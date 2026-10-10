namespace AetherixRomMaster.Core.Installation;

public sealed record StepResult(
    InstallState State,
    StepOutcome Outcome,
    string Message,
    string? ConfirmationKey = null,
    string? RecoveryGuidanceKey = null)
{
    public static StepResult Continue(InstallState state, string message) => new(state, StepOutcome.Continue, message);

    public static StepResult NeedsConfirmation(InstallState state, string message, string confirmationKey) =>
        new(state, StepOutcome.NeedsConfirmation, message, confirmationKey);

    public static StepResult Failed(InstallState state, string message, string? recoveryGuidanceKey = null) =>
        new(state, StepOutcome.Failed, message, RecoveryGuidanceKey: recoveryGuidanceKey);

    public static StepResult Cancelled(InstallState state) => new(state, StepOutcome.Cancelled, "Cancelled by user.");

    public static StepResult Finished(InstallState state, string message) => new(state, StepOutcome.Finished, message);
}
