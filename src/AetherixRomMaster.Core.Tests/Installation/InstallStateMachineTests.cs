using AetherixRomMaster.Core.Devices;
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
    public async Task VerifyBootloader_Fails_WhenDetectedUnknown_AndNotUserConfirmed()
    {
        var harness = new InstallHarness();
        harness.Detection.Current = InstallHarness.AdbCancunf() with { BootloaderLockState = BootloaderLockState.UnableToVerify };
        var (romZip, initialZip) = CreateValidPackages();
        var context = harness.BuildContext(InstallMode.Real, TestProfiles.InfinityXVerified(), romZip, initialZip);

        var results = await DrainAsync(harness.StateMachine.RunAsync(context));

        var last = results[^1];
        Assert.Equal(InstallState.VerifyBootloader, last.State);
        Assert.Equal(StepOutcome.Failed, last.Outcome);
    }

    [Fact]
    public async Task VerifyBootloader_Proceeds_WhenUserConfirmedUnlocked_DespiteUnknownDetectedState()
    {
        var harness = new InstallHarness();
        harness.Detection.Current = InstallHarness.AdbCancunf() with { BootloaderLockState = BootloaderLockState.UnableToVerify };
        var (romZip, initialZip) = CreateValidPackages();
        var context = harness.BuildContext(InstallMode.Real, TestProfiles.InfinityXVerified(), romZip, initialZip);
        context.BootloaderUserConfirmedUnlocked = true;
        context.BackupAcknowledged = true;
        context.DataWipeConfirmed = true;

        // Only VerifyBootloader's own outcome is under test -- draining further would run
        // into EnterRequiredInstallationMode's real "wait for fastbootd" timeout, since this
        // test isn't modeling a reboot into fastbootd.
        StepResult? verifyBootloaderResult = null;
        await foreach (var step in harness.StateMachine.RunAsync(context))
        {
            if (step.State == InstallState.VerifyBootloader)
            {
                verifyBootloaderResult = step;
                break;
            }
        }

        Assert.NotNull(verifyBootloaderResult);
        Assert.Equal(StepOutcome.Continue, verifyBootloaderResult!.Outcome);
    }

    [Fact]
    public async Task VerifyBootloader_StillFails_WhenDetectedLocked_EvenIfUserConfirmedUnlocked()
    {
        // A user attestation must never override a POSITIVELY detected Locked state --
        // it only stands in for a check that couldn't complete, never for one that
        // actively found the opposite of what's being claimed.
        var harness = new InstallHarness();
        harness.Detection.Current = InstallHarness.AdbCancunf() with { BootloaderLockState = BootloaderLockState.VerifiedLocked };
        var (romZip, initialZip) = CreateValidPackages();
        var context = harness.BuildContext(InstallMode.Real, TestProfiles.InfinityXVerified(), romZip, initialZip);
        context.BootloaderUserConfirmedUnlocked = true;

        var results = await DrainAsync(harness.StateMachine.RunAsync(context));

        var last = results[^1];
        Assert.Equal(InstallState.VerifyBootloader, last.State);
        Assert.Equal(StepOutcome.Failed, last.Outcome);
        Assert.Contains("Locked", last.Message);
    }

    [Fact]
    public async Task VerifyBootloader_LogsExplicitWarning_WhenProceedingOnUserConfirmationAlone()
    {
        var harness = new InstallHarness();
        harness.Detection.Current = InstallHarness.AdbCancunf() with { BootloaderLockState = BootloaderLockState.UnableToVerify };
        var (romZip, initialZip) = CreateValidPackages();
        var context = harness.BuildContext(InstallMode.Real, TestProfiles.InfinityXVerified(), romZip, initialZip);
        context.BootloaderUserConfirmedUnlocked = true;
        context.BackupAcknowledged = true;
        context.DataWipeConfirmed = true;

        await foreach (var step in harness.StateMachine.RunAsync(context))
        {
            if (step.State == InstallState.VerifyBootloader) break;
        }

        Assert.Contains(harness.Logger.Entries, e => e.Message.Contains("USER-CONFIRMED") && e.Message.Contains("not an app-verified fact"));
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
    public async Task DataWipeConfirmation_IsTheSoleFinalConfirmation_BeforeProceeding()
    {
        // Product requirement: exactly ONE final confirmation before anything destructive
        // -- CreateBackupGuidance must never stop for its own separate round-trip anymore
        // (it auto-logs and continues straight through to the data-wipe confirmation).
        var harness = new InstallHarness();
        harness.Detection.Current = InstallHarness.AdbCancunf();
        var (romZip, initialZip) = CreateValidPackages();
        var context = harness.BuildContext(InstallMode.Real, TestProfiles.InfinityXVerified(), romZip, initialZip);

        var firstPass = await DrainAsync(harness.StateMachine.RunAsync(context));
        Assert.Equal(StepOutcome.NeedsConfirmation, firstPass[^1].Outcome);
        Assert.Equal("data-wipe", firstPass[^1].ConfirmationKey);
        Assert.DoesNotContain(firstPass, r => r.ConfirmationKey == "backup-ack");
        Assert.True(context.BackupAcknowledged, "CreateBackupGuidance must auto-acknowledge, not wait for a separate confirmation.");

        context.DataWipeConfirmed = true;
        harness.FlashExecutor.FailingOperations.Add("RebootToFastbootd"); // stop here deliberately
        var secondPass = await DrainAsync(harness.StateMachine.RunAsync(context));
        Assert.Equal(InstallState.EnterRequiredInstallationMode, secondPass[^1].State);
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
    public async Task InstallRom_RequestsManualNavigation_WhenRecoveryReachesPlainAdbButNotSideload()
    {
        var harness = new InstallHarness();
        harness.Detection.Current = InstallHarness.AdbCancunf();
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
                // Recovery boots but stays in plain ADB -- this recovery needs the menu navigated by hand.
                harness.Detection.Current = InstallHarness.AdbRecoveryManualCancunf();
            }
        }

        var last = results[^1];
        Assert.Equal(InstallState.InstallRom, last.State);
        Assert.Equal(StepOutcome.NeedsConfirmation, last.Outcome);
        Assert.Equal("manual-recovery-navigation", last.ConfirmationKey);
        Assert.Contains("manual interaction", last.Message, StringComparison.OrdinalIgnoreCase);
        Assert.Contains("RebootToRecovery", harness.FlashExecutor.Calls);
        Assert.DoesNotContain("SideloadRom", harness.FlashExecutor.Calls);
    }

    [Fact]
    public async Task InstallRom_Succeeds_AfterManualNavigationConfirmation_WithoutReissuingReboot()
    {
        var harness = new InstallHarness();
        harness.Detection.Current = InstallHarness.AdbCancunf();
        var (romZip, initialZip) = CreateValidPackages();
        var context = harness.BuildContext(InstallMode.Real, TestProfiles.InfinityXVerified(), romZip, initialZip);
        context.BackupAcknowledged = true;
        context.DataWipeConfirmed = true;

        await foreach (var step in harness.StateMachine.RunAsync(context))
        {
            if (step.State == InstallState.DetectDevice && step.Outcome == StepOutcome.Continue)
            {
                harness.Detection.Current = InstallHarness.FastbootUserspaceCancunf();
            }
            else if (step.State == InstallState.EnterRequiredInstallationMode && step.Outcome == StepOutcome.Continue)
            {
                harness.Detection.Current = InstallHarness.AdbRecoveryManualCancunf();
            }
        }

        // The user has now (per the test's narrative) manually navigated the recovery menu.
        // Only the InstallRom retry itself is under test here -- draining the rest of the
        // pipeline would run into VerifyFinalOs's real 5-minute "wait for ADB" timeout,
        // since this test isn't modeling a reboot back to a normally-booted system.
        harness.Detection.Current = InstallHarness.AdbSideloadCancunf();
        StepResult? last = null;
        await foreach (var step in harness.StateMachine.RunAsync(context))
        {
            last = step;
            break;
        }

        Assert.NotNull(last);
        Assert.Equal(InstallState.InstallRom, last!.State);
        Assert.Equal(StepOutcome.Continue, last.Outcome);
        Assert.Contains("SideloadRom", harness.FlashExecutor.Calls);
        // RebootToRecovery must have been issued exactly once across both attempts, never repeated.
        Assert.Equal(1, harness.FlashExecutor.Calls.Count(c => c == "RebootToRecovery"));
    }

    [Fact]
    public async Task RequestDataWipeConfirmation_MessageIncludesStructuredSafetyFields()
    {
        var harness = new InstallHarness();
        harness.Detection.Current = InstallHarness.AdbCancunf();
        var (romZip, initialZip) = CreateValidPackages();
        var context = harness.BuildContext(InstallMode.Real, TestProfiles.InfinityXVerified(), romZip, initialZip);

        var results = await DrainAsync(harness.StateMachine.RunAsync(context));

        var last = results[^1];
        Assert.Equal(StepOutcome.NeedsConfirmation, last.Outcome);
        Assert.Equal("data-wipe", last.ConfirmationKey);
        foreach (var required in new[]
        {
            "Device:", "Codename:", "ROM:", "Android:", "Reason for wipe:", "Expected result:",
            "erases ALL user data", "Modify device partitions", "Keep the USB cable connected",
        })
        {
            Assert.Contains(required, last.Message);
        }
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

    [Fact]
    public async Task EnterRequiredInstallationMode_Fails_WhenDeviceDisconnectsBeforeReachingFastbootd()
    {
        // "If the device disconnects, STOP immediately." Uses a short cancellation deadline
        // instead of waiting out the real 30-second internal timeout -- WaitForConnectionModeAsync
        // links the passed-in token with its own timeout, so the mechanism under test (giving
        // up and failing once the device never reaches the target mode) is identical either way,
        // just without a genuinely slow test.
        var harness = new InstallHarness();
        harness.Detection.Current = InstallHarness.AdbCancunf();
        var (romZip, initialZip) = CreateValidPackages();
        var context = harness.BuildContext(InstallMode.Real, TestProfiles.InfinityXVerified(), romZip, initialZip);
        context.BackupAcknowledged = true;
        context.DataWipeConfirmed = true;

        using var cts = new CancellationTokenSource();
        var results = new List<StepResult>();
        await foreach (var step in harness.StateMachine.RunAsync(context, cts.Token))
        {
            results.Add(step);
            if (step.State == InstallState.RequestDataWipeConfirmation && step.Outcome == StepOutcome.Continue)
            {
                // Device disconnects right as the app is about to reboot it into fastbootd.
                harness.Detection.Current = DeviceInfo.Disconnected;
                cts.CancelAfter(TimeSpan.FromMilliseconds(50));
            }
        }

        Assert.Equal(StepOutcome.Failed, results[^1].Outcome);
        Assert.Equal(InstallState.EnterRequiredInstallationMode, results[^1].State);
        Assert.Equal("fastboot-detection-failure", results[^1].RecoveryGuidanceKey);
    }

    [Fact]
    public async Task VerifyKernelSuCompatibility_NeverReturnsFailed_RegardlessOfAssessment()
    {
        // Root/KernelSU is a post-install feature, never a ROM-installation prerequisite --
        // confirmed here at the state-machine level (PreFlightSafetyCheckerTests covers the
        // separate, UI-facing Pre-Flight gate; this is the state the real install walk itself
        // passes through, and it must never stop the walk no matter what it finds).
        var harness = new InstallHarness();
        // No "su -c id"/ksud/manager markers at all, and an old kernel string that won't
        // parse as GKI -- the worst-case "Unsupported" assessment this checker can produce.
        harness.Detection.Current = InstallHarness.AdbCancunf(fingerprint: "motorola/cancunf/cancunf:15/OLD/0001:user/release-keys");
        var (romZip, initialZip) = CreateValidPackages();
        var context = harness.BuildContext(InstallMode.Real, TestProfiles.InfinityXVerified(), romZip, initialZip);

        // Manual loop that stops the instant the state under test is observed -- draining
        // further would walk into EnterRequiredInstallationMode's real 30-second wait for a
        // fastbootd transition this test never provides (a real hang caught before it shipped,
        // not a hypothetical one; see InstallStateMachineTests' own earlier history of this
        // exact mistake).
        StepResult? kernelSuStep = null;
        await foreach (var step in harness.StateMachine.RunAsync(context))
        {
            if (step.State == InstallState.VerifyKernelSuCompatibility)
            {
                kernelSuStep = step;
                break;
            }
        }

        Assert.NotNull(kernelSuStep);
        Assert.Equal(StepOutcome.Continue, kernelSuStep!.Outcome);
    }

    [Fact]
    public async Task FullRealWalk_ReachesComplete_FromAnAbsolutelyFreshContext_WithoutAnyPriorDryRun()
    {
        // Direct proof of the product requirement: Install must not require having run
        // Dry Run first. This context has never had RomValidation/InitialInstallValidation
        // pre-populated and never has BackupAcknowledged/DataWipeConfirmed pre-set -- it is
        // driven exactly the way MainViewModel.DriveToCompletionAsync drives a real click of
        // "Install Custom ROM": run, and on NeedsConfirmation, confirm and resume.
        var harness = new InstallHarness();
        harness.Detection.Current = InstallHarness.AdbCancunf();
        var (romZip, initialZip) = CreateValidPackages();
        var context = harness.BuildContext(InstallMode.Real, TestProfiles.InfinityXVerified(), romZip, initialZip);

        var results = new List<StepResult>();
        StepResult? last = null;
        while (true)
        {
            await foreach (var step in harness.StateMachine.RunAsync(context))
            {
                results.Add(step);
                last = step;
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

            if (last!.Outcome != StepOutcome.NeedsConfirmation) break;

            Assert.Equal("data-wipe", last.ConfirmationKey); // the sole final confirmation
            context.DataWipeConfirmed = true;
        }

        Assert.DoesNotContain(results, r => r.Outcome == StepOutcome.Failed);
        Assert.Equal(StepOutcome.Finished, last!.Outcome);
        Assert.Equal(InstallState.Complete, last.State);
        // Every real safety check still ran, automatically, with no prior dry run:
        Assert.Contains(results, r => r.State == InstallState.VerifyBootloader && r.Outcome == StepOutcome.Continue);
        Assert.Contains(results, r => r.State == InstallState.VerifyChecksums && r.Outcome == StepOutcome.Continue);
        Assert.True(context.BackupAcknowledged); // auto-acknowledged, never a separate dialog
    }

    public void Dispose()
    {
        try { Directory.Delete(_tempDir, recursive: true); } catch { /* best-effort cleanup */ }
    }
}
