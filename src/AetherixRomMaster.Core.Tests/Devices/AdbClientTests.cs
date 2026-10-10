using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.Shell;
using AetherixRomMaster.Core.Tests.TestDoubles;
using Xunit;

namespace AetherixRomMaster.Core.Tests.Devices;

public class AdbClientTests
{
    [Fact]
    public async Task ListDevicesAsync_ParsesDeviceStateLines()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (_, args) => args[0] == "devices"
                ? new ProcessResult { ExitCode = 0, StandardOutput = "List of devices attached\nABC123\tdevice\nDEF456\tunauthorized\n", StandardError = "" }
                : throw new InvalidOperationException("unexpected call"),
        };
        var client = new AdbClient(runner, "adb");

        var devices = await client.ListDevicesAsync();

        Assert.Equal(2, devices.Count);
        Assert.Equal(new AdbDeviceEntry("ABC123", "device"), devices[0]);
        Assert.Equal(new AdbDeviceEntry("DEF456", "unauthorized"), devices[1]);
    }

    [Fact]
    public async Task GetAllPropsAsync_ParsesBracketedGetpropOutput()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (_, _) => new ProcessResult
            {
                ExitCode = 0,
                StandardOutput = "[ro.product.device]: [cancunf]\n[ro.product.manufacturer]: [motorola]\n[ro.build.version.release]: [15]\n",
                StandardError = "",
            },
        };
        var client = new AdbClient(runner, "adb");

        var props = await client.GetAllPropsAsync("ABC123");

        Assert.Equal("cancunf", props["ro.product.device"]);
        Assert.Equal("motorola", props["ro.product.manufacturer"]);
        Assert.Equal("15", props["ro.build.version.release"]);
    }

    [Fact]
    public async Task ListDevicesAsync_ReturnsEmpty_WhenCommandFails()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (_, _) => new ProcessResult { ExitCode = 1, StandardOutput = "", StandardError = "adb: not found" },
        };
        var client = new AdbClient(runner, "adb");

        var devices = await client.ListDevicesAsync();

        Assert.Empty(devices);
    }
}
