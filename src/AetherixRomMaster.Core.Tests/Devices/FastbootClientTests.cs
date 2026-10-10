using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.Shell;
using AetherixRomMaster.Core.Tests.TestDoubles;
using Xunit;

namespace AetherixRomMaster.Core.Tests.Devices;

public class FastbootClientTests
{
    [Fact]
    public async Task GetAllVarsAsync_ParsesLinesWithBootloaderPrefix()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (_, _) => new ProcessResult
            {
                ExitCode = 0,
                StandardOutput = "",
                StandardError = "(bootloader) unlocked: yes\n(bootloader) product: cancunf\nfinished. total time: 0.002s\n",
            },
        };
        var client = new FastbootClient(runner, "fastboot");

        var vars = await client.GetAllVarsAsync("ABC123");

        Assert.Equal("yes", vars["unlocked"]);
        Assert.Equal("cancunf", vars["product"]);
        Assert.False(vars.ContainsKey("finished"));
    }

    [Fact]
    public async Task GetAllVarsAsync_ParsesLinesWithoutBootloaderPrefix()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (_, _) => new ProcessResult
            {
                ExitCode = 0,
                StandardOutput = "securestate: flashing_unlocked\nis-userspace: no\n",
                StandardError = "",
            },
        };
        var client = new FastbootClient(runner, "fastboot");

        var vars = await client.GetAllVarsAsync("ABC123");

        Assert.Equal("flashing_unlocked", vars["securestate"]);
        Assert.Equal("no", vars["is-userspace"]);
    }

    [Fact]
    public async Task ListDevicesAsync_ParsesSerialColumn()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (_, _) => new ProcessResult { ExitCode = 0, StandardOutput = "ABC123\tfastboot\n", StandardError = "" },
        };
        var client = new FastbootClient(runner, "fastboot");

        var serials = await client.ListDevicesAsync();

        Assert.Equal(["ABC123"], serials);
    }
}
