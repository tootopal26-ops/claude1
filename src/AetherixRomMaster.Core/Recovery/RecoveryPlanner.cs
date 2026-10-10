using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.Diagnostics;
using AetherixRomMaster.Core.Installation;

namespace AetherixRomMaster.Core.Recovery;

/// <summary>
/// Turns a failure into an honest answer: either a specific, verified, safe thing to
/// try (always re-using files already checksummed in this session -- never a new image,
/// partition, or command), or an explicit "no verified path, stop" when none exists.
/// Every "Available" verdict is backed by a fresh live device query made while building
/// the session, never by an assumption carried over from before the failure. This class
/// never claims a hard-brick is automatically recoverable.
/// </summary>
public sealed class RecoveryPlanner
{
    private static readonly HashSet<string> ConnectivityRetryableKeys = new(StringComparer.Ordinal)
    {
        "device-not-detected", "adb-disconnection", "fastboot-detection-failure", "sideload-failure",
    };

    private readonly IDeviceDetectionService _deviceDetection;

    public RecoveryPlanner(IDeviceDetectionService deviceDetection)
    {
        _deviceDetection = deviceDetection;
    }

    /// <summary>Reactive: builds a full recovery session for the most recent failure in
    /// context.History. Requires a Failed step to already be recorded.</summary>
    public async Task<RecoverySession> BuildSessionAsync(InstallContext context, CancellationToken ct = default)
    {
        var failed = context.LastFailedStep
            ?? throw new InvalidOperationException("RecoveryPlanner.BuildSessionAsync requires a Failed step in InstallContext.History.");
        var lastSuccess = context.LastSuccessfulStep;

        var currentDevice = await _deviceDetection.DetectAsync(ct);
        var requiredFiles = BuildRequiredFiles(context);
        var (availability, explanation, options) = Classify(failed, currentDevice, requiredFiles);

        return new RecoverySession
        {
            CreatedAt = DateTimeOffset.Now,
            FailedState = failed.State,
            FailureMessage = failed.Message,
            RecoveryGuidanceKey = failed.RecoveryGuidanceKey,
            LastSuccessfulState = lastSuccess?.State,
            CurrentDeviceMode = currentDevice.ConnectionMode,
            CurrentDeviceSnapshot = currentDevice,
            PathAvailability = availability,
            AvailabilityExplanation = explanation,
            Options = options,
            RequiredFiles = requiredFiles,
            SessionLogPath = context.Logger.LogFilePath,
        };
    }

    /// <summary>Prospective: used by the Pre-Flash Safety Check, before anything has
    /// failed (or even started). Confirms the preconditions a retry would need --
    /// it is explicitly NOT a promise that every possible failure, including a
    /// hard-brick mid-flash, would be recoverable.</summary>
    public async Task<(bool Ready, string Explanation)> AssessRetryReadinessAsync(InstallContext context, CancellationToken ct = default)
    {
        var device = await _deviceDetection.DetectAsync(ct);
        var files = BuildRequiredFiles(context);
        var filesVerified = files.Count > 0 && files.All(f => f.ChecksumVerified);
        var deviceReachable = device.ConnectionMode != ConnectionMode.Disconnected;

        if (deviceReachable && filesVerified)
        {
            return (true,
                $"Device is reachable ({device.ConnectionMode}) and the selected file(s) are checksum-verified, so a safe retry " +
                "path would exist if an early- or mid-plan step failed. This does not guarantee recovery from every possible " +
                "failure -- a hard-brick during a partition write is never guaranteed recoverable by this app.");
        }

        if (!deviceReachable)
        {
            return (false, "No device is currently detected, so a retry path cannot be confirmed right now.");
        }

        return (false, "Not all selected files are checksum-verified yet, so a safe retry path cannot be confirmed yet.");
    }

    private static IReadOnlyList<RecoveryRequiredFile> BuildRequiredFiles(InstallContext context)
    {
        var files = new List<RecoveryRequiredFile>();
        if (context.RomValidation is not null)
        {
            files.Add(new RecoveryRequiredFile(
                "ROM package", context.RomZipPath, context.RomValidation.Checksum.Matched, context.RomValidation.Checksum.ComputedSha256));
        }
        if (context.InitialInstallZipPath is not null && context.InitialInstallValidation is not null)
        {
            files.Add(new RecoveryRequiredFile(
                "Initial-install package", context.InitialInstallZipPath, context.InitialInstallValidation.Checksum.Matched, context.InitialInstallValidation.Checksum.ComputedSha256));
        }
        return files;
    }

