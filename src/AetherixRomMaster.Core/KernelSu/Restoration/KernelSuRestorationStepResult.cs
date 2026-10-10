using AetherixRomMaster.Core.Installation;

namespace AetherixRomMaster.Core.KernelSu.Restoration;

/// <summary>Mirrors <see cref="Installation.StepResult"/>'s shape for the KernelSU
/// restoration workflow's own state enum -- same outcome model (Continue /
/// NeedsConfirmation / Failed / Finished), kept as a separate type because it carries
/// <see cref="KernelSuRestorationState"/> instead of <see cref="InstallState"/>.</summary>
public sealed record KernelSuRestorationStepResult(
    KernelSuRestorationState State,
    StepOutcome Outcome,
    string Message,
    string? ConfirmationKey = null)
{
    public static KernelSuRestorationStepResult Continue(KernelSuRestorationState state, string message) =>
        new(state, StepOutcome.Continue, message);

    public static KernelSuRestorationStepResult NeedsConfirmation(KernelSuRestorationState state, string message, string confirmationKey) =>
        new(state, StepOutcome.NeedsConfirmation, message, confirmationKey);

    public static KernelSuRestorationStepResult Blocked(string message) =>
        new(KernelSuRestorationState.Blocked, StepOutcome.Failed, message);

    public static KernelSuRestorationStepResult Finished(KernelSuRestorationState state, string message) =>
        new(state, StepOutcome.Finished, message);
}
