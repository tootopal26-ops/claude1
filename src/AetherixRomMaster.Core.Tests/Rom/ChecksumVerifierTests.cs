using System.Security.Cryptography;
using System.Text;
using AetherixRomMaster.Core.Rom;
using AetherixRomMaster.Core.Tests.TestDoubles;
using Xunit;

namespace AetherixRomMaster.Core.Tests.Rom;

public class ChecksumVerifierTests : IDisposable
{
    private readonly string _tempDir = Directory.CreateTempSubdirectory("aetherix-checksum-tests-").FullName;
    private readonly ChecksumVerifier _verifier = new();

    [Fact]
    public async Task VerifyAsync_Matches_WhenSiblingChecksumFileIsCorrect()
    {
        var filePath = Path.Combine(_tempDir, "rom.zip");
        var content = "pretend rom bytes"u8.ToArray();
        await File.WriteAllBytesAsync(filePath, content);
        var correctHash = Convert.ToHexString(SHA256.HashData(content)).ToLowerInvariant();
        RomZipFixtures.WriteSiblingChecksum(filePath, correctHash);

        var result = await _verifier.VerifyAsync(filePath, explicitExpectedSha256: null);

        Assert.True(result.Matched);
        Assert.Equal(correctHash, result.ComputedSha256);
    }

    [Fact]
    public async Task VerifyAsync_Fails_WhenSiblingChecksumFileIsWrong()
    {
        var filePath = Path.Combine(_tempDir, "rom.zip");
        await File.WriteAllBytesAsync(filePath, "pretend rom bytes"u8.ToArray());
        RomZipFixtures.WriteSiblingChecksum(filePath, new string('0', 64));

        var result = await _verifier.VerifyAsync(filePath, explicitExpectedSha256: null);

        Assert.False(result.Matched);
    }

    [Fact]
    public async Task VerifyAsync_ReportsUnavailable_WhenNoChecksumIsSuppliedOrFound()
    {
        var filePath = Path.Combine(_tempDir, "rom.zip");
        await File.WriteAllBytesAsync(filePath, "pretend rom bytes"u8.ToArray());

        var result = await _verifier.VerifyAsync(filePath, explicitExpectedSha256: null);

        Assert.False(result.Matched);
        Assert.Null(result.ExpectedSha256);
    }

    [Fact]
    public async Task VerifyAsync_PrefersExplicitChecksum_OverSiblingFile()
    {
        var filePath = Path.Combine(_tempDir, "rom.zip");
        var content = "pretend rom bytes"u8.ToArray();
        await File.WriteAllBytesAsync(filePath, content);
        var correctHash = Convert.ToHexString(SHA256.HashData(content)).ToLowerInvariant();
        RomZipFixtures.WriteSiblingChecksum(filePath, new string('f', 64)); // wrong, should be ignored

        var result = await _verifier.VerifyAsync(filePath, explicitExpectedSha256: correctHash);

        Assert.True(result.Matched);
    }

    [Fact]
    public async Task ComputeMd5Async_ComputesTheRealMd5()
    {
        var filePath = Path.Combine(_tempDir, "rom.zip");
        var content = "pretend rom bytes"u8.ToArray();
        await File.WriteAllBytesAsync(filePath, content);
        var expected = Convert.ToHexString(MD5.HashData(content)).ToLowerInvariant();

        var computed = await _verifier.ComputeMd5Async(filePath);

        Assert.Equal(expected, computed);
    }

    // Explicit algorithm-correctness regression tests: hash algorithms must never be
    // mixed. A real bug this guards against (found from a real device report): the local
    // file's computed hash was correctly compared MD5-to-MD5 under the hood, but the
    // MISMATCH MESSAGE defaulted to describing it as a SHA-256 comparison whenever no
    // single candidate matched -- see ChecksumCheckResult.DescribeComputedVsExpected.

    [Fact]
    public async Task VerifyAsync_Md5LocalVsMd5Official_CanMatch()
    {
        var filePath = Path.Combine(_tempDir, "rom.zip");
        var content = "pretend rom bytes"u8.ToArray();
        await File.WriteAllBytesAsync(filePath, content);
        var officialMd5 = Convert.ToHexString(MD5.HashData(content)).ToLowerInvariant();
        var known = new[] { new RomChecksumEntry("GApps", "MD5", officialMd5, "official page") };

        var result = await _verifier.VerifyAsync(filePath, explicitExpectedSha256: null, knownOfficialChecksums: known);

        Assert.True(result.Matched);
        Assert.Equal(officialMd5, result.ExpectedMd5);
        Assert.Null(result.ExpectedSha256);
    }

    [Fact]
    public async Task VerifyAsync_Sha256LocalVsSha256Official_CanMatch()
    {
        var filePath = Path.Combine(_tempDir, "rom.zip");
        var content = "pretend rom bytes"u8.ToArray();
        await File.WriteAllBytesAsync(filePath, content);
        var officialSha256 = Convert.ToHexString(SHA256.HashData(content)).ToLowerInvariant();
        var known = new[] { new RomChecksumEntry("Vanilla", "SHA256", officialSha256, "official page") };

        var result = await _verifier.VerifyAsync(filePath, explicitExpectedSha256: null, knownOfficialChecksums: known);

        Assert.True(result.Matched);
        Assert.Equal(officialSha256, result.ExpectedSha256);
        Assert.Null(result.ExpectedMd5);
    }

