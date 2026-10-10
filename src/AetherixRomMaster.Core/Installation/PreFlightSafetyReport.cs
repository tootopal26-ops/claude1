namespace AetherixRomMaster.Core.Installation;

public sealed class PreFlightSafetyReport
{
    public required IReadOnlyList<PreFlightCheckItem> Items { get; init; }

    /// <summary>True only when every blocking item passed. This is the actual gate for
    /// enabling real (non-dry-run) flashing.</summary>
    public bool CanProceed => Items.Where(i => i.IsBlocking).All(i => i.Passed);

    /// <summary>True only when every item passed, blocking or not -- a fully clean bill
    /// of health with no caveats at all.</summary>
    public bool AllPassed => Items.All(i => i.Passed);
}
