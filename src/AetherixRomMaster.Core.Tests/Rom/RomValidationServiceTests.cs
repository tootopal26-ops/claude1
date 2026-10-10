using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.Rom;
using AetherixRomMaster.Core.Tests.TestDoubles;
using Xunit;

namespace AetherixRomMaster.Core.Tests.Rom;

public class RomValidationServiceTests : IDisposable
{
    private readonly string _tempDir = Directory.CreateTempSubdirectory("aetherix-romvalidation-tests-").FullName;
    private readonly RomValidationService _service = new();

    private static DeviceInfo CancunfDevice() => new() { ConnectionMode = ConnectionMode.Adb, Codename = "cancunf", Serial = "S1" };

    [Fact]
    public async Task ValidateAsync_RejectsInvalidZip_AsUnrecognizedFormat()
    {
        var zip = RomZipFixtures.CreateInvalidZip(_tempDir, "garbage.zip");

        var result = await _service.ValidateAsync(zip, CancunfDevice(), TestProfiles.Cancunf(), TestProfiles.InfinityXVerified());

        Assert.Contains(result.Issues, i => i.Code == "UNRECOGNIZED_PACKAGE_FORMAT");
        Assert.True(result.HasBlockingIssues);
    }

    [Fact]
    public async Task ValidateAsync_RejectsRomBuiltForADifferentDevice()
    {
        var zip = RomZipFixtures.CreateLegacyRomZip(_tempDir, "wrong-device-rom.zip", preDevice: "some_other_device");
        RomZipFixtures.WriteSiblingChecksum(zip, await new ChecksumVerifier().ComputeSha256Async(zip));

        var result = await _service.ValidateAsync(zip, CancunfDevice(), TestProfiles.Cancunf(), TestProfiles.InfinityXVerified());

        Assert.Contains(result.Issues, i => i.Code == "ROM_DEVICE_MISMATCH");
        Assert.True(result.HasBlockingIssues);
    }

    [Fact]
    public async Task ValidateAsync_RejectsInitialInstallPackage_DeclaredForAnotherBoard()
    {
        var zip = RomZipFixtures.CreateInitialInstallPackage(_tempDir, "initial-install.zip", board: "some_other_board");
        RomZipFixtures.WriteSiblingChecksum(zip, await new ChecksumVerifier().ComputeSha256Async(zip));

        var result = await _service.ValidateAsync(
            zip, CancunfDevice(), TestProfiles.Cancunf(), TestProfiles.InfinityXVerified(), RomPackageKind.InitialInstallPackage);

        Assert.Contains(result.Issues, i => i.Code == "INITIAL_INSTALL_BOARD_MISMATCH");
    }

    [Fact]
    public async Task ValidateAsync_RejectsChecksumMismatch()
    {
        var zip = RomZipFixtures.CreateLegacyRomZip(_tempDir, "rom.zip");
        RomZipFixtures.WriteSiblingChecksum(zip, new string('a', 64)); // deliberately wrong

        var result = await _service.ValidateAsync(zip, CancunfDevice(), TestProfiles.Cancunf(), TestProfiles.InfinityXVerified());

        Assert.Contains(result.Issues, i => i.Code == "CHECKSUM_MISMATCH");
        Assert.False(result.IsSafeToInstall);
    }

    [Fact]
    public async Task ValidateAsync_RejectsWhenNoChecksumAvailableAtAll()
    {
        var zip = RomZipFixtures.CreateLegacyRomZip(_tempDir, "rom-no-checksum.zip");

        var result = await _service.ValidateAsync(zip, CancunfDevice(), TestProfiles.Cancunf(), TestProfiles.InfinityXVerified());

        Assert.Contains(result.Issues, i => i.Code == "CHECKSUM_UNAVAILABLE");
    }

    [Fact]
    public async Task ValidateAsync_RejectsUnverifiedRomProfile_EvenWithGoodChecksumAndMatchingDevice()
    {
        var zip = RomZipFixtures.CreateLegacyRomZip(_tempDir, "rom.zip");
        RomZipFixtures.WriteSiblingChecksum(zip, await new ChecksumVerifier().ComputeSha256Async(zip));

        var result = await _service.ValidateAsync(zip, CancunfDevice(), TestProfiles.Cancunf(), TestProfiles.InfinityXUnverified());

        Assert.Contains(result.Issues, i => i.Code == "ROM_PROFILE_UNVERIFIED");
        Assert.True(result.HasBlockingIssues);
    }

    [Fact]
    public async Task ValidateAsync_RejectsConnectedDeviceNotInSupportedList()
    {
        var zip = RomZipFixtures.CreateLegacyRomZip(_tempDir, "rom.zip");
        RomZipFixtures.WriteSiblingChecksum(zip, await new ChecksumVerifier().ComputeSha256Async(zip));
        var wrongDevice = new DeviceInfo { ConnectionMode = ConnectionMode.Adb, Codename = "completely_different_phone" };

        var result = await _service.ValidateAsync(zip, wrongDevice, TestProfiles.Cancunf(), TestProfiles.InfinityXVerified());

        Assert.Contains(result.Issues, i => i.Code == "CONNECTED_DEVICE_NOT_SUPPORTED");
    }

    [Fact]
    public async Task ValidateAsync_Passes_ForFullyMatchingVerifiedRomAndDevice()
    {
        var zip = RomZipFixtures.CreateLegacyRomZip(_tempDir, "rom.zip");
        RomZipFixtures.WriteSiblingChecksum(zip, await new ChecksumVerifier().ComputeSha256Async(zip));

        var result = await _service.ValidateAsync(zip, CancunfDevice(), TestProfiles.Cancunf(), TestProfiles.InfinityXVerified());

        Assert.True(result.IsSafeToInstall);
        Assert.Empty(result.Issues);
    }

    public void Dispose()
    {
        try { Directory.Delete(_tempDir, recursive: true); } catch { /* best-effort cleanup */ }
    }
}
