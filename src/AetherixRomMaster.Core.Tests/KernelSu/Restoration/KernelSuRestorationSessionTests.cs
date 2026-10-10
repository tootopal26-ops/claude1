using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.Installation;
using AetherixRomMaster.Core.KernelSu.Restoration;
using AetherixRomMaster.Core.Profiles;
using AetherixRomMaster.Core.Shell;
using AetherixRomMaster.Core.Tests.TestDoubles;
using Xunit;

namespace AetherixRomMaster.Core.Tests.KernelSu.Restoration;

public class KernelSuRestorationSessionTests : IDisposable
{
    private const string RomName = "Project Infinity X (Infinity-X)";
    private readonly string _workDir = Directory.CreateTempSubdirectory("aetherix-ksu-test-").FullName;

    public void Dispose()
    {
        try { Directory.Delete(_workDir, recursive: true); } catch { /* best-effort cleanup */ }
    }

    private static ProcessResult Ok(string stdout = "") => new() { ExitCode = 0, StandardOutput = stdout, StandardError = "" };
    private static ProcessResult Err(string stderr) => new() { ExitCode = 1, StandardOutput = "", StandardError = stderr };

    private static RomProfile BuildRomProfile() => new()
    {
        RomName = RomName,
        TargetCodename = "cancunf",
        InstallationMethod = InstallationMethod.FastbootUpdatePackageThenSideload,
        VerificationStatus = VerificationStatus.Unverified,
        Citations = [],
    };

    private static KernelSuProfile BuildVerifiedProfile() => new()
    {
        TargetCodename = "cancunf",
        TargetRomName = RomName,
        RequiredImageType = KernelImageType.InitBootImg,
        RequiredImageFileName = "init_boot.img",
        TargetPartition = "init_boot",
        VerificationStatus = VerificationStatus.Verified,
        Citations = [],
    };

    private static DeviceInfo BuildDevice() => new()
    {
        ConnectionMode = ConnectionMode.Adb,
        Serial = "SERIAL123",
        Codename = "cancunf",
        BuildFingerprint = "motorola/cancunf/cancunf:16/XYZ/1:user/release-keys",
    };

    private string WriteTempFile(string name, int sizeBytes, byte fill = 1)
    {
        var path = Path.Combine(_workDir, name);
        File.WriteAllBytes(path, Enumerable.Repeat(fill, sizeBytes).ToArray());
        return path;
    }

    private static readonly TimeSpan ShortTimeout = TimeSpan.FromMilliseconds(50);

    private static KernelSuRestorationSession BuildSession(FakeProcessRunner runner, bool isDryRun, out FakeInstallLogger logger)
    {
        var adb = new AdbClient(runner, "adb");
        var fastboot = new FastbootClient(runner, "fastboot");
        var rootDetector = new RootDetector(adb);
        logger = new FakeInstallLogger();
        return new KernelSuRestorationSession(
            adb, fastboot, rootDetector, logger, isDryRun,
            transferTimeout: ShortTimeout, fastbootWaitTimeout: ShortTimeout, adbWaitTimeout: ShortTimeout);
    }

    // ----- CheckAsync -----

