namespace AetherixRomMaster.Core.Rom;

public enum CompatibilityIssueSeverity
{
    Info,
    Warning,

    /// <summary>Installation must not proceed while any Blocking issue is present.</summary>
    Blocking
}
