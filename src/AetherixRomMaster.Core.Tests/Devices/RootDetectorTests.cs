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
    public async Task DetectAsync_DoesNotReportRoot_WhenSuCommandFails()
    {
        var runner = new FakeProcessRunner { Handler = (_, _) => new ProcessResult { ExitCode = 1, StandardOutput = "", StandardError = "su: not found" } };
        var detector = new RootDetector(new AdbClient(runner, "adb"));

        var result = await detector.DetectAsync("SERIAL123");

        Assert.Equal(RootSolutionType.None, result.Solution);
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
