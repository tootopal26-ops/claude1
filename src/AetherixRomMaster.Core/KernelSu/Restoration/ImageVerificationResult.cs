namespace AetherixRomMaster.Core.KernelSu.Restoration;

/// <summary>Result of validating a candidate image file (original or patched) before it
/// is trusted for transfer or flashing. A non-empty Issues list always means IsValid is
/// false -- callers never need to check both independently.</summary>
public sealed record ImageVerificationResult(bool IsValid, IReadOnlyList<string> Issues)
{
    public static ImageVerificationResult Valid() => new(true, []);

    public static ImageVerificationResult Invalid(params string[] issues) => new(false, issues);
}
