using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.Shell;
using AetherixRomMaster.Core.Tests.TestDoubles;
using Xunit;

namespace AetherixRomMaster.Core.Tests.Devices;

public class RootDetectorTests
{
    private static ProcessResult Ok(string stdout) => new() { ExitCode = 0, StandardOutput = stdout, StandardError = "" };

    [Fact]
    public async Task DetectAsync_DoesNotReportRoot_WhenExitCodeIsZeroButOutputLacksUidZero()
    {
        // "su" can exit 0 even when it just failed silently or printed nothing useful --
        // exit code alone must never be treated as proof of root.
        var runner = new FakeProcessRunner { Handler = (_, _) => Ok("") };
        var detector = new RootDetector(new AdbClient(runner, "adb"));

        var result = await detector.DetectAsync("SERIAL123");

        Assert.Equal(RootSolutionType.None, result.Solution);
    }

    [Fact]
    public async Task DetectAsync_ReportsNone_WhenSuFailsAndNoKsudOrManagerFound()
    {
        // A genuinely stock, non-rooted device: "su" doesn't exist at all, but the ordinary,
        // always-available "ps -A" / "pm list packages" checks both succeed and find nothing.
        var runner = new FakeProcessRunner
        {
            Handler = (_, args) =>
            {
                var joined = string.Join(" ", args);
                if (joined.Contains("su -c id")) return new ProcessResult { ExitCode = 1, StandardOutput = "", StandardError = "su: not found" };
                return Ok("");
            },
        };
        var detector = new RootDetector(new AdbClient(runner, "adb"));

        var result = await detector.DetectAsync("SERIAL123");

        Assert.Equal(RootSolutionType.None, result.Solution);
    }

    [Fact]
    public async Task DetectAsync_ReportsKernelSuDetectedNotGranted_WhenKsudRunningButSuDenied()
    {
        // The exact real-world scenario reported against cancunf hardware: KernelSU Manager
        // shows "Working" (ksud is genuinely running) but this adb session has no su grant.
        var runner = new FakeProcessRunner
        {
            Handler = (_, args) =>
            {
                var joined = string.Join(" ", args);
                if (joined.Contains("su -c id")) return new ProcessResult { ExitCode = 1, StandardOutput = "", StandardError = "Permission denied" };
                if (joined.Contains("ps -A")) return Ok("root 1234 1 ... S ksud\n");
                return Ok("");
            },
        };
        var detector = new RootDetector(new AdbClient(runner, "adb"));

        var result = await detector.DetectAsync("SERIAL123");

        Assert.Equal(RootSolutionType.KernelSuDetectedNotGranted, result.Solution);
        Assert.Contains("not been granted root", result.Detail);
    }

