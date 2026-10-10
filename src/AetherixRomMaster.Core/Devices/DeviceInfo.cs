namespace AetherixRomMaster.Core.Devices;

/// <summary>
/// Snapshot of everything detectable about the currently connected device.
/// Every field is either read verbatim from adb/fastboot output or left null/Unknown —
/// nothing here is ever guessed from a marketing name.
/// </summary>
public sealed class DeviceInfo
{
    public ConnectionMode ConnectionMode { get; init; } = ConnectionMode.Disconnected;

    /// <summary>The adb/fastboot serial used to target this exact device with -s.</summary>
    public string? Serial { get; init; }

    public string? Manufacturer { get; init; }
    public string? Model { get; init; }
    public string? Codename { get; init; }
    public string? AndroidVersion { get; init; }
    public string? BuildFingerprint { get; init; }
    public string? KernelVersion { get; init; }

    public BootloaderLockState BootloaderLockState { get; init; } = BootloaderLockState.Unknown;
    public string? BootloaderVersion { get; init; }
    public string? AntiRollbackIndex { get; init; }

    public RootSolutionType RootSolution { get; init; } = RootSolutionType.None;
    public string? RootSolutionDetail { get; init; }

    public long? AvailableStorageBytes { get; init; }
    public int? BatteryPercent { get; init; }

    public bool AdbConnected => ConnectionMode == ConnectionMode.Adb || ConnectionMode == ConnectionMode.AdbSideload;
    public bool FastbootConnected => ConnectionMode == ConnectionMode.FastbootBootloader || ConnectionMode == ConnectionMode.FastbootUserspace;
    public bool FastbootdConnected => ConnectionMode == ConnectionMode.FastbootUserspace;

    public static DeviceInfo Disconnected { get; } = new() { ConnectionMode = ConnectionMode.Disconnected };
}
