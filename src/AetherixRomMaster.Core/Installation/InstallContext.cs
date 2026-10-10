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

    public bool AdvanceToNextState()
    {
        if (CurrentStateIndex + 1 >= Plan.States.Count) return false;
        CurrentStateIndex++;
        return true;
    }
}
