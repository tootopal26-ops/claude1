namespace AetherixRomMaster.Core.KernelSu.Restoration;

/// <summary>The 16 user-visible states of the KernelSU Root Restoration workflow, in the
/// order a successful run passes through them. Blocked is the only non-linear exit -- it
/// can be reached from almost any earlier state the moment something required turns out
/// to be unverified, missing, or wrong.</summary>
public enum KernelSuRestorationState
{
    CheckingKernelSu,
    AlreadyIntegrated,
    RestorationRequired,
    PreparingImage,
    TransferringImageToDevice,
    WaitingForKernelSuManager,
    WaitingForPatchedImage,
    VerifyingPatchedImage,
    PatchedImageVerified,
    ReadyToRestore,
    RebootingToRequiredMode,
    Installing,
    RebootingToSystem,
    VerifyingKernelSu,
    RestoredSuccessfully,

    /// <summary>Restoration cannot continue safely -- something required (device/build
    /// match, image type, target partition, checksum, installation method) is unverified,
    /// missing, or wrong. Terminal: the session never auto-retries from here.</summary>
    Blocked,
}
