namespace AetherixRomMaster.Core.Devices;

/// <summary>
/// Evidence-based bootloader state. UnableToVerify and VerifiedLocked must never be
/// collapsed into each other: UnableToVerify means no reliable read-only query could
/// complete (e.g. the device is in ADB/Android mode, where this app no longer attempts
/// to infer lock state from ADB properties at all -- see DeviceDetectionService.
/// BuildFromAdbAsync for why); VerifiedLocked/VerifiedUnlocked mean a genuine Fastboot
/// round-trip (or, for Unlocked only, an explicit user attestation) actually produced
/// that verdict.
/// </summary>
public enum BootloaderLockState
{
    UnableToVerify,
    VerifiedLocked,
    VerifiedUnlocked,
}
