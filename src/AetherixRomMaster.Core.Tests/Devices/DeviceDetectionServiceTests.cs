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

    private static FakeProcessRunner BuildRunnerWithBootloaderProps(string bootloaderPropLines)
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
                        bootloaderPropLines);
                return Ok("");
            },
        };
    }

    [Fact]
    public async Task DetectAsync_ReportsUnlocked_WhenVerifiedBootStateGreenButVbmetaDeviceStateUnlocked()
    {
        // Regression test for a real false positive reported on cancunf hardware: a
        // genuinely OEM-unlocked device was reported as Locked purely because
        // ro.boot.verifiedbootstate=green (current-boot AVB signature state) was being
        // trusted as "bootloader locked". It must now lose to the authoritative
        // ro.boot.vbmeta.device_state property.
        var runner = BuildRunnerWithBootloaderProps(
            "[ro.boot.verifiedbootstate]: [green]\n" +
            "[ro.boot.vbmeta.device_state]: [unlocked]\n");
        var adb = new AdbClient(runner, "adb");
        var fastboot = new FastbootClient(runner, "fastboot");
        var service = new DeviceDetectionService(adb, fastboot, new RootDetector(adb));

        var device = await service.DetectAsync();

        Assert.Equal(BootloaderLockState.Unlocked, device.BootloaderLockState);
    }

    [Fact]
    public async Task DetectAsync_ReportsLocked_WhenMotorolaFlashLockedPropertyIsOne()
    {
        var runner = BuildRunnerWithBootloaderProps("[ro.boot.flash.locked]: [1]\n");
        var adb = new AdbClient(runner, "adb");
        var fastboot = new FastbootClient(runner, "fastboot");
        var service = new DeviceDetectionService(adb, fastboot, new RootDetector(adb));

        var device = await service.DetectAsync();

        Assert.Equal(BootloaderLockState.Locked, device.BootloaderLockState);
    }

    [Fact]
    public async Task DetectAsync_ReportsUnlocked_WhenOnlyVerifiedBootStateOrangeIsPresent()
    {
        var runner = BuildRunnerWithBootloaderProps("[ro.boot.verifiedbootstate]: [orange]\n");
        var adb = new AdbClient(runner, "adb");
        var fastboot = new FastbootClient(runner, "fastboot");
        var service = new DeviceDetectionService(adb, fastboot, new RootDetector(adb));

        var device = await service.DetectAsync();

        Assert.Equal(BootloaderLockState.Unlocked, device.BootloaderLockState);
    }

    [Fact]
    public async Task DetectAsync_ReportsUnknown_WhenVerifiedBootStateGreenIsTheOnlySignal()
    {
        // "green" alone, with none of the more authoritative properties present, must
        // resolve to Unknown -- never guessed as Locked. This is the crux of the fix: the
        // old code treated bare "green" as proof of Locked.
        var runner = BuildRunnerWithBootloaderProps("[ro.boot.verifiedbootstate]: [green]\n");
        var adb = new AdbClient(runner, "adb");
        var fastboot = new FastbootClient(runner, "fastboot");
        var service = new DeviceDetectionService(adb, fastboot, new RootDetector(adb));

        var device = await service.DetectAsync();

        Assert.Equal(BootloaderLockState.Unknown, device.BootloaderLockState);
    }

    [Fact]
    public async Task DetectAsync_ReportsUnknown_WhenNoBootloaderSignalIsPresentAtAll()
    {
        var runner = BuildRunnerWithBootloaderProps("");
        var adb = new AdbClient(runner, "adb");
        var fastboot = new FastbootClient(runner, "fastboot");
        var service = new DeviceDetectionService(adb, fastboot, new RootDetector(adb));

        var device = await service.DetectAsync();

        Assert.Equal(BootloaderLockState.Unknown, device.BootloaderLockState);
    }

    [Fact]
    public async Task VerifyBootloaderViaFastbootAsync_ReturnsAuthoritativeUnlockedState_WhenDeviceReachesFastboot()
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
        Assert.Equal(BootloaderLockState.Unlocked, result.LockState);
        Assert.Equal("yes", result.RawUnlockedVar);
    }

    [Fact]
    public async Task VerifyBootloaderViaFastbootAsync_FailsWithExplanation_WhenDeviceNeverReachesFastboot()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (path, args) =>
            {
                var joined = string.Join(" ", args);
                if (path.Contains("adb") && joined.Contains("reboot bootloader")) return Ok("");
                if (path.Contains("fastboot") && args is ["devices"]) return Ok(""); // never appears
                return Ok("");
            },
        };
        var adb = new AdbClient(runner, "adb");
        var fastboot = new FastbootClient(runner, "fastboot");
        var service = new DeviceDetectionService(adb, fastboot, new RootDetector(adb));

        var result = await service.VerifyBootloaderViaFastbootAsync("SERIAL123", TimeSpan.FromMilliseconds(50));

        Assert.False(result.Succeeded);
        Assert.Null(result.LockState);
        Assert.NotNull(result.ErrorMessage);
    }

    [Fact]
    public async Task VerifyBootloaderViaFastbootAsync_FailsWithExplanation_WhenAdbRebootCommandItselfFails()
    {
        var runner = new FakeProcessRunner
        {
            Handler = (_, _) => new ProcessResult { ExitCode = 1, StandardOutput = "", StandardError = "device offline" },
        };
        var adb = new AdbClient(runner, "adb");
        var fastboot = new FastbootClient(runner, "fastboot");
        var service = new DeviceDetectionService(adb, fastboot, new RootDetector(adb));

        var result = await service.VerifyBootloaderViaFastbootAsync("SERIAL123", TimeSpan.FromMilliseconds(50));

        Assert.False(result.Succeeded);
        Assert.NotNull(result.ErrorMessage);
    }
}
