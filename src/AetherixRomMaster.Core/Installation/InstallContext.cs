using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.KernelSu;
using AetherixRomMaster.Core.Logging;
using AetherixRomMaster.Core.Profiles;
using AetherixRomMaster.Core.Rom;

namespace AetherixRomMaster.Core.Installation;

/// <summary>Mutable state threaded through one run of the install state machine.</summary>
public sealed class InstallContext
{
    public required InstallMode Mode { get; init; }
    public required DeviceProfile DeviceProfile { get; init; }
    public required RomProfile RomProfile { get; init; }
    public required InstallPlan Plan { get; init; }
    public required IInstallLogger Logger { get; init; }

    public required string RomZipPath { get; set; }
    public string? InitialInstallZipPath { get; set; }
    public string? ExplicitRomChecksum { get; set; }
    public string? ExplicitInitialInstallChecksum { get; set; }

    public int CurrentStateIndex { get; set; }
    public InstallState CurrentState => Plan.States[CurrentStateIndex];

    public DeviceInfo? Device { get; set; }
    public string? DeviceSerial { get; set; }

    public RomValidationResult? InitialInstallValidation { get; set; }
    public RomValidationResult? RomValidation { get; set; }
    public KernelSuCompatibilityAssessment? KernelSuAssessment { get; set; }

    public RootDetectionResult? PreInstallRoot { get; set; }
    public RootDetectionResult? PostInstallRoot { get; set; }
    public string? PreInstallBuildFingerprint { get; set; }
    public string? PostInstallBuildFingerprint { get; set; }

    public bool BackupAcknowledged { get; set; }
    public bool DataWipeConfirmed { get; set; }

    /// <summary>Set only by an explicit user action when this app's own detection (ADB
    /// props and/or the Fastboot round-trip) could not confirm Unlocked -- e.g. a device
    /// whose fastboot USB interface doesn't enumerate reliably on the user's PC. This is
    /// never set automatically, and VerifyBootloader always logs plainly when it is the
    /// reason a run proceeded, instead of presenting it as something the app verified.</summary>
    public bool BootloaderUserConfirmedUnlocked { get; set; }

    /// <summary>Set once InstallRom has actually issued "reboot to recovery" for this
    /// attempt. When InstallRom is re-entered after a manual-recovery-navigation
    /// confirmation, this prevents re-issuing the reboot command to a device that has
    /// already left fastboot mode (which would just fail).</summary>
    public bool RecoveryRebootIssuedForSideload { get; set; }

    /// <summary>Every StepResult this run has produced, in order, including the
    /// terminal one. Populated by InstallStateMachine.RunAsync as it yields each
    /// result -- this is what RecoveryPlanner uses to find the last state that
    /// actually succeeded before a failure, without guessing or re-deriving it.</summary>
    public List<StepResult> History { get; } = [];

    public StepResult? LastSuccessfulStep => History.LastOrDefault(r => r.Outcome == StepOutcome.Continue || r.Outcome == StepOutcome.Finished);

    public StepResult? LastFailedStep => History.LastOrDefault(r => r.Outcome == StepOutcome.Failed);

    public bool AdvanceToNextState()
    {
        if (CurrentStateIndex + 1 >= Plan.States.Count) return false;
        CurrentStateIndex++;
        return true;
    }
}
