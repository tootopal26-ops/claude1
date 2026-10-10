namespace AetherixRomMaster.Core.Rom;

public sealed record RomCompatibilityIssue(CompatibilityIssueSeverity Severity, string Code, string Message);
