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
    public async Task RunAsync_CanProceed_WhenInitialInstallHasNoChecksumAtAll_ButDeclaredBoardMatches()
    {
        // This is the actual, real-world case this app's own Initial Install package is
        // in today: no sibling .sha256 file, nothing recorded in the ROM profile, and no
        // official checksum exists anywhere to supply -- researched and confirmed, see
        // RomValidationService. The Install button must not stay blocked by this alone.
        var romZip = RomZipFixtures.CreateLegacyRomZip(_tempDir, "rom.zip");
        RomZipFixtures.WriteSiblingChecksum(romZip, await new ChecksumVerifier().ComputeSha256Async(romZip));
        var initialZip = RomZipFixtures.CreateInitialInstallPackage(_tempDir, "infinity_gms_initial_install.zip");
        // Deliberately no sibling checksum written for initialZip.

        var harness = new InstallHarness();
        var context = harness.BuildContext(InstallMode.Real, TestProfiles.InfinityXVerified(), romZip, initialZip);
        var validationService = new RomValidationService();
        var device = InstallHarness.AdbCancunf();
        context.RomValidation = await validationService.ValidateAsync(romZip, device, TestProfiles.Cancunf(), TestProfiles.InfinityXVerified());
        context.InitialInstallValidation = await validationService.ValidateAsync(
            initialZip, device, TestProfiles.Cancunf(), TestProfiles.InfinityXVerified(), RomPackageKind.InitialInstallPackage);
        context.KernelSuAssessment = new KernelSuCompatibilityAssessment
        {
            KernelLooksGki = true,
            ParsedKernelVersion = "5.15.104-android13-8-weighu+",
            BootImageHasKernelSuMarkers = true,
            RecommendedAction = KernelSuRecommendedAction.UseBundledKernelSuAsIs,
            Explanation = "Bundled KernelSU detected.",
        };
        _detection.Current = InstallHarness.AdbCancunf();

        var report = await Checker.RunAsync(context);

        var checksumItem = report.Items.First(i => i.Name.Contains("checksum", StringComparison.OrdinalIgnoreCase));
        Assert.Equal(PreFlightCheckStatus.Pass, checksumItem.Status);
        Assert.True(report.CanProceed);
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
        Assert.Equal(PreFlightCheckStatus.Blocked, item.Status);
    }

    [Fact]
    public async Task RunAsync_ReportsUnableToVerify_NotBlocked_WhenBootloaderIsUnknownAndUnconfirmed()
    {
        // The actual bug this guards: detection that could not complete must never be
        // reported (or logged) as if it had positively found a locked bootloader.
        var context = await BuildFullyVerifiedContextAsync();
        _detection.Current = InstallHarness.AdbCancunf() with { BootloaderLockState = BootloaderLockState.Unknown };

        var report = await Checker.RunAsync(context);

        Assert.False(report.CanProceed);
        var item = report.Items.First(i => i.Name.Contains("Bootloader"));
        Assert.Equal(PreFlightCheckStatus.UnableToVerify, item.Status);
        Assert.NotEqual(PreFlightCheckStatus.Blocked, item.Status);
    }

    [Fact]
    public async Task RunAsync_CanProceed_WhenBootloaderUnknown_ButUserConfirmedUnlockedManually()
    {
        // This is the "too conservative in the wrong place" bug: InstallStateMachine
        // already honored BootloaderUserConfirmedUnlocked, but this separate Pre-Flight
        // gate (the one that actually controls the Install button's CanExecute) never
        // consulted it at all, so a manually-confirmed-unlocked device stayed blocked
        // here regardless of what the state machine would have allowed.
        var context = await BuildFullyVerifiedContextAsync();
        _detection.Current = InstallHarness.AdbCancunf() with { BootloaderLockState = BootloaderLockState.Unknown };
        context.BootloaderUserConfirmedUnlocked = true;

        var report = await Checker.RunAsync(context);

        var item = report.Items.First(i => i.Name.Contains("Bootloader"));
        Assert.Equal(PreFlightCheckStatus.Pass, item.Status);
        Assert.True(report.CanProceed);
    }

    [Fact]
    public async Task RunAsync_StillBlocks_WhenBootloaderIsLocked_EvenIfUserConfirmedUnlockedManually()
    {
        // A user attestation must never override a positively-detected Locked state --
        // it only stands in for a check that couldn't complete, never for one that
        // actively found the opposite of what's being claimed.
        var context = await BuildFullyVerifiedContextAsync();
        _detection.Current = InstallHarness.AdbCancunf() with { BootloaderLockState = BootloaderLockState.Locked };
        context.BootloaderUserConfirmedUnlocked = true;

        var report = await Checker.RunAsync(context);

        var item = report.Items.First(i => i.Name.Contains("Bootloader"));
        Assert.Equal(PreFlightCheckStatus.Blocked, item.Status);
        Assert.False(report.CanProceed);
    }

    [Fact]
    public async Task RunAsync_NeverIncludesAPlayIntegrityCheck_AndKernelSuNeverBlocksTheRomInstall()
    {
        // TASK: Root/KernelSU/Play Integrity are post-install features, never prerequisites
        // for the ROM install itself. There is no Play Integrity check anywhere in this
        // checklist (nothing in this app reads or reports Play Integrity status at all),
        // and the one root-related item (KernelSU) is explicitly advisory-only.
        var context = await BuildFullyVerifiedContextAsync();
        _detection.Current = InstallHarness.AdbCancunf();

        var report = await Checker.RunAsync(context);

        Assert.DoesNotContain(report.Items, i => i.Name.Contains("Integrity", StringComparison.OrdinalIgnoreCase));
        var kernelSuItem = report.Items.Single(i => i.Name.Contains("KernelSU"));
        Assert.False(kernelSuItem.IsBlocking);
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
