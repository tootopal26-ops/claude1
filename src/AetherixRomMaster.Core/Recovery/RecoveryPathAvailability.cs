namespace AetherixRomMaster.Core.Recovery;

public enum RecoveryPathAvailability
{
    /// <summary>A documented, verified, safe action exists (at minimum: reconnect and
    /// retry with the same already-checksummed files). Never means "guaranteed to fix
    /// it" -- only "there is something safe and specific to try".</summary>
    Available,

    /// <summary>No documented/verified recovery action exists for this failure. The app
    /// stops here rather than guessing a partition name, image, or command -- manual or
    /// service recovery may be required. This is the only honest answer for a possible
    /// hard-brick, and this app never claims otherwise.</summary>
    NotAvailable
}
