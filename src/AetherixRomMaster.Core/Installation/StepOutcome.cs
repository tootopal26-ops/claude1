namespace AetherixRomMaster.Core.Installation;

public enum StepOutcome
{
    /// <summary>Step succeeded; advance to the next state in the plan.</summary>
    Continue,

    /// <summary>Step cannot proceed until the user confirms something (see
    /// StepResult.ConfirmationKey). Calling ExecuteNextAsync again re-evaluates the
    /// same state, so confirming and re-calling is how the UI resumes.</summary>
    NeedsConfirmation,

    /// <summary>Unexpected error. Terminal — the installer never auto-continues from here.</summary>
    Failed,

    Cancelled,

    /// <summary>The plan's last state (COMPLETE) finished.</summary>
    Finished
}
