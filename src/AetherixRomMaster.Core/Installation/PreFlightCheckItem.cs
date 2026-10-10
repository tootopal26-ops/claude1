namespace AetherixRomMaster.Core.Installation;

/// <summary>One line of the Pre-Flash Safety Check. IsBlocking=false means this item is
/// shown honestly but does not by itself prevent clicking Install -- used only for the
/// KernelSU item, since an unconfirmed KernelSU path affects root, not device safety,
/// and the task's own design never makes root status a reason to withhold the ROM
/// install itself (see RunRestoreKernelSuIfCompatibleAsync).</summary>
public sealed record PreFlightCheckItem(string Name, bool Passed, bool IsBlocking, string Detail);
