using AetherixRomMaster.Core.Rom;
using AetherixRomMaster.Core.Tests.TestDoubles;
using Xunit;

namespace AetherixRomMaster.Core.Tests.Rom;

public class RomPackageInspectorTests : IDisposable
{
    private readonly string _tempDir = Directory.CreateTempSubdirectory("aetherix-rom-tests-").FullName;
    private readonly RomPackageInspector _inspector = new();

    [Fact]
    public async Task InspectAsync_ClassifiesInitialInstallPackage_ByAndroidInfoTxt()
    {
        var zip = RomZipFixtures.CreateInitialInstallPackage(_tempDir, "initial-install.zip");

        var package = await _inspector.InspectAsync(zip);

        Assert.Equal(RomPackageKind.InitialInstallPackage, package.Kind);
        Assert.NotNull(package.AndroidInfo);
        Assert.Contains("cancunf", package.AndroidInfo!.RequiredBoards);
    }

    [Fact]
    public async Task InspectAsync_ClassifiesLegacyFlashableZip_ByOtaMetadata()
    {
        var zip = RomZipFixtures.CreateLegacyRomZip(_tempDir, "rom.zip");

        var package = await _inspector.InspectAsync(zip);

        Assert.Equal(RomPackageKind.LegacyFlashableZip, package.Kind);
        Assert.NotNull(package.Metadata);
        Assert.Contains("cancunf", package.Metadata!.PreDevices);
    }

    [Fact]
    public async Task InspectAsync_ClassifiesUnknown_ForArbitraryZipWithNoAndroidManifest()
    {
        var zip = RomZipFixtures.CreateInvalidZip(_tempDir, "not-a-rom.zip");

        var package = await _inspector.InspectAsync(zip);

        Assert.Equal(RomPackageKind.Unknown, package.Kind);
    }

    [Fact]
    public async Task InspectAsync_Throws_WhenFileDoesNotExist()
    {
        await Assert.ThrowsAsync<FileNotFoundException>(() => _inspector.InspectAsync(Path.Combine(_tempDir, "missing.zip")));
    }

    public void Dispose()
    {
        try { Directory.Delete(_tempDir, recursive: true); } catch { /* best-effort cleanup */ }
    }
}
