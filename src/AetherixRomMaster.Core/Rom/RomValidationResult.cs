namespace AetherixRomMaster.Core.Rom;

public sealed class RomValidationResult
{
    public required RomPackage Package { get; init; }
    public required ChecksumCheckResult Checksum { get; init; }
    public required IReadOnlyList<RomCompatibilityIssue> Issues { get; init; }

    public bool HasBlockingIssues => Issues.Any(i => i.Severity == CompatibilityIssueSeverity.Blocking);
    public bool IsSafeToInstall => !HasBlockingIssues && Checksum.Matched;
}
