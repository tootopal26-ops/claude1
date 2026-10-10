namespace AetherixRomMaster.Core.Installation;

public enum InstallState
{
    Initialize,
    DetectDevice,
    VerifyBootloader,
    VerifyRom,
    DownloadRequiredFiles,
    VerifyChecksums,
    VerifyFirmware,
    VerifyRecovery,
    VerifyKernelSuCompatibility,
    CreateBackupGuidance,
    RequestDataWipeConfirmation,
    EnterRequiredInstallationMode,
    InstallRequiredInitialPackage,
    FormatDataIfRequired,
    InstallRom,
    VerifyInstallation,
    RestoreKernelSuIfCompatible,
    Reboot,
    VerifyFinalOs,
    VerifyKernelSuRoot,
    Complete,

    /// <summary>Terminal: a step failed. The installer never auto-continues from here.</summary>
    Failed,

    /// <summary>Terminal: the user cancelled at a safe point.</summary>
    Cancelled
}
