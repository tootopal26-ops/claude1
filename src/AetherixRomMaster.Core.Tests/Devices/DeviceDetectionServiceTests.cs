using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.Shell;
using AetherixRomMaster.Core.Tests.TestDoubles;
using Xunit;

namespace AetherixRomMaster.Core.Tests.Devices;

public class DeviceDetectionServiceTests
{
    private static FakeProcessRunner BuildCancunfAdbRunner()
    {
        return new FakeProcessRunner
        {
            Handler = (_, args) =>
            {
                var joined = string.Join(" ", args);
                if (joined.StartsWith("devices"))
                    return Ok("List of devices attached\nSERIAL123\tdevice\n");
                if (joined.Contains("shell getprop") && !joined.Contains("getprop ro"))
                    return Ok(
                        "[ro.product.manufacturer]: [motorola]\n" +
                        "[ro.product.model]: [moto g64 5g]\n" +
                        "[ro.product.device]: [cancunf]\n" +
                        "[ro.build.version.release]: [15]\n" +
                        "[ro.build.fingerprint]: [motorola/cancunf/cancunf:15/T3SL33.1-99/1:user/release-keys]\n" +
                        "[ro.boot.flash.locked]: [0]\n");
                if (joined.Contains("shell su -c id"))
                    return Ok("uid=0(root) gid=0(root)\n");
                if (joined.Contains("test -d /data/adb/ksu"))
                    return Ok("PRESENT\n");
                if (joined.Contains("test -d /data/adb/magisk"))
                    return Ok("");
                if (joined.Contains("shell uname -r"))
                    return Ok("5.15.104-android13-8-weighu+\n");
                if (joined.Contains("shell df -k /data"))
                    return Ok("Filesystem  1K-blocks  Used  Available Use% Mounted on\n/dev/block/dm-7  107321112 20000000 85000000  20% /data\n");
                if (joined.Contains("shell dumpsys battery"))
                    return Ok("Current Battery Service state:\n  level: 87\n");
                return Ok("");
            },
        };
    }

    private static ProcessResult Ok(string stdout) => new() { ExitCode = 0, StandardOutput = stdout, StandardError = "" };

    [Fact]
    public async Task DetectAsync_PopulatesDeviceInfo_ForSupportedCancunfDevice()
    {
        var runner = BuildCancunfAdbRunner();
        var adb = new AdbClient(runner, "adb");
        var fastboot = new FastbootClient(runner, "fastboot");
        var service = new DeviceDetectionService(adb, fastboot, new RootDetector(adb));

        var device = await service.DetectAsync();

        Assert.Equal(ConnectionMode.Adb, device.ConnectionMode);
        Assert.Equal("motorola", device.Manufacturer);
        Assert.Equal("cancunf", device.Codename);
        Assert.Equal("15", device.AndroidVersion);
        Assert.Equal(BootloaderLockState.Unlocked, device.BootloaderLockState);
        Assert.Equal(RootSolutionType.KernelSU, device.RootSolution);
        Assert.Equal("5.15.104-android13-8-weighu+", device.KernelVersion);
        Assert.Equal(87, device.BatteryPercent);
        Assert.NotNull(device.AvailableStorageBytes);
        Assert.Equal("SERIAL123", device.Serial);
    }

    [Fact]
    public async Task DetectAsync_ReturnsDisconnected_WhenNoAdbOrFastbootDevicesPresent()
    {
        var runner = new FakeProcessRunner
        {
            // "devices -l" (adb) and plain "devices" (fastboot) must be told apart --
            // a loose match here would misread fastboot's reply as a serial. Real fastboot
            // output has no header line at all when nothing is attached, unlike adb.
            Handler = (_, args) => args switch
            {
                ["devices", "-l"] => Ok("List of devices attached\n"),
                ["devices"] => Ok(""),
                _ => Ok(""),
            },
        };
        var adb = new AdbClient(runner, "adb");
        var fastboot = new FastbootClient(runner, "fastboot");
        var service = new DeviceDetectionService(adb, fastboot, new RootDetector(adb));

        var device = await service.DetectAsync();

        Assert.Equal(ConnectionMode.Disconnected, device.ConnectionMode);
        Assert.Null(device.Codename);
    }
}