    [Fact]
    public async Task DetectAsync_ReportsManagerInstalledOnly_WhenManagerPackagePresentButNoKsudOrGrant()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (_, args) =>
            {
                var joined = string.Join(" ", args);
                if (joined.Contains("su -c id")) return new ProcessResult { ExitCode = 1, StandardOutput = "", StandardError = "Permission denied" };
                if (joined.Contains("ps -A")) return Ok("u0_a123 5555 1 ... S com.android.systemui\n");
                if (joined.Contains("pm list packages")) return Ok("package:me.weishu.kernelsu\n");
                return Ok("");
            },
        };
        var detector = new RootDetector(new AdbClient(runner, "adb"));

        var result = await detector.DetectAsync("SERIAL123");

        Assert.Equal(RootSolutionType.KernelSuManagerInstalledOnly, result.Solution);
        Assert.Contains("kernel", result.Detail, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public async Task DetectAsync_ReportsInconclusive_WhenProcessListCannotBeRead()
    {
        // A transport/communication failure on "ps -A" itself must never be reinterpreted
        // as "no ksud process", which would silently downgrade into a false "not rooted".
        var runner = new FakeProcessRunner
        {
            Handler = (_, args) =>
            {
                var joined = string.Join(" ", args);
                if (joined.Contains("su -c id")) return new ProcessResult { ExitCode = 1, StandardOutput = "", StandardError = "Permission denied" };
                if (joined.Contains("ps -A")) return new ProcessResult { ExitCode = 1, StandardOutput = "", StandardError = "device offline" };
                return Ok("");
            },
        };
        var detector = new RootDetector(new AdbClient(runner, "adb"));

        var result = await detector.DetectAsync("SERIAL123");

        Assert.Equal(RootSolutionType.Inconclusive, result.Solution);
    }

    [Fact]
    public async Task DetectAsync_ReportsInconclusive_WhenPackageListCannotBeRead()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (_, args) =>
            {
                var joined = string.Join(" ", args);
                if (joined.Contains("su -c id")) return new ProcessResult { ExitCode = 1, StandardOutput = "", StandardError = "Permission denied" };
                if (joined.Contains("ps -A")) return Ok("u0_a123 5555 1 ... S com.android.systemui\n");
                if (joined.Contains("pm list packages")) return new ProcessResult { ExitCode = 1, StandardOutput = "", StandardError = "device offline" };
                return Ok("");
            },
        };
        var detector = new RootDetector(new AdbClient(runner, "adb"));

        var result = await detector.DetectAsync("SERIAL123");

        Assert.Equal(RootSolutionType.Inconclusive, result.Solution);
    }

    [Fact]
    public async Task DetectAsync_UsesElevatedSuWrappedMarkerChecks_NotPlainUnprivilegedShell()
    {
        // Regression guard for the latent privilege bug: /data/adb/ is only root-readable, so
        // the marker checks reached after su -c id succeeds must themselves be run via su -c,
        // not as a plain unprivileged shell command.
        var runner = new FakeProcessRunner
        {
            Handler = (_, args) =>
            {
                var joined = string.Join(" ", args);
                if (joined.Contains("su -c id")) return Ok("uid=0(root) gid=0(root)\n");
                if (joined.Contains("su -c") && joined.Contains("/data/adb/ksu")) return Ok("PRESENT\n");
                // An unwrapped (non-"su -c") marker check must not be treated as authoritative.
                if (joined.Contains("/data/adb/ksu")) return Ok("SHOULD-NOT-BE-TRUSTED\n");
                return Ok("");
            },
        };
        var detector = new RootDetector(new AdbClient(runner, "adb"));

        var result = await detector.DetectAsync("SERIAL123");

        Assert.Equal(RootSolutionType.KernelSU, result.Solution);
    }

    [Fact]
    public async Task DetectAsync_ReportsKernelSu_WhenUidZeroConfirmedAndKsuMarkerPresent()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (_, args) =>
            {
                var joined = string.Join(" ", args);
                if (joined.Contains("su -c id")) return Ok("uid=0(root) gid=0(root) context=u:r:su:s0\n");
                if (joined.Contains("/data/adb/ksu")) return Ok("PRESENT\n");
                if (joined.Contains("/data/adb/magisk")) return Ok("");
                return Ok("");
            },
        };
        var detector = new RootDetector(new AdbClient(runner, "adb"));

        var result = await detector.DetectAsync("SERIAL123");

        Assert.Equal(RootSolutionType.KernelSU, result.Solution);
    }

    [Fact]
    public async Task DetectAsync_ReportsMagisk_WhenUidZeroConfirmedAndMagiskMarkerPresent()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (_, args) =>
            {
                var joined = string.Join(" ", args);
                if (joined.Contains("su -c id")) return Ok("uid=0(root) gid=0(root)\n");
                if (joined.Contains("/data/adb/magisk")) return Ok("PRESENT\n");
                return Ok("");
            },
        };
        var detector = new RootDetector(new AdbClient(runner, "adb"));

        var result = await detector.DetectAsync("SERIAL123");

        Assert.Equal(RootSolutionType.Magisk, result.Solution);
    }

    [Fact]
    public async Task DetectAsync_DoesNotMatchUidZero_WhenOutputContainsUnrelatedNumberStartingWithZero()
    {
        // Guards against a naive substring match on "0" anywhere in the output.
        var runner = new FakeProcessRunner { Handler = (_, _) => Ok("uid=10 gid=10 groups=10,20,30\n") };
        var detector = new RootDetector(new AdbClient(runner, "adb"));

        var result = await detector.DetectAsync("SERIAL123");

        Assert.Equal(RootSolutionType.None, result.Solution);
    }
}
