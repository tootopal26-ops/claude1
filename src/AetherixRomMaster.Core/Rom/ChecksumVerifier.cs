using System.Security.Cryptography;

namespace AetherixRomMaster.Core.Rom;

public sealed record ChecksumCheckResult(
    bool Matched,
    /// <summary>True whenever some official reference existed to check against at all --
    /// an operator-supplied value, a RomProfile-recorded official checksum (regardless of
    /// whether any entry in it matched), or a sibling checksum file. False only means
    /// nothing was available to compare against, which is a distinct, less severe-sounding
    /// but equally blocking condition from a confirmed mismatch -- see
    /// RomValidationService's CHECKSUM_UNAVAILABLE vs CHECKSUM_MISMATCH.</summary>
    bool HasOfficialReference,
    string ComputedSha256,
    string ComputedMd5,
    string? ExpectedSha256,
    string? ExpectedMd5,
    string? MatchedVariantLabel,
    string Source)
{
    /// <summary>True only when there was an official reference to compare against AND the
    /// local file's own computed hash didn't match it -- a confirmed, evidence-based
    /// problem (corruption/tampering/wrong variant). False when nothing was ever available
    /// to compare against (HasOfficialReference is false) -- that is a missing-evidence
    /// condition, not a confirmed one, and callers must not treat the two the same way.</summary>
    public bool IsGenuineMismatch => !Matched && HasOfficialReference;

    /// <summary>
    /// Describes computed-vs-expected for a mismatch, naming the one real algorithm that
    /// was actually compared -- never mixing algorithms and never defaulting to naming an
    /// algorithm that was never actually checked. This exists because an earlier version of
    /// this message-building logic defaulted to "SHA-256" whenever ExpectedMd5 was null,
    /// which produced a genuinely misleading "expected SHA-256" message for a file that was
    /// only ever checked against MD5 candidates that simply didn't match any of them (see
    /// the "none matched" branch below, where neither Expected field is set because no
    /// single candidate value applies). Callers must use this instead of inspecting
    /// ExpectedMd5/ExpectedSha256 themselves.
    /// </summary>
    public string DescribeComputedVsExpected() => this switch
    {
        { ExpectedMd5: { } md5 } => $"computed MD5 {ComputedMd5}, expected MD5 {md5}",
        { ExpectedSha256: { } sha256 } => $"computed SHA-256 {ComputedSha256}, expected SHA-256 {sha256}",
        _ => $"neither algorithm matched any known official checksum -- locally computed MD5 {ComputedMd5}, SHA-256 {ComputedSha256}",
    };
}

/// <summary>
/// Verifies a downloaded/selected ROM file against a checksum published by its own
/// official source — never against a value invented by this application. The expected
/// value comes from, in priority order: an operator-supplied SHA-256, a checksum recorded
/// on the RomProfile itself (see <see cref="RomChecksumEntry.Source"/> for provenance —
/// this is how a device owner's own transcription of an official page's published MD5/
/// SHA-256 flows into a real, evidence-based check), a sibling ".sha256"/".sha256sum"
/// file, or nothing. SHA-256 and MD5 are always both computed locally regardless of which
/// algorithm the official source happens to publish, but a hash is only ever reported as
/// "expected"/official for the algorithm an official source actually published — this app
/// never presents an independently-computed SHA-256 as if it were an official checksum
/// when only MD5 was ever published for that file.
/// </summary>
public sealed class ChecksumVerifier
{
    public async Task<string> ComputeSha256Async(string filePath, CancellationToken ct = default)
    {
        await using var stream = File.OpenRead(filePath);
        var hash = await SHA256.HashDataAsync(stream, ct);
        return Convert.ToHexString(hash).ToLowerInvariant();
    }

    public async Task<string> ComputeMd5Async(string filePath, CancellationToken ct = default)
    {
        await using var stream = File.OpenRead(filePath);
        var hash = await MD5.HashDataAsync(stream, ct);
        return Convert.ToHexString(hash).ToLowerInvariant();
    }

    public async Task<string?> TryReadSiblingChecksumAsync(string filePath, CancellationToken ct = default)
    {
        foreach (var suffix in new[] { ".sha256", ".sha256sum" })
        {
            var candidate = filePath + suffix;
            if (!File.Exists(candidate)) continue;

            var content = await File.ReadAllTextAsync(candidate, ct);
            var token = content.Split((char[]?)null, StringSplitOptions.RemoveEmptyEntries).FirstOrDefault();
            if (token is { Length: 64 })
            {
                return token.ToLowerInvariant();
            }
        }
        return null;
    }

    public async Task<ChecksumCheckResult> VerifyAsync(
        string filePath,
        string? explicitExpectedSha256,
        IReadOnlyList<RomChecksumEntry>? knownOfficialChecksums = null,
        CancellationToken ct = default)
    {
        var computedSha256 = await ComputeSha256Async(filePath, ct);
        var computedMd5 = await ComputeMd5Async(filePath, ct);

        var explicitExpected = explicitExpectedSha256?.Trim().ToLowerInvariant();
        if (explicitExpected is not null)
        {
            return new ChecksumCheckResult(
                string.Equals(computedSha256, explicitExpected, StringComparison.OrdinalIgnoreCase),
                HasOfficialReference: true, computedSha256, computedMd5, explicitExpected, null, null, "operator-supplied");
        }

        if (knownOfficialChecksums is { Count: > 0 })
        {
            foreach (var candidate in knownOfficialChecksums)
            {
                var isMd5 = candidate.Algorithm.Equals("MD5", StringComparison.OrdinalIgnoreCase);
                var computedForAlgorithm = isMd5 ? computedMd5 : computedSha256;
                if (!string.Equals(computedForAlgorithm, candidate.Value.Trim(), StringComparison.OrdinalIgnoreCase)) continue;

                return new ChecksumCheckResult(
                    Matched: true, HasOfficialReference: true, computedSha256, computedMd5,
                    ExpectedSha256: isMd5 ? null : candidate.Value,
                    ExpectedMd5: isMd5 ? candidate.Value : null,
                    MatchedVariantLabel: candidate.VariantLabel,
                    Source: $"official ({candidate.VariantLabel}, {candidate.Algorithm}) -- {candidate.Source}");
            }

            var checkedLabels = string.Join(", ", knownOfficialChecksums.Select(c => $"{c.VariantLabel} ({c.Algorithm})"));
            return new ChecksumCheckResult(
                Matched: false, HasOfficialReference: true, computedSha256, computedMd5, null, null, null,
                $"checked against known official checksum(s) [{checkedLabels}] -- this file matched none of them");
        }

        var siblingSha256 = await TryReadSiblingChecksumAsync(filePath, ct);
        if (siblingSha256 is not null)
        {
            return new ChecksumCheckResult(
                string.Equals(computedSha256, siblingSha256, StringComparison.OrdinalIgnoreCase),
                HasOfficialReference: true, computedSha256, computedMd5, siblingSha256, null, null, "sibling checksum file");
        }

        return new ChecksumCheckResult(false, HasOfficialReference: false, computedSha256, computedMd5, null, null, null, "none available");
    }
}
