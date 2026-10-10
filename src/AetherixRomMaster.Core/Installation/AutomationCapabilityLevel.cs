namespace AetherixRomMaster.Core.Installation;

/// <summary>How much of a given installation-pipeline step Aetherix can actually drive
/// itself, for a specific device/ROM/profile combination -- never a fixed, hardcoded
/// claim, since the same step can be Automatic for one device/recovery and
/// ManualInteractionRequired for another.</summary>
public enum AutomationCapabilityLevel
{
    /// <summary>Aetherix performs this step itself via a documented ADB/Fastboot command,
    /// with no user action beyond having started the run.</summary>
    Automatic,

    /// <summary>Aetherix can perform the operation itself, but a human must explicitly
    /// approve it first (e.g. a destructive data-wipe, or installing a patched image).</summary>
    UserConfirmationRequired,

    /// <summary>Aetherix cannot drive this step itself -- a human must act on the device
    /// (recovery menu navigation, KernelSU Manager's own UI) and tell Aetherix when done.
    /// Aetherix never simulates touches/clicks to work around this.</summary>
    ManualInteractionRequired,

    /// <summary>This step cannot proceed at all yet -- something required (verification
    /// status, a profile field, device/build match) is missing.</summary>
    Blocked,
}
