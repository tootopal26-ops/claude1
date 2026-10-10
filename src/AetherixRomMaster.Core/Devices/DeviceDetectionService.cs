namespace AetherixRomMaster.Core.Devices;

/// <summary>
/// Builds a single <see cref="DeviceInfo"/> snapshot from whatever adb/fastboot can
/// currently see. Every field is derived from a real adb/fastboot query; nothing is
/// ever taken from a user-editable setting, because the bootloader/root state must
/// always reflect what the device itself reports.
/// </summary>
public sealed class DeviceDetectionService : IDeviceDetectionService
{
    private readonly AdbClient _adb;
    private readonly FastbootClient _fastboot;
    private readonly RootDetector _rootDetector;

    public DeviceDetectionService(AdbClient adb, FastbootClient fastboot, RootDetector? rootDetector = null)
    {
        _adb = adb;
        _fastboot = fastboot;
        _rootDetector = rootDetector ?? new RootDetector(adb);
    }

    public async Task<DeviceInfo> DetectAsync(CancellationToken ct = default)
    {
        var adbDevices = await _adb.ListDevicesAsync(ct);
        var adbDevice = adbDevices.FirstOrDefault(d => d.State is "device" or "recovery" or "sideload");

        if (adbDevice is not null)
        {
            return adbDevice.State == "sideload"
                ? await BuildFromSideloadAsync(adbDevice, ct)
                : await BuildFromAdbAsync(adbDevice, ct);
        }

        var unauthorized = adbDevices.FirstOrDefault(d => d.State == "unauthorized");
        if (unauthorized is not null)
        {
            return new DeviceInfo
            {
                ConnectionMode = ConnectionMode.Unknown,
                RootSolutionDetail = "Device detected but USB debugging is not authorized yet. Unlock the phone and accept the \"Allow USB debugging\" prompt.",
            };
        }

        var fastbootSerials = await _fastboot.ListDevicesAsync(ct);
        if (fastbootSerials.Count > 0)
        {
            return await BuildFromFastbootAsync(fastbootSerials[0], ct);
        }

        return DeviceInfo.Disconnected;
    }

    private async Task<DeviceInfo> BuildFromAdbAsync(AdbDeviceEntry device, CancellationToken ct)
    {
        var props = await _adb.GetAllPropsAsync(device.Serial, ct);
        var root = await _rootDetector.DetectAsync(device.Serial, ct);

        return new DeviceInfo
        {
            ConnectionMode = ConnectionMode.Adb,
            Serial = device.Serial,
            Manufacturer = GetProp(props, "ro.product.manufacturer"),
            Model = GetProp(props, "ro.product.model"),
            Codename = GetProp(props, "ro.product.device"),
            AndroidVersion = GetProp(props, "ro.build.version.release"),
            BuildFingerprint = GetProp(props, "ro.build.fingerprint"),
            KernelVersion = await GetKernelVersionAsync(device.Serial, ct),
            BootloaderLockState = DeriveBootloaderLockStateFromProps(props),
            RootSolution = root.Solution,
            RootSolutionDetail = root.Detail,
            AvailableStorageBytes = await GetAvailableStorageBytesAsync(device.Serial, ct),
            BatteryPercent = await GetBatteryPercentAsync(device.Serial, ct),
        };
    }

    private async Task<DeviceInfo> BuildFromSideloadAsync(AdbDeviceEntry device, CancellationToken ct)
    {
        await Task.CompletedTask;
        return new DeviceInfo
        {
            ConnectionMode = ConnectionMode.AdbSideload,
            Serial = device.Serial,
            RootSolutionDetail = "Device is in ADB sideload mode, waiting for a package to be pushed.",
        };
    }

    private async Task<DeviceInfo> BuildFromFastbootAsync(string serial, CancellationToken ct)
    {
        var vars = await _fastboot.GetAllVarsAsync(serial, "all", ct);
        var isUserspace = GetVar(vars, "is-userspace");
        var connectionMode = string.Equals(isUserspace, "yes", StringComparison.OrdinalIgnoreCase)
            ? ConnectionMode.FastbootUserspace
            : ConnectionMode.FastbootBootloader;

        return new DeviceInfo
        {
            ConnectionMode = connectionMode,
            Serial = serial,
            Codename = GetVar(vars, "product"),
            BootloaderVersion = GetVar(vars, "version-bootloader"),
            AntiRollbackIndex = GetVar(vars, "anti") ?? GetVar(vars, "anti-rollback"),
            BootloaderLockState = DeriveBootloaderLockStateFromFastboot(vars),
        };
    }

