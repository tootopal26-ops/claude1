using System.Security.Cryptography;

namespace AetherixRomMaster.Core.Rom;

public sealed record ChecksumCheckResult(bool Matched, string ComputedSha256, string? ExpectedSha256, string Source);

/// <summary>
/// Verifies a downloaded/selected ROM file against a checksum published by its own
/// official source — never against a value invented by this application. The expected
/// hash must come from a sibling checksum file (the common ".sha256" / ".sha256sum"
/// convention most ROM download pages use) or be supplied explicitly by the operator.
/// </summary>
public sealed class ChecksumVerifier
{
    public async Task<string> ComputeSha256Async(string filePath, CancellationToken ct = default)
    {
        await using var stream = File.OpenRead(filePath);
        var hash = await SHA256.HashDataAsync(stream, ct);
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

    public async Task<ChecksumCheckResult> VerifyAsync(string filePath, string? explicitExpectedSha256, CancellationToken ct = default)
    {
        var computed = await ComputeSha256Async(filePath, ct);

        var expected = explicitExpectedSha256?.Trim().ToLowerInvariant();
        var source = "operator-supplied";
        if (expected is null)
        {
            expected = await TryReadSiblingChecksumAsync(filePath, ct);
            source = "sibling checksum file";
        }

        if (expected is null)
        {
            return new ChecksumCheckResult(false, computed, null, "none available");
        }

        return new ChecksumCheckResult(
            string.Equals(computed, expected, StringComparison.OrdinalIgnoreCase),
            computed,
            expected,
            source);
    }
}
