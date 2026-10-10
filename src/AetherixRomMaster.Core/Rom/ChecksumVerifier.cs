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
    string Source);

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
