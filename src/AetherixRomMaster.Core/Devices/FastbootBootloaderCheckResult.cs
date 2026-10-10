namespace AetherixRomMaster.Core.Devices;

/// <summary>
/// Result of a real, authoritative bootloader check performed in fastboot mode (not a
/// heuristic from ADB properties). This is the one source this app trusts completely --
/// every other source (ro.boot.* properties read while booted into Android) is a
/// best-effort inference that can be wrong, as proven by a real false positive this app
/// shipped with (see DeviceDetectionService.DeriveBootloaderLockStateFromProps).
/// </summary>
public sealed record FastbootBootloaderCheckResult(
    bool Succeeded,
    BootloaderLockState? LockState,
    string? RawUnlockedVar,
    string? RawSecureStateVar,
    string? ErrorMessage)
{
    public static FastbootBootloaderCheckResult Failed(string message) => new(false, null, null, null, message);

    public static FastbootBootloaderCheckResult Success(BootloaderLockState state, string? rawUnlocked, string? rawSecureState)
        => new(true, state, rawUnlocked, rawSecureState, null);
}
