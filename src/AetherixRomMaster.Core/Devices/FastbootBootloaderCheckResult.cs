namespace AetherixRomMaster.Core.Devices;

/// <summary>
/// Result of a real, authoritative bootloader check performed in fastboot mode (not a
/// heuristic from ADB properties). This is the one source this app trusts completely.
/// No ro.boot.* property read while booted into Android is trusted as bootloader-lock
/// evidence at all anymore -- three separate "reliable" properties each, in turn, produced
/// a real false "Locked" positive this app shipped with (see DeviceDetectionService.
/// DeriveBootloaderLockStateFromFastboot's doc comment for the full history). While the
/// device is in plain ADB/Android mode, the only honest state is BootloaderLockState.
/// UnableToVerify -- only this fastboot round-trip (or an explicit user attestation, for
/// Unlocked only) can ever produce a Verified* verdict.
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
