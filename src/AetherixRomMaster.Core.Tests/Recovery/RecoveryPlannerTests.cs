using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.Installation;
using AetherixRomMaster.Core.Recovery;
using AetherixRomMaster.Core.Rom;
using AetherixRomMaster.Core.Tests.TestDoubles;
using Xunit;

namespace AetherixRomMaster.Core.Tests.Recovery;

public class RecoveryPlannerTests : IDisposable
{
    private readonly string _tempDir = Directory.CreateTempSubdirectory("aetherix-recovery-tests-").FullName;
    private readonly ScriptedDeviceDetectionService _detection = new();

    private RecoveryPlanner Planner => new(_detection);

    private async Task<InstallContext> BuildVerifiedContextAsync()
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

        return context;
    }

    private static void AddFailure(InstallContext context, InstallState state, string? guidanceKey)
    {
        context.History.Add(StepResult.Continue(InstallState.Initialize, "ok"));
        context.History.Add(StepResult.Failed(state, $"{state} failed.", guidanceKey));
    }

    [Fact]
    public async Task BuildSessionAsync_Throws_WhenNoFailureRecorded()
    {
        var context = await BuildVerifiedContextAsync();

        await Assert.ThrowsAsync<InvalidOperationException>(() => Planner.BuildSessionAsync(context));
    }

    [Fact]
    public async Task BuildSessionAsync_OffersExecutableRetry_ForConnectivityFailure_WhenDeviceReachableAndFilesVerified()
    {
        var context = await BuildVerifiedContextAsync();
        AddFailure(context, InstallState.EnterRequiredInstallationMode, "fastboot-detection-failure");
        _detection.Current = InstallHarness.FastbootUserspaceCancunf();

        var session = await Planner.BuildSessionAsync(context);

        Assert.Equal(RecoveryPathAvailability.Available, session.PathAvailability);
        var option = Assert.Single(session.Options);
        Assert.Equal(RecoveryActionKind.RetryWithVerifiedFiles, option.Kind);
        Assert.True(option.CanBeExecutedByThisApp);
        Assert.Equal(InstallState.EnterRequiredInstallationMode, option.RetryState);
    }

    [Fact]
    public async Task BuildSessionAsync_OffersGuidanceOnly_ForConnectivityFailure_WhenDeviceNotReachable()
    {
        var context = await BuildVerifiedContextAsync();
        AddFailure(context, InstallState.EnterRequiredInstallationMode, "fastboot-detection-failure");
        _detection.Current = DeviceInfo.Disconnected;

        var session = await Planner.BuildSessionAsync(context);

        Assert.Equal(RecoveryPathAvailability.Available, session.PathAvailability);
        var option = Assert.Single(session.Options);
        Assert.Equal(RecoveryActionKind.NonDestructiveGuidanceOnly, option.Kind);
        Assert.False(option.CanBeExecutedByThisApp);
    }

    [Fact]
    public async Task BuildSessionAsync_NeverPromisesRecovery_ForBootloop_WhenDeviceUnreachable()
    {
        var context = await BuildVerifiedContextAsync();
        AddFailure(context, InstallState.VerifyFinalOs, "bootloop");
        _detection.Current = DeviceInfo.Disconnected;

        var session = await Planner.BuildSessionAsync(context);

        Assert.Equal(RecoveryPathAvailability.NotAvailable, session.PathAvailability);
        Assert.Empty(session.Options);
        Assert.Contains("manual", session.AvailabilityExplanation, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("automatically", session.AvailabilityExplanation, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public async Task BuildSessionAsync_OffersVerifiedRetry_ForBootloop_WhenDeviceStillRespondsInFastboot()
    {
        var context = await BuildVerifiedContextAsync();
        AddFailure(context, InstallState.VerifyFinalOs, "bootloop");
        _detection.Current = InstallHarness.FastbootUserspaceCancunf();

        var session = await Planner.BuildSessionAsync(context);

        Assert.Equal(RecoveryPathAvailability.Available, session.PathAvailability);
        var option = Assert.Single(session.Options);
        Assert.True(option.CanBeExecutedByThisApp);
    }

    [Fact]
    public async Task BuildSessionAsync_NeverOffersRetryWithSameFile_ForChecksumMismatch()
    {
        var context = await BuildVerifiedContextAsync();
        AddFailure(context, InstallState.VerifyChecksums, "checksum-mismatch");
        _detection.Current = InstallHarness.AdbCancunf();

        var session = await Planner.BuildSessionAsync(context);

        Assert.All(session.Options, o => Assert.NotEqual(RecoveryActionKind.RetryWithVerifiedFiles, o.Kind));
    }

    [Fact]
    public async Task BuildSessionAsync_ReportsNotAvailable_ForUnrecognizedFailureCategory()
    {
        var context = await BuildVerifiedContextAsync();
        AddFailure(context, InstallState.InstallRom, guidanceKey: null);
        _detection.Current = InstallHarness.AdbCancunf();

        var session = await Planner.BuildSessionAsync(context);

        Assert.Equal(RecoveryPathAvailability.NotAvailable, session.PathAvailability);
        Assert.Empty(session.Options);
    }

    [Fact]
    public async Task BuildSessionAsync_NeverOffersExecutableOption_WhenPathIsNotAvailable()
    {
        var context = await BuildVerifiedContextAsync();
        AddFailure(context, InstallState.VerifyFinalOs, "bootloop");
        _detection.Current = DeviceInfo.Disconnected;

        var session = await Planner.BuildSessionAsync(context);

        Assert.All(session.Options, o => Assert.False(o.CanBeExecutedByThisApp));
    }

    [Fact]
    public async Task BuildSessionAsync_RecordsLastSuccessfulState_FromHistory()
    {
        var context = await BuildVerifiedContextAsync();
        context.History.Add(StepResult.Continue(InstallState.Initialize, "ok"));
        context.History.Add(StepResult.Continue(InstallState.DetectDevice, "ok"));
        context.History.Add(StepResult.Failed(InstallState.VerifyBootloader, "nope", "device-not-detected"));
        _detection.Current = InstallHarness.AdbCancunf();

        var session = await Planner.BuildSessionAsync(context);

        Assert.Equal(InstallState.DetectDevice, session.LastSuccessfulState);
        Assert.Equal(InstallState.VerifyBootloader, session.FailedState);
    }

    [Fact]
    public async Task AssessRetryReadinessAsync_True_WhenDeviceReachableAndFilesVerified()
    {
        var context = await BuildVerifiedContextAsync();
        _detection.Current = InstallHarness.AdbCancunf();

        var (ready, _) = await Planner.AssessRetryReadinessAsync(context);

        Assert.True(ready);
    }

    [Fact]
    public async Task AssessRetryReadinessAsync_False_WhenDeviceUnreachable()
    {
        var context = await BuildVerifiedContextAsync();
        _detection.Current = DeviceInfo.Disconnected;

        var (ready, explanation) = await Planner.AssessRetryReadinessAsync(context);

        Assert.False(ready);
        Assert.Contains("no device", explanation, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public async Task AssessRetryReadinessAsync_False_WhenFilesNotYetVerified()
    {
        var harness = new InstallHarness();
        var romZip = RomZipFixtures.CreateLegacyRomZip(_tempDir, "unverified-rom.zip");
        var context = harness.BuildContext(InstallMode.Real, TestProfiles.InfinityXVerified(), romZip, null);
        // RomValidation deliberately left null -- as if no dry run has been performed yet.
        _detection.Current = InstallHarness.AdbCancunf();

        var (ready, _) = await Planner.AssessRetryReadinessAsync(context);

        Assert.False(ready);
    }

    [Fact]
    public async Task AssessRetryReadinessAsync_True_WhenInitialInstallHasNoChecksumAtAll_ButBoardMatches()
    {
        // The researched Initial-Install exception (see RomValidationService) must also
        // apply here: this is the same "safe to retry with" judgment the Pre-Flash check
        // and the real Recovery Mode system both rely on, so a missing (never published)
        // checksum for this one package type must not make it look unverified/unsafe.
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
        _detection.Current = InstallHarness.AdbCancunf();

        var (ready, _) = await Planner.AssessRetryReadinessAsync(context);

        Assert.True(ready);
    }

    public void Dispose()
    {
        try { Directory.Delete(_tempDir, recursive: true); } catch { /* best-effort cleanup */ }
    }
}
