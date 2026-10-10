namespace AetherixRomMaster.Core.Installation;

/// <summary>
/// The specific, ROM-dependent sequence of states and operations this run will execute.
/// Built once up front (InstallPlanBuilder) from what the selected ROM profile actually
/// requires — never a fixed universal sequence.
/// </summary>
public sealed class InstallPlan
{
    public required IReadOnlyList<InstallState> States { get; init; }
    public required IReadOnlyList<InstallOperation> Operations { get; init; }

    public bool Includes(InstallState state) => States.Contains(state);
}
