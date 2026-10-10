namespace AetherixRomMaster.Core.Installation;

/// <summary>Evidence-based outcome of one Pre-Flash Safety Check item. UnableToVerify is
/// deliberately distinct from Blocked: it means this app could not obtain evidence either
/// way (e.g. a Fastboot query that never completed), not that it found a genuine problem
/// (e.g. a positively-detected locked bootloader). Both still withhold Install when
/// IsBlocking is true, but the UI and logs must never describe one as if it were the
/// other.</summary>
public enum PreFlightCheckStatus
{
    Pass,
    Blocked,
    UnableToVerify,
}

/// <summary>One line of the Pre-Flash Safety Check. IsBlocking=false means this item is
/// shown honestly but does not by itself prevent clicking Install -- used only for the
/// KernelSU item, since an unconfirmed KernelSU path affects root, not device safety,
/// and the task's own design never makes root status a reason to withhold the ROM
/// install itself (see RunRestoreKernelSuIfCompatibleAsync).</summary>
public sealed record PreFlightCheckItem(string Name, PreFlightCheckStatus Status, bool IsBlocking, string Detail)
{
    public bool Passed => Status == PreFlightCheckStatus.Pass;
}
