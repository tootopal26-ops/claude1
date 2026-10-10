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
            // Plain ADB/Android mode can never produce a verified bootloader state --
            // see the doc comment on DeriveBootloaderLockStateFromFastboot below for why
            // every ADB-property-based heuristic that used to live here was removed. Only
            // an actual Fastboot round-trip (VerifyBootloaderViaFastbootAsync) or an
            // explicit user attestation can ever yield VerifiedLocked/VerifiedUnlocked.
            BootloaderLockState = BootloaderLockState.UnableToVerify,
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
    /// Deliberately does NOT attempt to infer bootloader lock state from any ADB property
    /// anymore (ro.boot.vbmeta.device_state, ro.boot.flash.locked, ro.boot.verifiedbootstate
    /// all used to feed a heuristic here). Each one, in turn, produced a real false-positive
    /// "Locked" report on genuinely OEM-unlocked cancunf hardware:
    ///   - ro.boot.verifiedbootstate=green reflects whether the *currently booted image*
    ///     passed AVB signature verification -- true on stock firmware regardless of
    ///     OEM-unlock state.
    ///   - ro.boot.vbmeta.device_state="locked" reflects whether AVB verification
    ///     *enforcement* has been disabled -- a later, separate step from OEM-unlocking;
    ///     externally documented reports (e.g. a Pixel 5 where `fastboot flashing unlock`
    ///     genuinely succeeded yet this property stayed "locked" until a vbmeta image with
    ///     verification disabled was separately flashed) confirm a device can be fully
    ///     OEM-unlocked while this property still legitimately reads "locked".
    ///   - ro.boot.flash.locked, despite being Motorola's own documented flash-lock
    ///     property, was the next property in line to independently reproduce the same
    ///     false "Locked" report on real hardware -- the property itself is only updated
    ///     from the bootloader's fused state at boot time, and can read stale on a device
    ///     that hasn't fully power-cycled since being unlocked.
    ///   Three independent "reliable" properties, three independent real-world failures.
    /// Rather than chase a fourth, this app no longer treats ANY ADB property as evidence
    /// of bootloader lock state at all. While the device is booted into Android (ADB mode),
    /// the only honest answer is UnableToVerify -- see BuildFromAdbAsync. A genuine verdict
    /// (VerifiedLocked or VerifiedUnlocked) can only ever come from an actual Fastboot
    /// round-trip (DeriveBootloaderLockStateFromFastboot below), or, for Unlocked only, an
    /// explicit user attestation (InstallContext.BootloaderUserConfirmedUnlocked) that is
    /// always logged and displayed as a human attestation, never as something this app
    /// itself verified.
    /// </summary>
    private static BootloaderLockState DeriveBootloaderLockStateFromFastboot(IReadOnlyDictionary<string, string> vars)
    {
        var unlocked = GetVar(vars, "unlocked");
        if (unlocked is not null)
        {
            if (IsAffirmative(unlocked)) return BootloaderLockState.VerifiedUnlocked;
            if (IsNegative(unlocked)) return BootloaderLockState.VerifiedLocked;
            // Unrecognized value -- fall through instead of guessing. A real bug here once
            // reported Locked for a device whose own Fastboot screen showed
            // "Flashing_unlocked", because any value other than the exact literal "yes"
            // fell through to an unconditional "else = Locked".
        }

        var secureState = GetVar(vars, "securestate");
        if (secureState is not null)
        {
            if (secureState.Equals("flashing_unlocked", StringComparison.OrdinalIgnoreCase)) return BootloaderLockState.VerifiedUnlocked;
            if (secureState.Equals("flashing_locked", StringComparison.OrdinalIgnoreCase)) return BootloaderLockState.VerifiedLocked;
        }

        return BootloaderLockState.UnableToVerify;
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
