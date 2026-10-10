using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.Installation;
using AetherixRomMaster.Core.KernelSu;
using AetherixRomMaster.Core.Recovery;
using AetherixRomMaster.Core.Rom;
using AetherixRomMaster.Core.Tests.TestDoubles;
using Xunit;

namespace AetherixRomMaster.Core.Tests.Installation;

public class PreFlightSafetyCheckerTests : IDisposable
{
    private readonly string _tempDir = Directory.CreateTempSubdirectory("aetherix-preflight-tests-").FullName;
    private readonly ScriptedDeviceDetectionService _detection = new();

    private PreFlightSafetyChecker Checker => new(_detection, new RecoveryPlanner(_detection));

    private async Task<InstallContext> BuildFullyVerifiedContextAsync()
    {
        var romZip = RomZipFixtures.CreateLegacyRomZip(_tempDir, "rom.zip");
        RomZipFixtures.WriteSiblingChecksum(romZip, await new ChecksumVerifier().ComputeSha256Async(romZip));
        var initialZip = RomZipFixtures.CreateInitialInstallPackage(_tempDir, "initial.zip");
        RomZipFixtures.WriteSiblingChecksum(initialZip, await new ChecksumVerifier().ComputeSha256Async(initialZip));

        var harness = new InstallHarness();
        var context = harness.BuildContext(InstallMode.Real, TestProfiles.InfinityXVerified(), romZip, initialZip);

        var validationService = new RomValidationService();
        var device = InstallHarness.AdbCancunf();
        context.RomValidation = await validationService.ValidateAsync(romZip, device, TestProfiles.Cancunf(), TestProfiles.InfinityXVerified());
        context.InitialInstallValidation = await validationService.ValidateAsync(initialZip, device, TestProfiles.Cancunf(), TestProfiles.InfinityXVerified(), RomPackageKind.InitialInstallPackage);
        context.KernelSuAssessment = new KernelSuCompatibilityAssessment
        {
            KernelLooksGki = true,
            ParsedKernelVersion = "5.15.104-android13-8-weighu+",
            BootImageHasKernelSuMarkers = true,
            RecommendedAction = KernelSuRecommendedAction.UseBundledKernelSuAsIs,
            Explanation = "Bundled KernelSU detected.",
        };

        return context;
    }

    [Fact]
    public async Task RunAsync_CanProceed_WhenEverythingIsVerified()
    {
        var context = await BuildFullyVerifiedContextAsync();
        _detection.Current = InstallHarness.AdbCancunf();

        var report = await Checker.RunAsync(context);

        Assert.True(report.CanProceed);
        Assert.True(report.AllPassed);
        Assert.Equal(7, report.Items.Count);
    }

    [Fact]
    public async Task RunAsync_Blocks_WhenDeviceIsWrongCodename()
    {
        var context = await BuildFullyVerifiedContextAsync();
        _detection.Current = new DeviceInfo { ConnectionMode = ConnectionMode.Adb, Codename = "some_other_phone" };

        var report = await Checker.RunAsync(context);

        Assert.False(report.CanProceed);
        var item = report.Items.First(i => i.Name.Contains("cancunf"));
        Assert.False(item.Passed);
        Assert.True(item.IsBlocking);
    }

    [Fact]
    public async Task RunAsync_Blocks_WhenBootloaderIsLocked()
    {
        var context = await BuildFullyVerifiedContextAsync();
        _detection.Current = InstallHarness.AdbCancunf() with { BootloaderLockState = BootloaderLockState.Locked };

        var report = await Checker.RunAsync(context);

        Assert.False(report.CanProceed);
        var item = report.Items.First(i => i.Name.Contains("Bootloader"));
        Assert.False(item.Passed);
    }

    [Fact]
    public async Task RunAsync_Blocks_WhenChecksumsNotYetVerified()
    {
        var harness = new InstallHarness();
        var romZip = RomZipFixtures.CreateLegacyRomZip(_tempDir, "unverified.zip");
        var context = harness.BuildContext(InstallMode.Real, TestProfiles.InfinityXVerified(), romZip, null);
        _detection.Current = InstallHarness.AdbCancunf();

        var report = await Checker.RunAsync(context);

        Assert.False(report.CanProceed);
    }

    [Fact]
    public async Task RunAsync_DoesNotBlock_WhenOnlyKernelSuItemIsUnconfirmed()
    {
        var context = await BuildFullyVerifiedContextAsync();
        context.KernelSuAssessment = new KernelSuCompatibilityAssessment
        {
            KernelLooksGki = true,
            ParsedKernelVersion = "5.15.104-android13-8-weighu+",
            RecommendedAction = KernelSuRecommendedAction.ManualVerifiedKernelRequired,
            Explanation = "No verified KernelSU image confirmed yet.",
        };
        _detection.Current = InstallHarness.AdbCancunf();

        var report = await Checker.RunAsync(context);

        var kernelSuItem = report.Items.First(i => i.Name.Contains("KernelSU"));
        Assert.False(kernelSuItem.Passed);
        Assert.False(kernelSuItem.IsBlocking);
        Assert.True(report.CanProceed);
        Assert.False(report.AllPassed);
    }

    [Fact]
    public async Task RunAsync_Blocks_WhenRecoveryPathNotAvailable()
    {
        var context = await BuildFullyVerifiedContextAsync();
        _detection.Current = DeviceInfo.Disconnected;

        var report = await Checker.RunAsync(context);

        Assert.False(report.CanProceed);
        var item = report.Items.First(i => i.Name.Contains("Recovery path"));
        Assert.False(item.Passed);
    }

    public void Dispose()
    {
        try { Directory.Delete(_tempDir, recursive: true); } catch { /* best-effort cleanup */ }
    }
}
