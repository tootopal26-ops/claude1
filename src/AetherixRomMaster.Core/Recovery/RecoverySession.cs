using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.Installation;

namespace AetherixRomMaster.Core.Recovery;

/// <summary>
/// A complete, honest snapshot of "what happened, what we can see right now, and what
/// (if anything) is safe to try next" -- built once per failure by RecoveryPlanner.
/// Never asserts a path is Available without having actually re-queried the device for
/// its current reachability first.
/// </summary>
public sealed class RecoverySession
{
    public required DateTimeOffset CreatedAt { get; init; }

    public required InstallState FailedState { get; init; }
    public required string FailureMessage { get; init; }
    public string? RecoveryGuidanceKey { get; init; }

    public InstallState? LastSuccessfulState { get; init; }

    /// <summary>The device's connection mode as freshly re-detected while building this
    /// session -- not the stale value from before the failure.</summary>
    public required ConnectionMode CurrentDeviceMode { get; init; }
    public DeviceInfo? CurrentDeviceSnapshot { get; init; }

    public required RecoveryPathAvailability PathAvailability { get; init; }
    public required string AvailabilityExplanation { get; init; }

    public required IReadOnlyList<RecoveryOption> Options { get; init; }
    public required IReadOnlyList<RecoveryRequiredFile> RequiredFiles { get; init; }

    public string? SessionLogPath { get; init; }
}
