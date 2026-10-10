namespace AetherixRomMaster.Core.Installation;

/// <summary>One row of an automation-capability report: a named pipeline step, how much
/// of it Aetherix can drive itself right now, and why.</summary>
public sealed record AutomationCapabilityItem(string StepName, AutomationCapabilityLevel Level, string Explanation)
{
    /// <summary>A terse, Dry-Run-style marker for display: a checkmark for fully
    /// automatic, a warning glyph for anything that needs a person.</summary>
    public string Marker => Level == AutomationCapabilityLevel.Automatic ? "✓" : "⚠";
}
