using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.Installation;
using AetherixRomMaster.Core.Shell;
using AetherixRomMaster.Core.Tests.TestDoubles;
using Xunit;

namespace AetherixRomMaster.Core.Tests.Installation;

public class AdvancedMaintenanceExecutorTests : IDisposable
{
    private readonly string _workDir = Directory.CreateTempSubdirectory("aetherix-maint-test-").FullName;

    public void Dispose()
    {
        try { Directory.Delete(_workDir, recursive: true); } catch { /* best-effort cleanup */ }
    }

    private static ProcessResult Ok(string stdout = "") => new() { ExitCode = 0, StandardOutput = stdout, StandardError = "" };

    private static AdvancedMaintenanceExecutor BuildExecutor(FakeProcessRunner runner, out FakeInstallLogger logger)
    {
        var adb = new AdbClient(runner, "adb");
        var fastboot = new FastbootClient(runner, "fastboot");
        logger = new FakeInstallLogger();
        return new AdvancedMaintenanceExecutor(adb, fastboot, logger);
    }

    // ----- Format User Data -----

    [Fact]
    public async Task FormatUserDataAsync_DryRun_NeverCallsRealCommand()
    {
        var runner = new FakeProcessRunner { Handler = (_, _) => Ok() };
        var executor = BuildExecutor(runner, out _);

        var result = await executor.FormatUserDataAsync("S1", isDryRun: true);

        Assert.True(result.Succeeded);
        Assert.Contains("DRY RUN", result.Message);
        Assert.Empty(runner.Calls);
    }

    [Fact]
    public async Task FormatUserDataAsync_RealMode_RunsFastbootWipeUserdata()
    {
        var runner = new FakeProcessRunner { Handler = (_, _) => Ok() };
        var executor = BuildExecutor(runner, out _);

        var result = await executor.FormatUserDataAsync("S1", isDryRun: false);

        Assert.True(result.Succeeded);
        Assert.Contains(runner.Calls, c => c.Exe.Contains("fastboot") && c.Args.SequenceEqual(new[] { "-s", "S1", "-w" }));
    }

    [Fact]
    public async Task FormatUserDataAsync_RealMode_ReportsFailure_WhenCommandFails()
    {
        var runner = new FakeProcessRunner { Handler = (_, _) => new ProcessResult { ExitCode = 1, StandardOutput = "", StandardError = "device not found" } };
        var executor = BuildExecutor(runner, out var logger);

        var result = await executor.FormatUserDataAsync("S1", isDryRun: false);

        Assert.False(result.Succeeded);
        Assert.Contains(logger.Entries, e => e.Message.Contains("Failed") && e.Message.Contains("device not found"));
    }

    // ----- Reboot routing -----

    [Fact]
    public async Task RebootToRecoveryAsync_UsesAdbReboot_WhenCurrentlyInAdb()
    {
        var runner = new FakeProcessRunner { Handler = (_, _) => Ok() };
        var executor = BuildExecutor(runner, out _);

        await executor.RebootToRecoveryAsync("S1", ConnectionMode.Adb, isDryRun: false);

        Assert.Contains(runner.Calls, c => c.Exe.Contains("adb") && c.Args.Contains("recovery"));
        Assert.DoesNotContain(runner.Calls, c => c.Exe.Contains("fastboot"));
    }

    [Fact]
    public async Task RebootToRecoveryAsync_UsesFastbootHyphenatedSubcommand_WhenCurrentlyInFastbootBootloader()
    {
        var runner = new FakeProcessRunner { Handler = (_, _) => Ok() };
        var executor = BuildExecutor(runner, out _);

        var result = await executor.RebootToRecoveryAsync("S1", ConnectionMode.FastbootBootloader, isDryRun: false);

        // FastbootClient special-cases this into a single "reboot-recovery" token, not "reboot recovery".
        Assert.Contains(runner.Calls, c => c.Exe.Contains("fastboot") && c.Args.Contains("reboot-recovery"));
        Assert.Contains("reboot-recovery", result.Message);
    }

    [Fact]
    public async Task RebootToFastbootdAsync_DryRun_NeverIssuesAnyCommand()
    {
        var runner = new FakeProcessRunner { Handler = (_, _) => Ok() };
        var executor = BuildExecutor(runner, out _);

        var result = await executor.RebootToFastbootdAsync("S1", ConnectionMode.Adb, isDryRun: true);

        Assert.True(result.Succeeded);
        Assert.Empty(runner.Calls);
    }

