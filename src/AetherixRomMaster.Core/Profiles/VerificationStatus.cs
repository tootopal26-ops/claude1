namespace AetherixRomMaster.Core.Profiles;

/// <summary>
/// Whether the exact, device-specific facts needed for REAL flashing (checksums,
/// download URLs, flashing command sequence) have been confirmed against an official
/// source, versus still being a researched-but-unconfirmed starting point.
/// Dry-run mode works at any status; the real flashing engine refuses to proceed
/// past planning while status is anything other than Verified.
/// </summary>
public enum VerificationStatus
{
    Unverified,
    PendingManualConfirmation,
    Verified,
    Rejected
}
