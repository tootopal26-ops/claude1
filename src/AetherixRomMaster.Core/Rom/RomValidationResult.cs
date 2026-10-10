namespace AetherixRomMaster.Core.Rom;

public sealed class RomValidationResult
{
    public required RomPackage Package { get; init; }
    public required ChecksumCheckResult Checksum { get; init; }
    public required IReadOnlyList<RomCompatibilityIssue> Issues { get; init; }

    public bool HasBlockingIssues => Issues.Any(i => i.Severity == CompatibilityIssueSeverity.Blocking);
    // !IsGenuineMismatch (rather than Checksum.Matched) correctly covers the researched
    // Initial-Install exception too: when no official checksum exists for that specific
    // package type, Matched is false but IsGenuineMismatch is also false, and
    // HasBlockingIssues already independently stays strict for the ROM package in the
    // equivalent case -- see RomValidationService.
    public bool IsSafeToInstall => !HasBlockingIssues && !Checksum.IsGenuineMismatch;
}
