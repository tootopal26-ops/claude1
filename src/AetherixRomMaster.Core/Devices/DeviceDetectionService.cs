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
            return adbDevice.State switch
            {
                "sideload" => await BuildFromSideloadAsync(adbDevice, ct),
                // Plain "recovery" is a real, distinct adb state from a normally-booted
                // system -- getprop/root-detection assumptions in BuildFromAdbAsync don't
                // hold here, so this is never routed through that path.
                "recovery" => await BuildFromRecoveryAsync(adbDevice, ct),
                _ => await BuildFromAdbAsync(adbDevice, ct),
            };
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

    /// <summary>
    /// The one bootloader check this app treats as fully authoritative: reboots the
    /// device into the bootloader, queries fastboot directly (never a heuristic), then
    /// reboots back to the system it was running. This is a real, user-visible device
    /// action (two reboots) -- callers must only invoke it from an explicit, user-
    /// initiated action, never automatically on a polling timer.
    /// </summary>
    public async Task<FastbootBootloaderCheckResult> VerifyBootloaderViaFastbootAsync(
        string serial, TimeSpan timeout, CancellationToken ct = default)
    {
        var rebootResult = await _adb.RebootAsync(serial, "bootloader", ct);
        if (!rebootResult.Succeeded)
        {
            return FastbootBootloaderCheckResult.Failed(
                "Could not issue the reboot-to-bootloader command over ADB. Confirm the device is still connected and authorized.");
        }

        var reachedFastboot = await WaitForFastbootDeviceAsync(serial, timeout, ct);
        if (!reachedFastboot)
        {
            return FastbootBootloaderCheckResult.Failed(
                "The device did not appear in fastboot within the timeout. It may still be rebooting, may need its USB driver reinstalled for fastboot mode specifically, or may require a different cable/port.");
        }

        var vars = await _fastboot.GetAllVarsAsync(serial, "all", ct);
        var lockState = DeriveBootloaderLockStateFromFastboot(vars);
        var rawUnlocked = GetVar(vars, "unlocked");
        var rawSecureState = GetVar(vars, "securestate");

        await _fastboot.RebootAsync(serial, null, ct);

        return FastbootBootloaderCheckResult.Success(lockState, rawUnlocked, rawSecureState);
    }

    private async Task<bool> WaitForFastbootDeviceAsync(string serial, TimeSpan timeout, CancellationToken ct)
    {
        using var timeoutCts = new CancellationTokenSource(timeout);
        using var linked = CancellationTokenSource.CreateLinkedTokenSource(ct, timeoutCts.Token);

        while (!linked.IsCancellationRequested)
        {
            var devices = await _fastboot.ListDevicesAsync(linked.Token);
            if (devices.Contains(serial))
            {
                return true;
            }

            try
            {
                await Task.Delay(TimeSpan.FromSeconds(1), linked.Token);
            }
            catch (OperationCanceledException)
            {
                break;
            }
        }
        return false;
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

    private async Task<DeviceInfo> BuildFromRecoveryAsync(AdbDeviceEntry device, CancellationToken ct)
    {
        await Task.CompletedTask;
        return new DeviceInfo
        {
            ConnectionMode = ConnectionMode.AdbRecoveryManual,
            Serial = device.Serial,
            RootSolutionDetail = "Device is in recovery with plain ADB access (not yet in sideload mode). If a sideload " +
                                  "install is pending, select \"Apply Update\" → \"Apply from ADB\" in the recovery menu.",
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
    /// <summary>
    /// Priority order matters here, and it's informed by a real false positive: a cancunf
    /// device with a genuinely unlocked bootloader was reported as Locked because
    /// ro.boot.verifiedbootstate=green was trusted as "locked". That property reflects
    /// whether the *currently booted image* passed AVB signature verification against the
    /// OEM key -- a stock, unmodified system partition reports green regardless of whether
    /// OEM unlocking itself is enabled. It is not the same question as "is the bootloader
    /// unlock-capable/unlocked", and conflating them produces exactly this false positive.
    ///
    /// ro.boot.vbmeta.device_state is the standard AVB 2.0 property for the actual device
    /// lock state (values are literally "unlocked"/"locked", no interpretation needed) and
    /// is checked first. ro.boot.flash.locked (Motorola-specific, 0/1) is second. If neither
    /// is readable, verifiedbootstate is used only for its unambiguous "orange"/"yellow"
    /// case (which in practice only occurs once a device really is unlocked or custom-signed)
    /// -- its "green" value is deliberately never treated as proof of Locked anymore.
    /// When nothing resolves it, this returns Unknown rather than guessing: see
    /// DeviceDetectionServiceTests for the regression test covering this exact scenario.
    /// </summary>
    private static BootloaderLockState DeriveBootloaderLockStateFromProps(IReadOnlyDictionary<string, string> props)
    {
        var vbmetaDeviceState = GetProp(props, "ro.boot.vbmeta.device_state");
        if (vbmetaDeviceState is not null)
        {
            if (vbmetaDeviceState.Equals("unlocked", StringComparison.OrdinalIgnoreCase)) return BootloaderLockState.Unlocked;
            if (vbmetaDeviceState.Equals("locked", StringComparison.OrdinalIgnoreCase)) return BootloaderLockState.Locked;
        }

        var flashLocked = GetProp(props, "ro.boot.flash.locked");
        if (flashLocked == "0") return BootloaderLockState.Unlocked;
        if (flashLocked == "1") return BootloaderLockState.Locked;

        var verifiedBootState = GetProp(props, "ro.boot.verifiedbootstate");
        if (verifiedBootState is not null &&
            (verifiedBootState.Equals("orange", StringComparison.OrdinalIgnoreCase) ||
             verifiedBootState.Equals("yellow", StringComparison.OrdinalIgnoreCase)))
        {
            return BootloaderLockState.Unlocked;
        }

        return BootloaderLockState.Unknown;
    }

    /// <summary>
    /// Mirrors <see cref="DeriveBootloaderLockStateFromProps"/>'s fail-safe design: a real
    /// bug here reported Locked for a device whose own Fastboot screen showed
    /// "Flashing_unlocked", because `getvar unlocked` returning anything other than the
    /// exact literal "yes" (e.g. a bootloader that answers "true"/"false" instead of
    /// "yes"/"no", or a partial/garbled read on a flaky USB enumeration) fell through to
    /// an unconditional "else = Locked". An unrecognized value must never be treated as
    /// proof of Locked -- only an explicit, recognized "locked" signal does that. Anything
    /// else falls through to the next signal, and ultimately to Unknown ("unable to
    /// verify") rather than guessing. See DeviceDetectionServiceTests for the regression
    /// test covering this exact scenario.
    /// </summary>
    private static BootloaderLockState DeriveBootloaderLockStateFromFastboot(IReadOnlyDictionary<string, string> vars)
    {
        var unlocked = GetVar(vars, "unlocked");
        if (unlocked is not null)
        {
            if (IsAffirmative(unlocked)) return BootloaderLockState.Unlocked;
            if (IsNegative(unlocked)) return BootloaderLockState.Locked;
            // Unrecognized value -- fall through instead of guessing.
        }

        var secureState = GetVar(vars, "securestate");
        if (secureState is not null)
        {
            if (secureState.Equals("flashing_unlocked", StringComparison.OrdinalIgnoreCase)) return BootloaderLockState.Unlocked;
            if (secureState.Equals("flashing_locked", StringComparison.OrdinalIgnoreCase)) return BootloaderLockState.Locked;
        }

        return BootloaderLockState.Unknown;
    }

    private static bool IsAffirmative(string value) =>
        value.Equals("yes", StringComparison.OrdinalIgnoreCase) ||
        value.Equals("true", StringComparison.OrdinalIgnoreCase) ||
        value == "1";

    private static bool IsNegative(string value) =>
        value.Equals("no", StringComparison.OrdinalIgnoreCase) ||
        value.Equals("false", StringComparison.OrdinalIgnoreCase) ||
        value == "0";

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