    private (RecoveryPathAvailability Availability, string Explanation, IReadOnlyList<RecoveryOption> Options) Classify(
        StepResult failed, DeviceInfo currentDevice, IReadOnlyList<RecoveryRequiredFile> requiredFiles)
    {
        var key = failed.RecoveryGuidanceKey;
        var deviceReachable = currentDevice.ConnectionMode != ConnectionMode.Disconnected;
        var allFilesVerified = requiredFiles.Count > 0 && requiredFiles.All(f => f.ChecksumVerified);

        if (key is not null && ConnectivityRetryableKeys.Contains(key))
        {
            if (deviceReachable && allFilesVerified)
            {
                return (RecoveryPathAvailability.Available,
                    $"Device is reachable ({currentDevice.ConnectionMode}) and the file(s) involved are already verified -- safe to retry the failed step as-is.",
                    [RetryOption(failed.State,
                        "Retry the failed step",
                        "Re-runs the exact same step using the file(s) already verified in this session. No new file, image, or command is introduced.")]);
            }

            return (RecoveryPathAvailability.Available,
                "No device is currently detected. This is a connectivity issue, not a flash-state issue -- reconnect, then retry is expected to work.",
                [new RecoveryOption(
                    RecoveryActionKind.NonDestructiveGuidanceOnly,
                    "Reconnect and retry",
                    "The device is not currently reachable over USB.",
                    GuidanceSteps(key),
                    CanBeExecutedByThisApp: false)]);
        }

        if (key is "checksum-mismatch" or "rom-verification-error")
        {
            return (RecoveryPathAvailability.Available,
                "The file itself failed verification before any device was touched. Re-downloading and re-verifying is the safe next step -- the old file must not be reused.",
                [new RecoveryOption(
                    RecoveryActionKind.NonDestructiveGuidanceOnly,
                    "Re-download and re-verify",
                    "The selected file did not match its official checksum, or could not be classified as a valid package.",
                    GuidanceSteps(key),
                    CanBeExecutedByThisApp: false)]);
        }

        if (key == "kernelsu-installation-failure")
        {
            return (RecoveryPathAvailability.Available,
                "Not a device-risk failure -- the ROM itself installed; only KernelSU root was not established.",
                [new RecoveryOption(
                    RecoveryActionKind.NonDestructiveGuidanceOnly,
                    "ROM install is unaffected",
                    "Root status only -- no OS-level recovery is needed.",
                    GuidanceSteps(key),
                    CanBeExecutedByThisApp: false)]);
        }

        if (key == "bootloop")
        {
            if (deviceReachable && (currentDevice.FastbootConnected || currentDevice.AdbConnected) && allFilesVerified)
            {
                return (RecoveryPathAvailability.Available,
                    $"Device still responds right now ({currentDevice.ConnectionMode}) -- a verified retry path exists.",
                    [RetryOption(failed.State,
                        "Re-attempt the failed flashing step",
                        "Re-flashes only the already-verified file(s) already used in this session via the device's current fastboot/ADB session -- no new or different image.")]);
            }

            return (RecoveryPathAvailability.NotAvailable,
                "The device is not currently responding over ADB or fastboot. This app has no verified recovery image, partition " +
                "layout, or command sequence for this exact failure mode and will not guess one. Manual or Motorola/authorized-" +
                "service recovery may be required -- consult the official cancunf community resources cited in this app's device " +
                "profile (profiles/cancunf.device.json) before attempting any further commands yourself.",
                []);
        }

        return (RecoveryPathAvailability.NotAvailable,
            "This failure was not recognized as a documented, verified recovery scenario. Stopping rather than guessing a command.",
            []);
    }

    private static RecoveryOption RetryOption(InstallState state, string title, string description) =>
        new(RecoveryActionKind.RetryWithVerifiedFiles, title, description,
            [$"Re-run the {state} step using only the file(s) already verified in this session.", "No new file, image, or command will be introduced."],
            CanBeExecutedByThisApp: true, RetryState: state);

    private static IReadOnlyList<string> GuidanceSteps(string? key)
        => RecoveryGuidance.Lookup(key)?.Steps ?? [];
}