    private static string? GetProp(IReadOnlyDictionary<string, string> props, string key)
        => props.TryGetValue(key, out var value) && value.Length > 0 ? value : null;

    private static string? GetVar(IReadOnlyDictionary<string, string> vars, string key)
        => vars.TryGetValue(key, out var value) && value.Length > 0 && !value.Equals("not found", StringComparison.OrdinalIgnoreCase)
            ? value
            : null;

    /// <summary>
    /// Derives lock state from device-reported properties only. ro.boot.flash.locked is the
    /// Motorola-specific property (0 = unlocked, 1 = locked); ro.boot.verifiedbootstate is the
    /// generic AVB fallback (orange/yellow = unlocked, green = locked). Never guessed, never
    /// taken from a user toggle.
    /// </summary>
    private static BootloaderLockState DeriveBootloaderLockStateFromProps(IReadOnlyDictionary<string, string> props)
    {
        var flashLocked = GetProp(props, "ro.boot.flash.locked");
        if (flashLocked == "0") return BootloaderLockState.Unlocked;
        if (flashLocked == "1") return BootloaderLockState.Locked;

        var verifiedBootState = GetProp(props, "ro.boot.verifiedbootstate");
        if (verifiedBootState is not null)
        {
            if (verifiedBootState.Equals("orange", StringComparison.OrdinalIgnoreCase) ||
                verifiedBootState.Equals("yellow", StringComparison.OrdinalIgnoreCase))
            {
                return BootloaderLockState.Unlocked;
            }
            if (verifiedBootState.Equals("green", StringComparison.OrdinalIgnoreCase))
            {
                return BootloaderLockState.Locked;
            }
        }

        return BootloaderLockState.Unknown;
    }

    private static BootloaderLockState DeriveBootloaderLockStateFromFastboot(IReadOnlyDictionary<string, string> vars)
    {
        var unlocked = GetVar(vars, "unlocked");
        if (unlocked is not null)
        {
            return unlocked.Equals("yes", StringComparison.OrdinalIgnoreCase)
                ? BootloaderLockState.Unlocked
                : BootloaderLockState.Locked;
        }

        var secureState = GetVar(vars, "securestate");
        if (secureState is not null)
        {
            if (secureState.Equals("flashing_unlocked", StringComparison.OrdinalIgnoreCase)) return BootloaderLockState.Unlocked;
            if (secureState.Equals("flashing_locked", StringComparison.OrdinalIgnoreCase)) return BootloaderLockState.Locked;
        }

        return BootloaderLockState.Unknown;
    }

    private async Task<string?> GetKernelVersionAsync(string serial, CancellationToken ct)
    {
        var result = await _adb.ShellAsync(serial, "uname -r", ct);
        var value = result.StandardOutput.Trim();
        return result.Succeeded && value.Length > 0 ? value : null;
    }

    private async Task<long?> GetAvailableStorageBytesAsync(string serial, CancellationToken ct)
    {
        var result = await _adb.ShellAsync(serial, "df -k /data", ct);
        if (!result.Succeeded) return null;

        var lines = result.StandardOutput.Split('\n', StringSplitOptions.TrimEntries | StringSplitOptions.RemoveEmptyEntries);
        var dataLine = lines.FirstOrDefault(l => l.Contains("/data", StringComparison.Ordinal));
        if (dataLine is null) return null;

        var parts = dataLine.Split((char[]?)null, StringSplitOptions.RemoveEmptyEntries);
        // Standard `df -k` columns: Filesystem 1K-blocks Used Available Use% Mounted-on
        if (parts.Length >= 4 && long.TryParse(parts[3], out var availableKb))
        {
            return availableKb * 1024;
        }
        return null;
    }

    private async Task<int?> GetBatteryPercentAsync(string serial, CancellationToken ct)
    {
        var result = await _adb.ShellAsync(serial, "dumpsys battery", ct);
        if (!result.Succeeded) return null;

        foreach (var line in result.StandardOutput.Split('\n'))
        {
            var trimmed = line.Trim();
            if (trimmed.StartsWith("level:", StringComparison.OrdinalIgnoreCase))
            {
                var value = trimmed["level:".Length..].Trim();
                if (int.TryParse(value, out var level)) return level;
            }
        }
        return null;
    }
}
