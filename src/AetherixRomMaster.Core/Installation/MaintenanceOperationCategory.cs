namespace AetherixRomMaster.Core.Installation;

/// <summary>How risky a standalone Advanced Maintenance operation is, decided per
/// device/ROM-profile combination -- never a fixed label independent of what's actually
/// verified for the connected device.</summary>
public enum MaintenanceOperationCategory
{
    /// <summary>Does not modify any partition or user data -- a plain reboot target.</summary>
    SafeNonDestructive,

    /// <summary>Modifies device state (erases data, installs a package). Always requires
    /// an explicit confirmation showing exactly what will happen before running.</summary>
    Destructive,

    /// <summary>Not enabled: no verified source confirms this operation is valid, needed,
    /// or safe for this exact device. Never run merely because another ROM tool uses it.</summary>
    UnsupportedUnverified,
}
