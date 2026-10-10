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

    public void Dispose()
    {
        try { Directory.Delete(_tempDir, recursive: true); } catch { /* best-effort cleanup */ }
    }
}