    [Fact]
    public async Task RebootToSystemAsync_UsesFastbootPlainReboot_WhenCurrentlyInFastbootUserspace()
    {
        var runner = new FakeProcessRunner { Handler = (_, _) => Ok() };
        var executor = BuildExecutor(runner, out _);

        await executor.RebootToSystemAsync("S1", ConnectionMode.FastbootUserspace, isDryRun: false);

        Assert.Contains(runner.Calls, c => c.Exe.Contains("fastboot") && c.Args.SequenceEqual(new[] { "-s", "S1", "reboot" }));
    }

    // ----- ADB Sideload -----

    [Fact]
    public async Task SideloadPackageAsync_Fails_WhenFileDoesNotExist_WithoutCallingAnything()
    {
        var runner = new FakeProcessRunner { Handler = (_, _) => Ok() };
        var executor = BuildExecutor(runner, out _);

        var result = await executor.SideloadPackageAsync("S1", Path.Combine(_workDir, "missing.zip"), ConnectionMode.AdbSideload, isDryRun: false);

        Assert.False(result.Succeeded);
        Assert.Empty(runner.Calls);
    }

    [Fact]
    public async Task SideloadPackageAsync_Fails_WhenDeviceIsNotAlreadyInSideloadMode()
    {
        var runner = new FakeProcessRunner { Handler = (_, _) => Ok() };
        var executor = BuildExecutor(runner, out _);
        var file = Path.Combine(_workDir, "package.zip");
        File.WriteAllText(file, "contents");

        var result = await executor.SideloadPackageAsync("S1", file, ConnectionMode.Adb, isDryRun: false);

        Assert.False(result.Succeeded);
        Assert.Contains("not in ADB sideload mode", result.Message);
        Assert.Empty(runner.Calls);
    }

    [Fact]
    public async Task SideloadPackageAsync_DryRun_NeverCallsRealCommand_EvenWhenInSideloadMode()
    {
        var runner = new FakeProcessRunner { Handler = (_, _) => Ok() };
        var executor = BuildExecutor(runner, out _);
        var file = Path.Combine(_workDir, "package.zip");
        File.WriteAllText(file, "contents");

        var result = await executor.SideloadPackageAsync("S1", file, ConnectionMode.AdbSideload, isDryRun: true);

        Assert.True(result.Succeeded);
        Assert.Empty(runner.Calls);
    }

    [Fact]
    public async Task SideloadPackageAsync_RealMode_RunsAdbSideload_WhenInSideloadModeAndFileExists()
    {
        var runner = new FakeProcessRunner { Handler = (_, _) => Ok() };
        var executor = BuildExecutor(runner, out _);
        var file = Path.Combine(_workDir, "package.zip");
        File.WriteAllText(file, "contents");

        var result = await executor.SideloadPackageAsync("S1", file, ConnectionMode.AdbSideload, isDryRun: false);

        Assert.True(result.Succeeded);
        Assert.Contains(runner.Calls, c => c.Exe.Contains("adb") && c.Args.Contains("sideload") && c.Args.Contains(file));
    }

    // ----- Every operation's dry run is a true no-op -----

    [Fact]
    public async Task EveryOperation_DryRun_IssuesZeroProcessCalls()
    {
        var runner = new FakeProcessRunner { Handler = (_, _) => Ok() };
        var executor = BuildExecutor(runner, out _);
        var file = Path.Combine(_workDir, "package.zip");
        File.WriteAllText(file, "contents");

        await executor.FormatUserDataAsync("S1", isDryRun: true);
        await executor.RebootToRecoveryAsync("S1", ConnectionMode.FastbootBootloader, isDryRun: true);
        await executor.RebootToBootloaderAsync("S1", ConnectionMode.Adb, isDryRun: true);
        await executor.RebootToFastbootdAsync("S1", ConnectionMode.Adb, isDryRun: true);
        await executor.RebootToSystemAsync("S1", ConnectionMode.FastbootUserspace, isDryRun: true);
        await executor.SideloadPackageAsync("S1", file, ConnectionMode.AdbSideload, isDryRun: true);

        Assert.Empty(runner.Calls);
    }
}
