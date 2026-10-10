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
        // Plain ADB/Android mode never yields a verified verdict anymore, regardless of
        // what ro.boot.flash.locked (or any other property) says -- see the dedicated
        // Theory below for the full regression coverage of why.
        Assert.Equal(BootloaderLockState.UnableToVerify, device.BootloaderLockState);
        Assert.Equal(RootSolutionType.KernelSU, device.RootSolution);
        Assert.Equal("5.15.104-android13-8-weighu+", device.KernelVersion);
        Assert.Equal(87, device.BatteryPercent);
        Assert.NotNull(device.AvailableStorageBytes);
        Assert.Equal("SERIAL123", device.Serial);
    }

    [Fact]
    public async Task DetectAsync_ReportsAdbRecoveryManual_WhenAdbStateIsPlainRecovery()
    {
        // "recovery" is a real, distinct adb device state from "sideload" -- it must never
        // be routed through the normally-booted-system detection path (which would read
        // nonsensical getprop/root data from a recovery environment).
        var runner = new FakeProcessRunner
        {
            Handler = (_, args) => args switch
            {
                ["devices", "-l"] => Ok("List of devices attached\nSERIAL123\trecovery\n"),
                _ => Ok(""),
            },
        };
        var adb = new AdbClient(runner, "adb");
        var fastboot = new FastbootClient(runner, "fastboot");
        var service = new DeviceDetectionService(adb, fastboot, new RootDetector(adb));

        var device = await service.DetectAsync();

        Assert.Equal(ConnectionMode.AdbRecoveryManual, device.ConnectionMode);
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

    // Regression coverage for THREE separate real false-"Locked" positives reported in
    // turn on actual cancunf hardware -- ro.boot.verifiedbootstate, then
    // ro.boot.vbmeta.device_state, then (independently) ro.boot.flash.locked -- each one
    // trusted in turn as "the" authoritative ADB-readable bootloader signal, and each one
    // in turn proven wrong on a genuinely OEM-unlocked device (one case externally
    // documented: a Pixel 5 where `fastboot flashing unlock` genuinely succeeded yet
    // ro.boot.vbmeta.device_state stayed "locked" until a separate, later step).
    // Rather than chase a fourth "reliable" property, this app no longer trusts ANY ADB
    // property as bootloader evidence at all: while connected over plain ADB/Android, the
    // state is always UnableToVerify, regardless of what any property (or combination of
    // properties, including the previously-"authoritative" flash.locked=1) says. Only a
    // genuine Fastboot round-trip, or an explicit user attestation (Unlocked only), can
    // ever produce a verified verdict -- see VerifyBootloaderViaFastbootAsync tests below.
    // This is regression coverage for points C and G of the bootloader state-model fix:
    // (C) VerificationStatus=NotAvailable must never be reported as Locked, and
    // (G) UnableToVerify must never be collapsed into/confused with Locked.
    [Theory]
    [InlineData("")]
    [InlineData("[ro.boot.flash.locked]: [0]\n")]
    [InlineData("[ro.boot.flash.locked]: [1]\n")]
    [InlineData("[ro.boot.vbmeta.device_state]: [unlocked]\n")]
    [InlineData("[ro.boot.vbmeta.device_state]: [locked]\n")]
    [InlineData("[ro.boot.verifiedbootstate]: [green]\n")]
    [InlineData("[ro.boot.verifiedbootstate]: [orange]\n")]
    [InlineData("[ro.boot.flash.locked]: [1]\n[ro.boot.vbmeta.device_state]: [locked]\n[ro.boot.verifiedbootstate]: [green]\n")]
    public async Task DetectAsync_AlwaysReportsUnableToVerify_OverPlainAdb_RegardlessOfAnyAdbProperty(string bootloaderPropLines)
    {
        var runner = BuildRunnerWithBootloaderProps(bootloaderPropLines);
        var adb = new AdbClient(runner, "adb");
        var fastboot = new FastbootClient(runner, "fastboot");
        var service = new DeviceDetectionService(adb, fastboot, new RootDetector(adb));

        var device = await service.DetectAsync();

        Assert.Equal(BootloaderLockState.UnableToVerify, device.BootloaderLockState);
        Assert.NotEqual(BootloaderLockState.VerifiedLocked, device.BootloaderLockState);
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
        Assert.Equal(BootloaderLockState.VerifiedUnlocked, result.LockState);
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

    // Regression coverage for a real bug: a device whose own Fastboot screen plainly
    // showed "Flashing_unlocked" was reported Locked, because `getvar unlocked` returning
    // anything other than the exact literal "yes" (e.g. "true"/"false" instead of
    // "yes"/"no", or a garbled/partial read) fell through to an unconditional
    // "else = Locked" instead of treating an unrecognized value as unable-to-verify.
    [Theory]
    [InlineData("true", BootloaderLockState.VerifiedUnlocked)]
    [InlineData("false", BootloaderLockState.VerifiedLocked)]
    [InlineData("1", BootloaderLockState.VerifiedUnlocked)]
    [InlineData("0", BootloaderLockState.VerifiedLocked)]
    [InlineData("yes", BootloaderLockState.VerifiedUnlocked)]
    [InlineData("no", BootloaderLockState.VerifiedLocked)]
    public async Task VerifyBootloaderViaFastbootAsync_RecognizesAlternateTrueFalseSpellings_ForUnlockedGetvar(
        string rawUnlockedValue, BootloaderLockState expected)
    {
        var runner = new FakeProcessRunner
        {
            Handler = (path, args) =>
            {
                var joined = string.Join(" ", args);
                if (path.Contains("adb") && joined.Contains("reboot bootloader")) return Ok("");
                if (path.Contains("fastboot") && args is ["devices"]) return Ok("SERIAL123\tfastboot\n");
                if (path.Contains("fastboot") && joined.Contains("getvar all"))
                    return new ProcessResult { ExitCode = 0, StandardOutput = "", StandardError = $"unlocked: {rawUnlockedValue}\nfinished. total time: 0.001s\n" };
                if (path.Contains("fastboot") && joined.Contains("reboot")) return Ok("");
                return Ok("");
            },
        };
        var adb = new AdbClient(runner, "adb");
        var fastboot = new FastbootClient(runner, "fastboot");
        var service = new DeviceDetectionService(adb, fastboot, new RootDetector(adb));

        var result = await service.VerifyBootloaderViaFastbootAsync("SERIAL123", TimeSpan.FromSeconds(5));

        Assert.True(result.Succeeded);
        Assert.Equal(expected, result.LockState);
    }

    [Fact]
    public async Task VerifyBootloaderViaFastbootAsync_NeverReportsLocked_ForAnUnrecognizedUnlockedValue()
    {
        // A garbled/unexpected getvar value must fall through to "unable to verify"
        // (Unknown), never default to Locked -- that is the exact bug being guarded here.
        var runner = new FakeProcessRunner
        {
            Handler = (path, args) =>
            {
                var joined = string.Join(" ", args);
                if (path.Contains("adb") && joined.Contains("reboot bootloader")) return Ok("");
                if (path.Contains("fastboot") && args is ["devices"]) return Ok("SERIAL123\tfastboot\n");
                if (path.Contains("fastboot") && joined.Contains("getvar all"))
                    return new ProcessResult { ExitCode = 0, StandardOutput = "", StandardError = "unlocked: unsupported\nfinished. total time: 0.001s\n" };
                if (path.Contains("fastboot") && joined.Contains("reboot")) return Ok("");
                return Ok("");
            },
        };
        var adb = new AdbClient(runner, "adb");
        var fastboot = new FastbootClient(runner, "fastboot");
        var service = new DeviceDetectionService(adb, fastboot, new RootDetector(adb));

        var result = await service.VerifyBootloaderViaFastbootAsync("SERIAL123", TimeSpan.FromSeconds(5));

        Assert.True(result.Succeeded);
        Assert.NotEqual(BootloaderLockState.VerifiedLocked, result.LockState);
    }
}
