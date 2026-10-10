using AetherixRomMaster.Core.Installation;

namespace AetherixRomMaster.Core.Recovery;

/// <summary>
/// One concrete, honest thing the user could do next. "CanBeExecutedByThisApp" is true
/// only for RetryWithVerifiedFiles -- that is the one recovery action this app actually
/// performs itself (by re-invoking the state machine on the same, unmodified context;
/// a Failed outcome never advances CurrentStateIndex, so the failed state simply runs
/// again). Every other option is advice the human must act on themselves.
/// </summary>
public sealed record RecoveryOption(
    RecoveryActionKind Kind,
    string Title,
    string Description,
    IReadOnlyList<string> Steps,
    bool CanBeExecutedByThisApp,
    InstallState? RetryState = null);
