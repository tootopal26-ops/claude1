using AetherixRomMaster.Core.Installation;
using AetherixRomMaster.Core.Tests.TestDoubles;
using Xunit;

namespace AetherixRomMaster.Core.Tests.Installation;

public class InstallStateMachineTests : IDisposable
{
    private readonly string _tempDir = Directory.CreateTempSubdirectory("aetherix-sm-tests-").FullName;

    private (string RomZip, string InitialInstallZip) CreateValidPackages()
    {
        var romZip = RomZipFixtures.CreateLegacyRomZip(_tempDir, "rom.zip");
        RomZipFixtures.WriteSiblingChecksum(romZip, Task.Run(() => new AetherixRomMaster.Core.Rom.ChecksumVerifier().ComputeSha256Async(romZip)).Result);

        var initialZip = RomZipFixtures.CreateInitialInstallPackage(_tempDir, "initial-install.zip");
        RomZipFixtures.WriteSiblingChecksum(initialZip, Task.Run(() => new AetherixRomMaster.Core.Rom.ChecksumVerifier().ComputeSha256Async(initialZip)).Result);

        return (romZip, initialZip);
    }

    private static async Task<List<StepResult>> DrainAsync(IAsyncEnumerable<StepResult> steps)
    {
        var results = new List<StepResult>();
        await foreach (var step in steps) results.Add(step);
        return results;
    }

    [Fact]
    public async Task DetectDevice_Fails_WhenNoDeviceIsConnected()
    {
        var harness = new InstallHarness();
        harness.Detection.Current = AetherixRomMaster.Core.Devices.DeviceInfo.Disconnected;
        var (romZip, initialZip) = CreateValidPackages();
        var context = harness.BuildContext(InstallMode.Real, TestProfiles.InfinityXVerified(), romZip, initialZip);

        var results = await DrainAsync(harness.StateMachine.RunAsync(context));

        var last = results[^1];
        Assert.Equal(InstallState.DetectDevice, last.State);
        Assert.Equal(StepOutcome.Failed, last.Outcome);
        Assert.Equal("device-not-detected", last.RecoveryGuidanceKey);
    }

    [Fact]
    public async Task VerifyRom_Fails_WhenRequiredInitialInstallPackageIsMissing()
    {
        var harness = new InstallHarness();
        harness.Detection.Current = InstallHarness.AdbCancunf();
        var (romZip, _) = CreateValidPackages();
        var context = harness.BuildContext(InstallMode.Real, TestProfiles.InfinityXVerified(), romZip, initialInstallZipPath: null);

        var results = await DrainAsync(harness.StateMachine.RunAsync(context));

        var last = results[^1];
        Assert.Equal(InstallState.VerifyRom, last.State);
        Assert.Equal(StepOutcome.Failed, last.Outcome);
    }

    [Fact]
    public async Task EnterRequiredInstallationMode_Fails_WhenRebootCommandItselfFails()
    {
        var harness = new InstallHarness();
        harness.Detection.Current = InstallHarness.AdbCancunf();
        harness.FlashExecutor.FailingOperations.Add("RebootToFastbootd");
        var (romZip, initialZip) = CreateValidPackages();
        var context = harness.BuildContext(InstallMode.Real, TestProfiles.InfinityXVerified(), romZip, initialZip);
        context.BackupAcknowledged = true;
        context.DataWipeConfirmed = true;

        var results = await DrainAsync(harness.StateMachine.RunAsync(context));

        var last = results[^1];
        Assert.Equal(InstallState.EnterRequiredInstallationMode, last.State);
        Assert.Equal(StepOutcome.Failed, last.Outcome);
        Assert.Equal("fastboot-detection-failure", last.RecoveryGuidanceKey);
        // Never auto-continues past the failure into destructive steps.
        Assert.DoesNotContain("FlashInitialInstallPackage", harness.FlashExecutor.Calls);
    }

    [Fact]
    public async Task BackupAndDataWipe_RequireExplicitConfirmation_BeforeProceeding()
    {
        var harness = new InstallHarness();
        harness.Detection.Current = InstallHarness.AdbCancunf();
        var (romZip, initialZip) = CreateValidPackages();
        var context = harness.BuildContext(InstallMode.Real, TestProfiles.InfinityXVerified(), romZip, initialZip);

        var firstPass = await DrainAsync(harness.StateMachine.RunAsync(context));
        Assert.Equal(StepOutcome.NeedsConfirmation, firstPass[^1].Outcome);
        Assert.Equal("backup-ack", firstPass[^1].ConfirmationKey);

        context.BackupAcknowledged = true;
        var secondPass = await DrainAsync(harness.StateMachine.RunAsync(context));
        Assert.Equal(StepOutcome.NeedsConfirmation, secondPass[^1].Outcome);
        Assert.Equal("data-wipe", secondPass[^1].ConfirmationKey);

        context.DataWipeConfirmed = true;
        harness.FlashExecutor.FailingOperations.Add("RebootToFastbootd"); // stop here deliberately
        var thirdPass = await DrainAsync(harness.StateMachine.RunAsync(context));
        Assert.Equal(InstallState.EnterRequiredInstallationMode, thirdPass[^1].State);
    }

