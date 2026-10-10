namespace AetherixRomMaster.Core.KernelSu.Restoration;

/// <summary>What, exactly, restoration would need to do -- or why it can't proceed yet.
/// Built once per session by <see cref="KernelSuRestorationPlanner"/> and never guessed
/// field-by-field; if any required fact is missing, the whole plan is Blocked rather than
/// partially filled in.</summary>
public sealed record KernelSuRestorationPlan(
    bool CanProceed,
    string? BlockingReason,
    KernelImageType? RequiredImageType,
    string? RequiredImageFileName,
    string? TargetPartition)
{
    public static KernelSuRestorationPlan Blocked(string reason) => new(false, reason, null, null, null);
}