    [Fact]
    public async Task VerifyAsync_Md5LocalVsSha256Official_IsNeverConsideredAMatch()
    {
        var filePath = Path.Combine(_tempDir, "rom.zip");
        var content = "pretend rom bytes"u8.ToArray();
        await File.WriteAllBytesAsync(filePath, content);
        // Deliberately the file's own computed MD5, offered as a fake "SHA256" official
        // value -- this must never be treated as a match just because the digit strings
        // happen to share a prefix/format; the algorithms are simply different questions.
        var computedMd5 = Convert.ToHexString(MD5.HashData(content)).ToLowerInvariant();
        var known = new[] { new RomChecksumEntry("GApps", "SHA256", computedMd5, "official page") };

        var result = await _verifier.VerifyAsync(filePath, explicitExpectedSha256: null, knownOfficialChecksums: known);

        Assert.False(result.Matched);
    }

    [Fact]
    public async Task VerifyAsync_Sha256LocalVsMd5Official_IsNeverConsideredAMatch()
    {
        var filePath = Path.Combine(_tempDir, "rom.zip");
        var content = "pretend rom bytes"u8.ToArray();
        await File.WriteAllBytesAsync(filePath, content);
        var computedSha256 = Convert.ToHexString(SHA256.HashData(content)).ToLowerInvariant();
        var known = new[] { new RomChecksumEntry("GApps", "MD5", computedSha256, "official page") };

        var result = await _verifier.VerifyAsync(filePath, explicitExpectedSha256: null, knownOfficialChecksums: known);

        Assert.False(result.Matched);
    }

    [Fact]
    public async Task VerifyAsync_MismatchMessage_NeverMislabelsTheAlgorithmActuallyChecked()
    {
        // The exact bug reported: "Computed SHA-256 ... does not match the expected
        // SHA-256 from checked official checksums [GApps (MD5), Vanilla (MD5)]" -- naming
        // SHA-256 when only MD5 candidates were ever checked. DescribeComputedVsExpected
        // must never name an algorithm that wasn't actually the one compared.
        var filePath = Path.Combine(_tempDir, "rom.zip");
        await File.WriteAllBytesAsync(filePath, "pretend rom bytes"u8.ToArray());
        var known = new[]
        {
            new RomChecksumEntry("GApps", "MD5", new string('a', 32), "official page"),
            new RomChecksumEntry("Vanilla", "MD5", new string('b', 32), "official page"),
        };

        var result = await _verifier.VerifyAsync(filePath, explicitExpectedSha256: null, knownOfficialChecksums: known);

        Assert.False(result.Matched);
        var description = result.DescribeComputedVsExpected();
        Assert.DoesNotContain("expected SHA-256", description, StringComparison.OrdinalIgnoreCase);
        Assert.Contains("MD5", description);
    }

    [Fact]
    public async Task VerifyAsync_MatchesAgainstKnownOfficialMd5_AndReportsTheVariant()
    {
        var filePath = Path.Combine(_tempDir, "rom.zip");
        var content = "pretend rom bytes"u8.ToArray();
        await File.WriteAllBytesAsync(filePath, content);
        var officialMd5 = Convert.ToHexString(MD5.HashData(content)).ToLowerInvariant();
        var known = new[] { new RomChecksumEntry("GApps", "MD5", officialMd5, "official page") };

        var result = await _verifier.VerifyAsync(filePath, explicitExpectedSha256: null, knownOfficialChecksums: known);

        Assert.True(result.Matched);
        Assert.True(result.HasOfficialReference);
        Assert.Equal("GApps", result.MatchedVariantLabel);
        Assert.Equal(officialMd5, result.ExpectedMd5);
        Assert.Null(result.ExpectedSha256);
    }

    [Fact]
    public async Task VerifyAsync_NeverPresentsAnIndependentlyComputedSha256AsAnOfficialValue_WhenOnlyMd5WasPublished()
    {
        var filePath = Path.Combine(_tempDir, "rom.zip");
        await File.WriteAllBytesAsync(filePath, "pretend rom bytes"u8.ToArray());
        var known = new[] { new RomChecksumEntry("GApps", "MD5", new string('a', 32), "official page") };

        var result = await _verifier.VerifyAsync(filePath, explicitExpectedSha256: null, knownOfficialChecksums: known);

        // The file doesn't match the known MD5, so this must read as a confirmed mismatch,
        // not as "no official checksum was ever published" -- and never claim a SHA-256
        // "expected" value that nobody actually published.
        Assert.False(result.Matched);
        Assert.True(result.HasOfficialReference);
        Assert.Null(result.ExpectedSha256);
        Assert.Null(result.ExpectedMd5);
    }

    [Fact]
    public async Task VerifyAsync_ReportsNoOfficialReference_WhenNoExplicitNoKnownChecksumsAndNoSiblingFile()
    {
        var filePath = Path.Combine(_tempDir, "rom.zip");
        await File.WriteAllBytesAsync(filePath, "pretend rom bytes"u8.ToArray());

        var result = await _verifier.VerifyAsync(filePath, explicitExpectedSha256: null);

        Assert.False(result.HasOfficialReference);
    }

    public void Dispose()
    {
        try { Directory.Delete(_tempDir, recursive: true); } catch { /* best-effort cleanup */ }
    }
}
