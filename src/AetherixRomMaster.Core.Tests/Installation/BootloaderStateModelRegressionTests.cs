using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.Installation;
using AetherixRomMaster.Core.Recovery;
using AetherixRomMaster.Core.Shell;
using AetherixRomMaster.Core.Tests.TestDoubles;
using Xunit;

namespace AetherixRomMaster.Core.Tests.Installation;

/// <summary>
/// Dedicated regression coverage for the VerifiedUnlocked / VerifiedLocked / UnableToVerify
/// bootloader state model, each test letter-labeled to match the exact scenario list this
/// fix was required to prove: a real device reported ADB=Yes, Fastboot/FastbootD=No/No,
/// Bootloader=Locked, Verification Status=NotAvailable, with Recovery Mode saying
/// "Bootloader lock state reports Locked" -- while the device was never actually proven
/// Locked by any reliable read-only query. Tests A/B/D/E exercise the lower-level Fastboot
/// mechanism directly (see also DeviceDetectionServiceTests.cs for broader coverage of the
/// same methods); C/F/G/H exercise the full state machine / Pre-Flight checker / Recovery
/// Planner stack together, since the real bug lived in how those layers combined.
/// </summary>
public class BootloaderStateModelRegressionTests : IDisposable
{
    private readonly string _tempDir = Directory.CreateTempSubdirectory("aetherix-bootloader-regression-").FullName;

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

    private static ProcessResult Ok(string stdout) => new() { ExitCode = 0, StandardOutput = stdout, StandardError = "" };

