namespace AetherixRomMaster.Core.Installation;

/// <summary>One concrete action the plan will take — what the dry run lists, and what
/// the real engine executes one at a time, in the same order.</summary>
public sealed record InstallOperation(InstallState State, string Description, bool IsDestructive, string? Command = null);