    [Fact]
    public async Task CheckAsync_ReportsAlreadyIntegrated_WhenKernelSuIsFullyOperational()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (_, args) =>
            {
                var joined = string.Join(" ", args);
                if (joined.Contains("su -c id")) return Ok("uid=0(root) gid=0(root)\n");
                if (joined.Contains("/data/adb/ksu")) return Ok("PRESENT\n");
                return Ok("");
            },
        };
        var session = BuildSession(runner, isDryRun: true, out _);

        var result = await session.CheckAsync(BuildDevice(), BuildRomProfile(), kernelSuProfile: null);

        Assert.Equal(StepOutcome.Finished, result.Outcome);
        Assert.Equal(KernelSuRestorationState.AlreadyIntegrated, result.State);
    }

    [Fact]
    public async Task CheckAsync_Blocked_WhenNotRootedAndNoKernelSuProfileExists()
    {
        var runner = new FakeProcessRunner { Handler = (_, _) => Ok("") }; // su fails, ps -A empty, pm list empty
        var session = BuildSession(runner, isDryRun: true, out _);

        var result = await session.CheckAsync(BuildDevice(), BuildRomProfile(), kernelSuProfile: null);

        Assert.Equal(StepOutcome.Failed, result.Outcome);
        Assert.Equal(KernelSuRestorationState.Blocked, result.State);
        Assert.Contains("No KernelSU restoration profile", result.Message);
    }

    [Fact]
    public async Task CheckAsync_ReturnsRestorationRequired_WhenKsudRunningButNotGranted_AndProfileVerified()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (_, args) =>
            {
                var joined = string.Join(" ", args);
                if (joined.Contains("su -c id")) return Err("Permission denied");
                if (joined.Contains("ps -A")) return Ok("root 1234 1 ... S ksud\n");
                return Ok("");
            },
        };
        var session = BuildSession(runner, isDryRun: true, out _);

        var result = await session.CheckAsync(BuildDevice(), BuildRomProfile(), BuildVerifiedProfile());

        Assert.Equal(StepOutcome.Continue, result.Outcome);
        Assert.Equal(KernelSuRestorationState.RestorationRequired, result.State);
        Assert.NotNull(session.Plan);
        Assert.True(session.Plan!.CanProceed);
    }

    [Fact]
    public async Task CheckAsync_ReturnsRestorationRequired_WhenManagerInstalledOnly_AndProfileVerified()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (_, args) =>
            {
                var joined = string.Join(" ", args);
                if (joined.Contains("su -c id")) return Err("Permission denied");
                if (joined.Contains("ps -A")) return Ok("u0_a1 1 1 ... S com.android.systemui\n");
                if (joined.Contains("pm list packages")) return Ok("package:me.weishu.kernelsu\n");
                return Ok("");
            },
        };
        var session = BuildSession(runner, isDryRun: true, out _);

        var result = await session.CheckAsync(BuildDevice(), BuildRomProfile(), BuildVerifiedProfile());

        Assert.Equal(StepOutcome.Continue, result.Outcome);
        Assert.Equal(KernelSuRestorationState.RestorationRequired, result.State);
    }

    [Fact]
    public async Task CheckAsync_Blocked_WhenDetectionIsInconclusive_EvenWithAVerifiedProfile()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (_, args) =>
            {
                var joined = string.Join(" ", args);
                if (joined.Contains("su -c id")) return Err("Permission denied");
                if (joined.Contains("ps -A")) return Err("device offline"); // the check itself fails
                return Ok("");
            },
        };
        var session = BuildSession(runner, isDryRun: true, out _);

        var result = await session.CheckAsync(BuildDevice(), BuildRomProfile(), BuildVerifiedProfile());

        Assert.Equal(StepOutcome.Failed, result.Outcome);
        Assert.Contains("inconclusive", result.Message, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public async Task CheckAsync_Blocked_WhenNoDeviceSerial()
    {
        var runner = new FakeProcessRunner { Handler = (_, _) => Ok("") };
        var session = BuildSession(runner, isDryRun: true, out _);

        var result = await session.CheckAsync(DeviceInfo.Disconnected, BuildRomProfile(), BuildVerifiedProfile());

        Assert.Equal(StepOutcome.Failed, result.Outcome);
    }

    // ----- PrepareAndTransferImageAsync -----

    private static async Task<KernelSuRestorationSession> SessionAtRestorationRequiredAsync(FakeProcessRunner runner, bool isDryRun)
    {
        var session = BuildSession(runner, isDryRun, out _);
        var check = await session.CheckAsync(BuildDevice(), BuildRomProfile(), BuildVerifiedProfile());
        Assert.Equal(KernelSuRestorationState.RestorationRequired, check.State);
        return session;
    }

    [Fact]
    public async Task PrepareAndTransferImageAsync_Fails_WhenFileDoesNotExist()
    {
        var runner = new FakeProcessRunner { Handler = NotRootedHandler };
        var session = await SessionAtRestorationRequiredAsync(runner, isDryRun: true);

        var result = await session.PrepareAndTransferImageAsync(Path.Combine(_workDir, "missing.img"));

        Assert.Equal(StepOutcome.Failed, result.Outcome);
    }

    [Fact]
    public async Task PrepareAndTransferImageAsync_Fails_WhenFileNameDoesNotMatchRequiredImageType()
    {
        var runner = new FakeProcessRunner { Handler = NotRootedHandler };
        var session = await SessionAtRestorationRequiredAsync(runner, isDryRun: true);
        var wrongFile = WriteTempFile("boot.img", 2_000_000); // profile requires init_boot.img

        var result = await session.PrepareAndTransferImageAsync(wrongFile);

        Assert.Equal(StepOutcome.Failed, result.Outcome);
        Assert.Contains("does not match", result.Message);
    }

    [Fact]
    public async Task PrepareAndTransferImageAsync_DryRun_NeverPushesAnythingToTheDevice()
    {
        var runner = new FakeProcessRunner { Handler = NotRootedHandler };
        var session = await SessionAtRestorationRequiredAsync(runner, isDryRun: true);
        var originalImage = WriteTempFile("init_boot.img", 5_000_000);

        var result = await session.PrepareAndTransferImageAsync(originalImage);

        Assert.Equal(StepOutcome.NeedsConfirmation, result.Outcome);
        Assert.Equal(KernelSuRestorationState.WaitingForKernelSuManager, result.State);
        Assert.Equal("kernelsu-manager-patch-complete", result.ConfirmationKey);
        Assert.DoesNotContain(runner.Calls, c => c.Args.Contains("push"));
    }

    [Fact]
    public async Task PrepareAndTransferImageAsync_RealMode_PushesTheImage_AndFailsCleanlyIfPushFails()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (path, args) => args.Contains("push") ? Err("device offline") : NotRootedHandler(path, args),
        };
        var session = await SessionAtRestorationRequiredAsync(runner, isDryRun: false);
        var originalImage = WriteTempFile("init_boot.img", 5_000_000);

        var result = await session.PrepareAndTransferImageAsync(originalImage);

        Assert.Equal(StepOutcome.Failed, result.Outcome);
        Assert.Contains("Failed to transfer", result.Message);
    }

    // ----- RetrieveAndVerifyPatchedImageAsync -----

    private async Task<(KernelSuRestorationSession Session, string OriginalPath)> SessionWithOriginalImageAsync(bool isDryRun)
    {
        var runner = new FakeProcessRunner { Handler = NotRootedHandler };
        var session = await SessionAtRestorationRequiredAsync(runner, isDryRun);
        var originalPath = WriteTempFile("init_boot.img", 5_000_000, fill: 7);
        var prep = await session.PrepareAndTransferImageAsync(originalPath);
        Assert.Equal(StepOutcome.NeedsConfirmation, prep.Outcome);
        return (session, originalPath);
    }

    [Fact]
    public async Task RetrieveAndVerifyPatchedImageAsync_Fails_WhenFileTooSmall()
    {
        var (session, _) = await SessionWithOriginalImageAsync(isDryRun: true);
        var tinyFile = WriteTempFile("ksu_patched_0001.img", 100);

        var result = await session.RetrieveAndVerifyPatchedImageAsync(tinyFile);

        Assert.Equal(StepOutcome.Failed, result.Outcome);
    }

    [Fact]
    public async Task RetrieveAndVerifyPatchedImageAsync_Fails_WhenPatchedFileIsByteForByteIdenticalToOriginal()
    {
        var (session, originalPath) = await SessionWithOriginalImageAsync(isDryRun: true);
        var identicalCopy = Path.Combine(_workDir, "ksu_patched_0002.img");
        File.Copy(originalPath, identicalCopy);

        var result = await session.RetrieveAndVerifyPatchedImageAsync(identicalCopy);

        Assert.Equal(StepOutcome.Failed, result.Outcome);
        Assert.Contains("same SHA-256", result.Message);
    }

    [Fact]
    public async Task RetrieveAndVerifyPatchedImageAsync_Succeeds_ForADistinctPlausibleImage()
    {
        var (session, _) = await SessionWithOriginalImageAsync(isDryRun: true);
        var patchedFile = WriteTempFile("ksu_patched_0003.img", 5_100_000, fill: 9);

        var result = await session.RetrieveAndVerifyPatchedImageAsync(patchedFile);

        Assert.Equal(StepOutcome.Continue, result.Outcome);
        Assert.Equal(KernelSuRestorationState.PatchedImageVerified, result.State);
    }

    // ----- BuildSafetySummary -----

    [Fact]
    public async Task BuildSafetySummary_Blocked_WithoutAVerifiedPatchedImage()
    {
        var (session, _) = await SessionWithOriginalImageAsync(isDryRun: true);

        var result = session.BuildSafetySummary(BuildDevice(), BuildRomProfile());

        Assert.Equal(StepOutcome.Failed, result.Outcome);
        Assert.Contains("cannot continue safely", result.Message);
    }

    [Fact]
    public async Task BuildSafetySummary_IncludesEveryRequiredField_AndTheExactConfirmationSentence()
    {
        var (session, _) = await SessionWithOriginalImageAsync(isDryRun: true);
        var patchedFile = WriteTempFile("ksu_patched_0004.img", 5_100_000, fill: 9);
        await session.RetrieveAndVerifyPatchedImageAsync(patchedFile);

        var result = session.BuildSafetySummary(BuildDevice(), BuildRomProfile());

        Assert.Equal(StepOutcome.NeedsConfirmation, result.Outcome);
        Assert.Equal("install-patched-kernelsu-image", result.ConfirmationKey);
        foreach (var required in new[] { "DEVICE:", "ROM:", "BUILD:", "ANDROID VERSION:", "IMAGE TYPE:", "IMAGE FILE:", "SHA-256:", "INSTALLATION MODE:", "TARGET PARTITION:" })
        {
            Assert.Contains(required, result.Message);
        }
        Assert.Contains("I understand that this will install the verified KernelSU-modified image for this exact device and build.", result.Message);
    }

    // ----- InstallPatchedImageAsync -----

    private async Task<KernelSuRestorationSession> SessionReadyToInstallAsync(FakeProcessRunner runner, bool isDryRun)
    {
        var session = await SessionAtRestorationRequiredAsync(runner, isDryRun);
        var originalImage = WriteTempFile("init_boot.img", 5_000_000, fill: 7);
        await session.PrepareAndTransferImageAsync(originalImage);
        var patchedImage = WriteTempFile("ksu_patched_ready.img", 5_100_000, fill: 9);
        await session.RetrieveAndVerifyPatchedImageAsync(patchedImage);
        var summary = session.BuildSafetySummary(BuildDevice(), BuildRomProfile());
        Assert.Equal(StepOutcome.NeedsConfirmation, summary.Outcome);
        return session;
    }

    [Fact]
    public async Task InstallPatchedImageAsync_DryRun_NeverIssuesRebootOrFlashCommands()
    {
        var runner = new FakeProcessRunner { Handler = NotRootedHandler };
        var session = await SessionReadyToInstallAsync(runner, isDryRun: true);
        runner.Calls.Clear();

        var result = await session.InstallPatchedImageAsync(expectedCodename: "cancunf");

        Assert.Equal(StepOutcome.Continue, result.Outcome);
        Assert.Equal(KernelSuRestorationState.RebootingToSystem, result.State);
        Assert.Empty(runner.Calls);
    }

    [Fact]
    public async Task InstallPatchedImageAsync_RealMode_Fails_WhenDeviceNeverReachesFastboot()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (_, args) =>
            {
                var joined = string.Join(" ", args);
                if (joined.Contains("reboot bootloader")) return Ok("");
                if (args is ["devices"]) return Ok(""); // fastboot never sees it
                return NotRootedHandler(_, args);
            },
        };
        var session = await SessionReadyToInstallAsync(runner, isDryRun: false);

        var result = await session.InstallPatchedImageAsync(expectedCodename: "cancunf");

        Assert.Equal(StepOutcome.Failed, result.Outcome);
        Assert.Contains("did not appear in fastboot", result.Message);
    }

    [Fact]
    public async Task InstallPatchedImageAsync_RealMode_Fails_WhenFastbootReportsAnUnexpectedProduct()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (path, args) =>
            {
                var joined = string.Join(" ", args);
                if (joined.Contains("reboot bootloader")) return Ok("");
                if (path.Contains("fastboot") && args is ["devices"]) return Ok("SERIAL123\tfastboot\n");
                if (path.Contains("fastboot") && joined.Contains("getvar product"))
                    return new ProcessResult { ExitCode = 0, StandardOutput = "", StandardError = "product: someotherdevice\nfinished. total time: 0.001s\n" };
                return NotRootedHandler(path, args);
            },
        };
        var session = await SessionReadyToInstallAsync(runner, isDryRun: false);

        var result = await session.InstallPatchedImageAsync(expectedCodename: "cancunf");

        Assert.Equal(StepOutcome.Failed, result.Outcome);
        Assert.Contains("not the expected", result.Message);
        Assert.DoesNotContain(runner.Calls, c => c.Args.Contains("flash"));
    }

    [Fact]
    public async Task InstallPatchedImageAsync_RealMode_FlashesTheVerifiedPartition_WhenEverythingMatches()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (path, args) =>
            {
                var joined = string.Join(" ", args);
                if (joined.Contains("reboot bootloader")) return Ok("");
                if (path.Contains("fastboot") && args is ["devices"]) return Ok("SERIAL123\tfastboot\n");
                if (path.Contains("fastboot") && joined.Contains("getvar product"))
                    return new ProcessResult { ExitCode = 0, StandardOutput = "", StandardError = "product: cancunf\nfinished. total time: 0.001s\n" };
                if (path.Contains("fastboot") && args.Contains("flash")) return Ok("");
                if (path.Contains("fastboot") && joined.Contains("reboot") && !joined.Contains("bootloader")) return Ok("");
                return NotRootedHandler(path, args);
            },
        };
        var session = await SessionReadyToInstallAsync(runner, isDryRun: false);

        var result = await session.InstallPatchedImageAsync(expectedCodename: "cancunf");

        Assert.Equal(StepOutcome.Continue, result.Outcome);
        Assert.Equal(KernelSuRestorationState.RebootingToSystem, result.State);
        Assert.Contains(runner.Calls, c => c.Args.Contains("flash") && c.Args.Contains("init_boot"));
    }

    // ----- VerifyAfterRebootAsync -----

    [Fact]
    public async Task VerifyAfterRebootAsync_DryRun_NeverQueriesTheRealDevice()
    {
        var runner = new FakeProcessRunner { Handler = NotRootedHandler };
        var session = BuildSession(runner, isDryRun: true, out _);

        var result = await session.VerifyAfterRebootAsync("SERIAL123");

        Assert.Equal(StepOutcome.Finished, result.Outcome);
        Assert.Equal(KernelSuRestorationState.RestoredSuccessfully, result.State);
        Assert.Empty(runner.Calls);
    }

    [Fact]
    public async Task VerifyAfterRebootAsync_RealMode_Finished_WhenKernelSuConfirmed()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (_, args) =>
            {
                var joined = string.Join(" ", args);
                if (joined.Contains("wait-for-device")) return Ok("");
                if (joined.Contains("su -c id")) return Ok("uid=0(root) gid=0(root)\n");
                if (joined.Contains("/data/adb/ksu")) return Ok("PRESENT\n");
                return Ok("");
            },
        };
        var session = BuildSession(runner, isDryRun: false, out _);

        var result = await session.VerifyAfterRebootAsync("SERIAL123");

        Assert.Equal(StepOutcome.Finished, result.Outcome);
        Assert.Equal(KernelSuRestorationState.RestoredSuccessfully, result.State);
    }

    [Fact]
    public async Task VerifyAfterRebootAsync_RealMode_NeverClaimsSuccess_WhenKernelSuNotDetectedAfterInstall()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (_, args) =>
            {
                var joined = string.Join(" ", args);
                if (joined.Contains("wait-for-device")) return Ok("");
                return Ok(""); // su fails, ps -A empty, pm list empty -> None
            },
        };
        var session = BuildSession(runner, isDryRun: false, out _);

        var result = await session.VerifyAfterRebootAsync("SERIAL123");

        Assert.Equal(StepOutcome.Failed, result.Outcome);
        Assert.Contains("Do not assume restoration succeeded", result.Message);
    }

    [Fact]
    public async Task VerifyAfterRebootAsync_RealMode_Fails_WhenDeviceNeverComesBackOnAdb()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (_, args) => string.Join(" ", args).Contains("wait-for-device")
                ? new ProcessResult { ExitCode = 1, StandardOutput = "", StandardError = "timeout" }
                : Ok(""),
        };
        var session = BuildSession(runner, isDryRun: false, out _);

        var result = await session.VerifyAfterRebootAsync("SERIAL123");

        Assert.Equal(StepOutcome.Failed, result.Outcome);
        Assert.Contains("did not come back up on ADB", result.Message);
    }

    // ----- End-to-end dry run never touches the device for writes -----

    [Fact]
    public async Task FullDryRunWorkflow_NeverIssuesAnyWriteCommand_FromPrepareThroughVerify()
    {
        var runner = new FakeProcessRunner { Handler = NotRootedHandler };
        var session = await SessionAtRestorationRequiredAsync(runner, isDryRun: true);
        runner.Calls.Clear();

        var originalImage = WriteTempFile("init_boot.img", 5_000_000, fill: 7);
        await session.PrepareAndTransferImageAsync(originalImage);

        var patchedImage = WriteTempFile("ksu_patched_e2e.img", 5_100_000, fill: 9);
        await session.RetrieveAndVerifyPatchedImageAsync(patchedImage);

        session.BuildSafetySummary(BuildDevice(), BuildRomProfile());
        await session.InstallPatchedImageAsync(expectedCodename: "cancunf");
        await session.VerifyAfterRebootAsync("SERIAL123");

        // Dry run must not modify the phone: no push/flash/reboot, in fact no process call at all.
        Assert.Empty(runner.Calls);
        Assert.Equal(KernelSuRestorationState.RestoredSuccessfully, session.CurrentState);
    }

    private static ProcessResult NotRootedHandler(string path, IReadOnlyList<string> args) => Ok("");
}
