namespace AetherixRomMaster.Core.Recovery;

public enum RecoveryActionKind
{
    /// <summary>Re-run the failed state using the exact same files already verified
    /// earlier in this session -- no new file, image, or command is introduced.</summary>
    RetryWithVerifiedFiles,

    /// <summary>Advice only; this app takes no device-altering action
    /// (reconnect cable, check driver, wait for first boot, etc.).</summary>
    NonDestructiveGuidanceOnly,

    /// <summary>No verified path exists. Carries no steps this app can execute --
    /// only an explanation of why, and that manual/service recovery may be needed.</summary>
    NoVerifiedPathStop
}