    // ---------------------------------------------------------------------------------
    // A. Fastboot unavailable -> UnableToVerify
    // ---------------------------------------------------------------------------------
    [Fact]
    public async Task A_FastbootUnavailable_AndNoAdbDeviceEither_ReportsUnableToVerify_NeverLocked()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (_, args) => args switch
            {
                ["devices", "-l"] => Ok("List of devices attached\n"),
                ["devices"] => Ok(""), // fastboot: no device enumerates at all
                _ => Ok(""),
            },
        };
        var adb = new AdbClient(runner, "adb");
        var fastboot = new FastbootClient(runner, "fastboot");
        var service = new DeviceDetectionService(adb, fastboot, new RootDetector(adb));

        var device = await service.DetectAsync();

        Assert.Equal(ConnectionMode.Disconnected, device.ConnectionMode);
        Assert.Equal(BootloaderLockState.UnableToVerify, device.BootloaderLockState);
        Assert.NotEqual(BootloaderLockState.VerifiedLocked, device.BootloaderLockState);
    }

    // ---------------------------------------------------------------------------------
    // B. Fastboot timeout -> UnableToVerify
    // ---------------------------------------------------------------------------------
    [Fact]
    public async Task B_FastbootTimeout_WhileWaitingToReachBootloader_NeverReturnsLocked()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (path, args) =>
            {
                var joined = string.Join(" ", args);
                if (path.Contains("adb") && joined.Contains("reboot bootloader")) return Ok("");
                if (path.Contains("fastboot") && args is ["devices"]) return Ok(""); // never appears -- times out
                return Ok("");
            },
        };
        var adb = new AdbClient(runner, "adb");
        var fastboot = new FastbootClient(runner, "fastboot");
        var service = new DeviceDetectionService(adb, fastboot, new RootDetector(adb));

        var result = await service.VerifyBootloaderViaFastbootAsync("SERIAL123", TimeSpan.FromMilliseconds(50));

        Assert.False(result.Succeeded);
        Assert.Null(result.LockState);
        Assert.NotEqual(BootloaderLockState.VerifiedLocked, result.LockState);
    }

    // ---------------------------------------------------------------------------------
    // C. VerificationStatus=NotAvailable -> NOT Locked
    // ---------------------------------------------------------------------------------
    [Fact]
    public async Task C_RecoverySession_ClassifiedNotAvailable_NeverClaimsLocked_ForUnableToVerifyFailure()
    {
        // Reproduces the exact real-hardware report: ADB-connected, Fastboot/FastbootD
        // unavailable, bootloader UnableToVerify and unconfirmed. VerifyBootloader's
        // Failed() call passes no recovery-guidance key, so RecoveryPlanner.Classify falls
        // through to its generic "not a recognized recovery scenario" bucket --
        // RecoveryPathAvailability.NotAvailable. That classification is expected and fine;
        // what must never happen is NotAvailable being paired with a message that claims
        // the bootloader was found Locked.
        var harness = new InstallHarness();
        harness.Detection.Current = InstallHarness.AdbCancunf() with { BootloaderLockState = BootloaderLockState.UnableToVerify };
        var (romZip, initialZip) = CreateValidPackages();
        var context = harness.BuildContext(InstallMode.Real, TestProfiles.InfinityXVerified(), romZip, initialZip);

        await DrainAsync(harness.StateMachine.RunAsync(context));

        Assert.NotNull(context.LastFailedStep);
        Assert.Equal(InstallState.VerifyBootloader, context.LastFailedStep!.State);

        var planner = new RecoveryPlanner(harness.Detection);
        var session = await planner.BuildSessionAsync(context);

        Assert.Equal(RecoveryPathAvailability.NotAvailable, session.PathAvailability);
        Assert.DoesNotContain("Locked", session.FailureMessage);
    }

    // ---------------------------------------------------------------------------------
    // D. Explicit fastboot unlocked response -> VerifiedUnlocked
    // ---------------------------------------------------------------------------------
    [Fact]
    public async Task D_ExplicitFastbootUnlockedResponse_ReturnsVerifiedUnlocked()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (path, args) =>
            {
                var joined = string.Join(" ", args);
                if (path.Contains("adb") && joined.Contains("reboot bootloader")) return Ok("");
                if (path.Contains("fastboot") && args is ["devices"]) return Ok("SERIAL123\tfastboot\n");
                if (path.Contains("fastboot") && joined.Contains("getvar all"))
                    return new ProcessResult { ExitCode = 0, StandardOutput = "", StandardError = "unlocked: yes\nsecurestate: flashing_unlocked\nfinished. total time: 0.001s\n" };
                if (path.Contains("fastboot") && joined.Contains("reboot")) return Ok("");
                return Ok("");
            },
        };
        var adb = new AdbClient(runner, "adb");
        var fastboot = new FastbootClient(runner, "fastboot");
        var service = new DeviceDetectionService(adb, fastboot, new RootDetector(adb));

        var result = await service.VerifyBootloaderViaFastbootAsync("SERIAL123", TimeSpan.FromSeconds(5));

        Assert.True(result.Succeeded);
        Assert.Equal(BootloaderLockState.VerifiedUnlocked, result.LockState);
    }

    // ---------------------------------------------------------------------------------
    // E. Explicit fastboot locked response -> VerifiedLocked
    // ---------------------------------------------------------------------------------
    [Fact]
    public async Task E_ExplicitFastbootLockedResponse_ReturnsVerifiedLocked()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (path, args) =>
            {
                var joined = string.Join(" ", args);
                if (path.Contains("adb") && joined.Contains("reboot bootloader")) return Ok("");
                if (path.Contains("fastboot") && args is ["devices"]) return Ok("SERIAL123\tfastboot\n");
                if (path.Contains("fastboot") && joined.Contains("getvar all"))
                    return new ProcessResult { ExitCode = 0, StandardOutput = "", StandardError = "unlocked: no\nsecurestate: flashing_locked\nfinished. total time: 0.001s\n" };
                if (path.Contains("fastboot") && joined.Contains("reboot")) return Ok("");
                return Ok("");
            },
        };
        var adb = new AdbClient(runner, "adb");
        var fastboot = new FastbootClient(runner, "fastboot");
        var service = new DeviceDetectionService(adb, fastboot, new RootDetector(adb));

        var result = await service.VerifyBootloaderViaFastbootAsync("SERIAL123", TimeSpan.FromSeconds(5));

        Assert.True(result.Succeeded);
        Assert.Equal(BootloaderLockState.VerifiedLocked, result.LockState);
    }

    // ---------------------------------------------------------------------------------
    // F. VerifiedLocked blocks installation
    // ---------------------------------------------------------------------------------
    [Fact]
    public async Task F_VerifiedLocked_BlocksInstallation_InBothStateMachineAndPreFlightChecker()
    {
        var harness = new InstallHarness();
        harness.Detection.Current = InstallHarness.AdbCancunf() with { BootloaderLockState = BootloaderLockState.VerifiedLocked };
        var (romZip, initialZip) = CreateValidPackages();
        var context = harness.BuildContext(InstallMode.Real, TestProfiles.InfinityXVerified(), romZip, initialZip);

        var results = await DrainAsync(harness.StateMachine.RunAsync(context));
        var last = results[^1];
        Assert.Equal(InstallState.VerifyBootloader, last.State);
        Assert.Equal(StepOutcome.Failed, last.Outcome);
        Assert.Contains("Locked", last.Message);

        var preFlight = new PreFlightSafetyChecker(harness.Detection, new RecoveryPlanner(harness.Detection));
        var report = await preFlight.RunAsync(context);
        var bootloaderItem = report.Items.First(i => i.Name.Contains("Bootloader"));
        Assert.Equal(PreFlightCheckStatus.Blocked, bootloaderItem.Status);
        Assert.False(report.CanProceed);
    }

    // ---------------------------------------------------------------------------------
    // G. UnableToVerify does not falsely claim Locked
    // ---------------------------------------------------------------------------------
    [Fact]
    public async Task G_UnableToVerify_NeverFalselyClaimsLocked_AcrossStateMachineAndPreFlightChecker()
    {
        // The core of the whole bug report, reproduced end to end: ADB connected,
        // Fastboot/FastbootD unavailable, bootloader UnableToVerify and unconfirmed. Must
        // surface the exact required text, never the word "Locked", at every layer.
        var harness = new InstallHarness();
        harness.Detection.Current = InstallHarness.AdbCancunf() with { BootloaderLockState = BootloaderLockState.UnableToVerify };
        var (romZip, initialZip) = CreateValidPackages();
        var context = harness.BuildContext(InstallMode.Real, TestProfiles.InfinityXVerified(), romZip, initialZip);

        var results = await DrainAsync(harness.StateMachine.RunAsync(context));
        var last = results[^1];
        Assert.Equal(InstallState.VerifyBootloader, last.State);
        Assert.Equal(StepOutcome.Failed, last.Outcome);
        Assert.DoesNotContain("Locked", last.Message);
        Assert.Contains("Unable to verify bootloader — reboot to bootloader/Fastboot to verify.", last.Message);

        var preFlight = new PreFlightSafetyChecker(harness.Detection, new RecoveryPlanner(harness.Detection));
        var report = await preFlight.RunAsync(context);
        var bootloaderItem = report.Items.First(i => i.Name.Contains("Bootloader"));
        Assert.Equal(PreFlightCheckStatus.UnableToVerify, bootloaderItem.Status);
        Assert.NotEqual(PreFlightCheckStatus.Blocked, bootloaderItem.Status);
        Assert.DoesNotContain("Locked", bootloaderItem.Detail);
    }

    // ---------------------------------------------------------------------------------
    // H. Recovery Mode UI displays the same state as the underlying verification result
    // ---------------------------------------------------------------------------------
    [Fact]
    public async Task H_RecoveryModeMessage_IsTheSameMessageTheStateMachineProduced_NotASeparateCopy()
    {
        // Recovery Mode's displayed failure text must be sourced from the same StepResult
        // the state machine actually produced, never a separately-maintained copy that
        // could drift -- that drift is exactly how a prior version of this app ended up
        // displaying "Locked" in Recovery Mode when the underlying check had never
        // positively found Locked at all.
        var harness = new InstallHarness();
        harness.Detection.Current = InstallHarness.AdbCancunf() with { BootloaderLockState = BootloaderLockState.UnableToVerify };
        var (romZip, initialZip) = CreateValidPackages();
        var context = harness.BuildContext(InstallMode.Real, TestProfiles.InfinityXVerified(), romZip, initialZip);

        await DrainAsync(harness.StateMachine.RunAsync(context));
        var failedStep = context.LastFailedStep;
        Assert.NotNull(failedStep);

        var planner = new RecoveryPlanner(harness.Detection);
        var session = await planner.BuildSessionAsync(context);

        Assert.Equal(failedStep!.State, session.FailedState);
        Assert.Equal(failedStep.Message, session.FailureMessage);
        Assert.DoesNotContain("Locked", session.FailureMessage);
    }

    public void Dispose()
    {
        try { Directory.Delete(_tempDir, recursive: true); } catch { /* best-effort cleanup */ }
    }
}