    [Fact]
    public async Task InstallRom_Fails_WhenSideloadFails_AfterSuccessfulModeTransitions()
    {
        var harness = new InstallHarness();
        harness.Detection.Current = InstallHarness.AdbCancunf();
        harness.FlashExecutor.FailingOperations.Add("SideloadRom");
        var (romZip, initialZip) = CreateValidPackages();
        var context = harness.BuildContext(InstallMode.Real, TestProfiles.InfinityXVerified(), romZip, initialZip);
        context.BackupAcknowledged = true;
        context.DataWipeConfirmed = true;

        var results = new List<StepResult>();
        await foreach (var step in harness.StateMachine.RunAsync(context))
        {
            results.Add(step);
            // Detection.Current must only change *after* DetectDevice has already consumed
            // the initial ADB state -- switching it earlier would corrupt device detection itself.
            if (step.State == InstallState.DetectDevice && step.Outcome == StepOutcome.Continue)
            {
                harness.Detection.Current = InstallHarness.FastbootUserspaceCancunf();
            }
            else if (step.State == InstallState.EnterRequiredInstallationMode && step.Outcome == StepOutcome.Continue)
            {
                harness.Detection.Current = InstallHarness.AdbSideloadCancunf();
            }
        }

        var last = results[^1];
        Assert.Equal(InstallState.InstallRom, last.State);
        Assert.Equal(StepOutcome.Failed, last.Outcome);
        Assert.Equal("sideload-failure", last.RecoveryGuidanceKey);
        Assert.Contains("RebootToRecovery", harness.FlashExecutor.Calls);
        Assert.Contains("SideloadRom", harness.FlashExecutor.Calls);
    }

    [Fact]
    public async Task FullDryRunWalk_ReachesComplete_WithNoFailedStep()
    {
        var harness = new InstallHarness();
        harness.Detection.Current = InstallHarness.AdbCancunf();
        var (romZip, initialZip) = CreateValidPackages();
        var context = harness.BuildContext(InstallMode.DryRun, TestProfiles.InfinityXVerified(), romZip, initialZip);
        context.BackupAcknowledged = true;
        context.DataWipeConfirmed = true;

        var results = await DrainAsync(harness.StateMachine.RunAsync(context));

        Assert.DoesNotContain(results, r => r.Outcome == StepOutcome.Failed);
        Assert.Equal(StepOutcome.Finished, results[^1].Outcome);
        Assert.Equal(InstallState.Complete, results[^1].State);
    }

    [Fact]
    public async Task FullRealWalk_NeverClaimsRootActive_WhenNoRootIsDetectedOnBootedDevice()
    {
        var harness = new InstallHarness();
        // RootProcessRunner's default handler (set in InstallHarness ctor) returns exit 0
        // with empty output for every call, including "su -c id" -- i.e. no "uid=0", so
        // RootDetector must report RootSolutionType.None, never a false positive.
        harness.Detection.Current = InstallHarness.AdbCancunf(fingerprint: "motorola/cancunf/cancunf:15/OLD/0001:user/release-keys");
        var (romZip, initialZip) = CreateValidPackages();
        var context = harness.BuildContext(InstallMode.Real, TestProfiles.InfinityXVerified(), romZip, initialZip);
        context.BackupAcknowledged = true;
        context.DataWipeConfirmed = true;

        var results = new List<StepResult>();
        await foreach (var step in harness.StateMachine.RunAsync(context))
        {
            results.Add(step);
            if (step.State == InstallState.DetectDevice && step.Outcome == StepOutcome.Continue)
            {
                harness.Detection.Current = InstallHarness.FastbootUserspaceCancunf();
            }
            else if (step.State == InstallState.EnterRequiredInstallationMode && step.Outcome == StepOutcome.Continue)
            {
                harness.Detection.Current = InstallHarness.AdbSideloadCancunf();
            }
            else if (step.State == InstallState.InstallRom && step.Outcome == StepOutcome.Continue)
            {
                harness.Detection.Current = InstallHarness.AdbCancunf(fingerprint: "motorola/cancunf/cancunf:16/NEW/0001:user/release-keys");
            }
        }

        var complete = results.Single(r => r.State == InstallState.Complete);
        Assert.Equal(StepOutcome.Finished, complete.Outcome);
        Assert.Contains("NOT ESTABLISHED", complete.Message);
        Assert.DoesNotContain("ACTIVE (verified)", complete.Message);
    }

    public void Dispose()
    {
        try { Directory.Delete(_tempDir, recursive: true); } catch { /* best-effort cleanup */ }
    }
}
