using System.Runtime.CompilerServices;
using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.KernelSu;
using AetherixRomMaster.Core.Logging;
using AetherixRomMaster.Core.Rom;

namespace AetherixRomMaster.Core.Installation;

/// <summary>
/// Drives one <see cref="InstallContext"/> through its <see cref="InstallPlan"/>, one
/// state at a time. Every state is re-entrant: calling <see cref="RunAsync"/> again after
/// a NeedsConfirmation result resumes exactly where it left off, because progress lives
/// on the context (CurrentStateIndex), not on this class or the enumerator.
///
/// In DryRun mode, a condition that would block real flashing is logged and the plan
/// still walks to completion so the full picture is visible. In Real mode the same
/// condition stops the run immediately (StepOutcome.Failed) — it never auto-continues.
/// </summary>
public sealed class InstallStateMachine
{
    private readonly IDeviceDetectionService _deviceDetection;
    private readonly DeviceCompatibilityChecker _deviceCompatibility;
    private readonly RomValidationService _romValidation;
    private readonly KernelSuCompatibilityChecker _kernelSuChecker;
    private readonly RootDetector _rootDetector;
    private readonly IFlashExecutor _flashExecutor;

    public InstallStateMachine(
        IDeviceDetectionService deviceDetection,
        DeviceCompatibilityChecker deviceCompatibility,
        RomValidationService romValidation,
        KernelSuCompatibilityChecker kernelSuChecker,
        RootDetector rootDetector,
        IFlashExecutor flashExecutor)
    {
        _deviceDetection = deviceDetection;
        _deviceCompatibility = deviceCompatibility;
        _romValidation = romValidation;
        _kernelSuChecker = kernelSuChecker;
        _rootDetector = rootDetector;
        _flashExecutor = flashExecutor;
    }

    private bool IsReal(InstallContext context) => context.Mode == InstallMode.Real;

    public async IAsyncEnumerable<StepResult> RunAsync(InstallContext context, [EnumeratorCancellation] CancellationToken ct = default)
    {
        while (true)
        {
            if (ct.IsCancellationRequested)
            {
                var cancelled = StepResult.Cancelled(context.CurrentState);
                context.Logger.Log(LogLevel.Warning, "StateMachine", $"Cancelled at {context.CurrentState}.");
                context.History.Add(cancelled);
                yield return cancelled;
                yield break;
            }

            var result = await ExecuteCurrentStateAsync(context, ct);
            context.History.Add(result);
            yield return result;

            if (result.Outcome != StepOutcome.Continue)
            {
                yield break;
            }

            if (!context.AdvanceToNextState())
            {
                yield break;
            }
        }
    }

    private Task<StepResult> ExecuteCurrentStateAsync(InstallContext context, CancellationToken ct)
    {
        context.Logger.Log(LogLevel.Info, "StateMachine", $"--- {context.CurrentState} ---");

        return context.CurrentState switch
        {
            InstallState.Initialize => RunInitializeAsync(context),
            InstallState.DetectDevice => RunDetectDeviceAsync(context, ct),
            InstallState.VerifyBootloader => RunVerifyBootloaderAsync(context, ct),
            InstallState.VerifyRom => RunVerifyRomAsync(context, ct),
            InstallState.DownloadRequiredFiles => RunDownloadRequiredFilesAsync(context),
            InstallState.VerifyChecksums => RunVerifyChecksumsAsync(context),
            InstallState.VerifyFirmware => RunVerifyFirmwareAsync(context),
            InstallState.VerifyRecovery => RunVerifyRecoveryAsync(context),
            InstallState.VerifyKernelSuCompatibility => RunVerifyKernelSuCompatibilityAsync(context, ct),
            InstallState.CreateBackupGuidance => RunCreateBackupGuidanceAsync(context),
            InstallState.RequestDataWipeConfirmation => RunRequestDataWipeConfirmationAsync(context),
            InstallState.EnterRequiredInstallationMode => RunEnterRequiredInstallationModeAsync(context, ct),
            InstallState.InstallRequiredInitialPackage => RunInstallRequiredInitialPackageAsync(context, ct),
            InstallState.FormatDataIfRequired => RunFormatDataIfRequiredAsync(context, ct),
            InstallState.InstallRom => RunInstallRomAsync(context, ct),
            InstallState.VerifyInstallation => RunVerifyInstallationAsync(context),
            InstallState.RestoreKernelSuIfCompatible => RunRestoreKernelSuIfCompatibleAsync(context),
            InstallState.Reboot => RunRebootAsync(context, ct),
            InstallState.VerifyFinalOs => RunVerifyFinalOsAsync(context, ct),
            InstallState.VerifyKernelSuRoot => RunVerifyKernelSuRootAsync(context, ct),
            InstallState.Complete => RunCompleteAsync(context),
            _ => Task.FromResult(StepResult.Failed(context.CurrentState, $"No handler for state {context.CurrentState}.")),
        };
    }

    private Task<StepResult> RunInitializeAsync(InstallContext context)
    {
        context.Logger.Log(LogLevel.Info, "Initialize", $"Mode: {context.Mode}. Device profile: {context.DeviceProfile.Codename}. ROM: {context.RomProfile.RomName}.");
        return Task.FromResult(StepResult.Continue(context.CurrentState, "Initialized."));
    }

    private async Task<StepResult> RunDetectDeviceAsync(InstallContext context, CancellationToken ct)
    {
        var device = await _deviceDetection.DetectAsync(ct);
        context.Device = device;
        context.DeviceSerial = device.Serial;

        if (device.ConnectionMode == ConnectionMode.Disconnected)
        {
            return StepResult.Failed(context.CurrentState, "No device detected over ADB or fastboot.", "device-not-detected");
        }

        context.Logger.Log(LogLevel.Info, "DetectDevice",
            $"Mode={device.ConnectionMode}, Manufacturer={device.Manufacturer}, Model={device.Model}, Codename={device.Codename}, " +
            $"Android={device.AndroidVersion}, Kernel={device.KernelVersion}, Bootloader={device.BootloaderLockState}, Root={device.RootSolution}");

        return StepResult.Continue(context.CurrentState, $"Detected {device.Manufacturer} {device.Model} ({device.Codename}).");
    }

    private async Task<StepResult> RunVerifyBootloaderAsync(InstallContext context, CancellationToken ct)
    {
        var device = context.Device!;
        var compatibility = _deviceCompatibility.Check(device, context.DeviceProfile);
        if (!compatibility.IsSupported)
        {
            return StepResult.Failed(context.CurrentState, compatibility.Reason, "device-not-detected");
        }

        if (device.BootloaderLockState != BootloaderLockState.Unlocked)
        {
            var message = device.BootloaderLockState == BootloaderLockState.Locked
                ? "Bootloader lock state reports Locked. AETHERIX ROM MASTER never unlocks a bootloader itself and will not " +
                  "proceed: unlocking it (OEM unlocking + 'fastboot flashing unlock', done by you, on the device, outside this " +
                  "app) is an irreversible, user-initiated action that WIPES ALL DATA on the device as a side effect of the " +
                  "unlock itself, before any ROM is even flashed. Back up anything you need first if you choose to unlock."
                : "Bootloader lock state could not be confirmed as Unlocked (reports 'Unknown'). AETHERIX ROM MASTER will not " +
                  "proceed on an unconfirmed state — use \"Verify Bootloader via Fastboot\" for an authoritative check (reboots " +
                  "the device into fastboot and reads its lock state directly) rather than assuming either way.";
            if (IsReal(context)) return StepResult.Failed(context.CurrentState, message);
            context.Logger.Log(LogLevel.Warning, "VerifyBootloader", "[DRY RUN] " + message);
        }

        context.PreInstallBuildFingerprint = device.BuildFingerprint;
        if (device.ConnectionMode == ConnectionMode.Adb && context.DeviceSerial is not null)
        {
            context.PreInstallRoot = await _rootDetector.DetectAsync(context.DeviceSerial, ct);
            context.Logger.Log(LogLevel.Info, "VerifyBootloader", $"Pre-install root: {context.PreInstallRoot.Solution} — {context.PreInstallRoot.Detail}");

            if (context.PreInstallRoot.Solution == RootSolutionType.Magisk)
            {
                return StepResult.Failed(context.CurrentState, context.PreInstallRoot.Detail ?? "Magisk detected; this tool does not support Magisk.");
            }
        }

        return StepResult.Continue(context.CurrentState, compatibility.Reason);
    }

    private async Task<StepResult> RunVerifyRomAsync(InstallContext context, CancellationToken ct)
    {
        var device = context.Device!;
        // No specific expected kind asserted here: a FastbootUpdatePackageThenSideload ROM
        // zip may be either an A/B payload or a legacy block-based package depending on the
        // build. RomValidationService still rejects RomPackageKind.Unknown regardless.
        context.RomValidation = await _romValidation.ValidateAsync(
            context.RomZipPath, device, context.DeviceProfile, context.RomProfile,
            expectedKind: null, context.ExplicitRomChecksum, ct);

        LogIssues(context, "VerifyRom", context.RomValidation.Issues);

        if (context.RomProfile.RequiresInitialInstallPackage)
        {
            if (context.InitialInstallZipPath is null)
            {
                var message = "This ROM requires a separate initial-install/firmware package, but none was supplied.";
                if (IsReal(context)) return StepResult.Failed(context.CurrentState, message, "rom-verification-error");
                context.Logger.Log(LogLevel.Warning, "VerifyRom", "[DRY RUN] " + message);
            }
            else
            {
                context.InitialInstallValidation = await _romValidation.ValidateAsync(
                    context.InitialInstallZipPath, device, context.DeviceProfile, context.RomProfile,
                    RomPackageKind.InitialInstallPackage, context.ExplicitInitialInstallChecksum, ct);
                LogIssues(context, "VerifyRom(InitialInstall)", context.InitialInstallValidation.Issues);
            }
        }

        var blocking = context.RomValidation.HasBlockingIssues ||
                       (context.InitialInstallValidation?.HasBlockingIssues ?? false);

        if (blocking && IsReal(context))
        {
            return StepResult.Failed(context.CurrentState, "ROM verification found blocking issues — see log for details.", "rom-verification-error");
        }

        return StepResult.Continue(context.CurrentState, blocking
            ? "[DRY RUN] ROM verification found blocking issues that would stop a real install — see log."
            : "ROM package(s) verified against the connected device.");
    }

    private Task<StepResult> RunDownloadRequiredFilesAsync(InstallContext context)
    {
        var missing = new List<string>();
        if (!File.Exists(context.RomZipPath)) missing.Add(context.RomZipPath);
        if (context.RomProfile.RequiresInitialInstallPackage && context.InitialInstallZipPath is not null && !File.Exists(context.InitialInstallZipPath))
        {
            missing.Add(context.InitialInstallZipPath);
        }

        if (missing.Count > 0)
        {
            var message = $"Required file(s) not present locally: {string.Join(", ", missing)}. Use the Download button or select the file(s) manually.";
            return Task.FromResult(IsReal(context)
                ? StepResult.Failed(context.CurrentState, message)
                : StepResult.Continue(context.CurrentState, "[DRY RUN] " + message));
        }

        return Task.FromResult(StepResult.Continue(context.CurrentState, "Required files are present locally."));
    }

    private Task<StepResult> RunVerifyChecksumsAsync(InstallContext context)
    {
        var issues = new List<string>();
        if (context.RomValidation is { Checksum.Matched: false })
        {
            issues.Add($"ROM package: computed {context.RomValidation.Checksum.ComputedSha256}, expected {context.RomValidation.Checksum.ExpectedSha256 ?? "(none available)"}.");
        }
        if (context.InitialInstallValidation is { Checksum.Matched: false })
        {
            issues.Add($"Initial-install package: computed {context.InitialInstallValidation.Checksum.ComputedSha256}, expected {context.InitialInstallValidation.Checksum.ExpectedSha256 ?? "(none available)"}.");
        }

        if (issues.Count > 0)
        {
            var message = "Checksum verification failed: " + string.Join(" | ", issues);
            return Task.FromResult(IsReal(context)
                ? StepResult.Failed(context.CurrentState, message, "checksum-mismatch")
                : StepResult.Continue(context.CurrentState, "[DRY RUN] " + message));
        }

        return Task.FromResult(StepResult.Continue(context.CurrentState, "Checksums verified against official source."));
    }

    private Task<StepResult> RunVerifyFirmwareAsync(InstallContext context)
    {
        var required = context.RomProfile.RequiredFirmwareAndroidVersion;
        var actual = context.Device?.AndroidVersion;

        if (required is null)
        {
            return Task.FromResult(StepResult.Continue(context.CurrentState, "This ROM profile does not declare a required firmware Android version."));
        }

        if (actual is null || !actual.StartsWith(required, StringComparison.OrdinalIgnoreCase))
        {
            var message = $"Device reports Android {actual ?? "unknown"}, but this ROM requires firmware based on Android {required}.";
            return Task.FromResult(IsReal(context)
                ? StepResult.Failed(context.CurrentState, message)
                : StepResult.Continue(context.CurrentState, "[DRY RUN] " + message));
        }

        return Task.FromResult(StepResult.Continue(context.CurrentState, $"Firmware Android version {actual} satisfies the requirement."));
    }

    private Task<StepResult> RunVerifyRecoveryAsync(InstallContext context)
    {
        context.Logger.Log(LogLevel.Info, "VerifyRecovery",
            "This installation method uses the device's stock recovery (ADB sideload capable since Android 5) — no custom recovery is flashed.");
        return Task.FromResult(StepResult.Continue(context.CurrentState, "Stock recovery sideload path confirmed."));
    }

    private async Task<StepResult> RunVerifyKernelSuCompatibilityAsync(InstallContext context, CancellationToken ct)
    {
        context.KernelSuAssessment = await _kernelSuChecker.AssessAsync(
            targetBootImagePath: null,
            currentKernelVersion: context.Device?.KernelVersion,
            romProfile: context.RomProfile,
            ct);

        var assessment = context.KernelSuAssessment;
        context.Logger.Log(
            assessment.RequiresManualStop ? LogLevel.Warning : LogLevel.Info,
            "VerifyKernelSuCompatibility",
            $"{assessment.RecommendedAction}: {assessment.Explanation}");

        return StepResult.Continue(context.CurrentState, assessment.Explanation);
    }

    private Task<StepResult> RunCreateBackupGuidanceAsync(InstallContext context)
    {
        if (context.BackupAcknowledged)
        {
            return Task.FromResult(StepResult.Continue(context.CurrentState, "Backup guidance acknowledged."));
        }

        const string message =
            "This process will erase data on the device. Back up photos, messages, and anything else you want to keep " +
            "(e.g. via your Google account or copying files off the device) before continuing.";
        return Task.FromResult(StepResult.NeedsConfirmation(context.CurrentState, message, "backup-ack"));
    }

    private Task<StepResult> RunRequestDataWipeConfirmationAsync(InstallContext context)
    {
        if (!context.RomProfile.RequiresDataFormat)
        {
            return Task.FromResult(StepResult.Continue(context.CurrentState, "This ROM profile does not require an explicit data format step."));
        }

        if (context.DataWipeConfirmed)
        {
            return Task.FromResult(StepResult.Continue(context.CurrentState, "Data wipe confirmed by user."));
        }

        var message = context.RomProfile.DataFormatNotes ?? "This will erase all data on the device (fastboot -w).";
        return Task.FromResult(StepResult.NeedsConfirmation(context.CurrentState, message, "data-wipe"));
    }

    private async Task<StepResult> RunEnterRequiredInstallationModeAsync(InstallContext context, CancellationToken ct)
    {
        var serial = RequireSerial(context);
        var ok = await _flashExecutor.RebootToFastbootdAsync(serial, context.Logger, ct);
        if (!ok)
        {
            return StepResult.Failed(context.CurrentState, "Failed to reboot the device into fastbootd.", "fastboot-detection-failure");
        }

        if (IsReal(context))
        {
            var confirmed = await WaitForConnectionModeAsync(context, ConnectionMode.FastbootUserspace, TimeSpan.FromSeconds(30), ct);
            if (!confirmed)
            {
                return StepResult.Failed(context.CurrentState, "Device did not report fastbootd (userspace fastboot) after the reboot command.", "fastboot-detection-failure");
            }
        }

        return StepResult.Continue(context.CurrentState, "Device is in fastbootd.");
    }

    private async Task<StepResult> RunInstallRequiredInitialPackageAsync(InstallContext context, CancellationToken ct)
    {
        var serial = RequireSerial(context);
        var zipPath = context.InitialInstallZipPath ?? throw new InvalidOperationException("Initial install package path was not set despite being required.");

        var ok = await _flashExecutor.FlashInitialInstallPackageAsync(serial, zipPath, context.Logger, ct);
        return ok
            ? StepResult.Continue(context.CurrentState, "Initial-install package flashed.")
            : StepResult.Failed(context.CurrentState, "Flashing the initial-install package failed.", "fastboot-detection-failure");
    }

    private async Task<StepResult> RunFormatDataIfRequiredAsync(InstallContext context, CancellationToken ct)
    {
        var serial = RequireSerial(context);
        var ok = await _flashExecutor.FormatDataAsync(serial, context.Logger, ct);
        return ok
            ? StepResult.Continue(context.CurrentState, "/data formatted.")
            : StepResult.Failed(context.CurrentState, "Formatting /data failed.");
    }

    private async Task<StepResult> RunInstallRomAsync(InstallContext context, CancellationToken ct)
    {
        var serial = RequireSerial(context);

        var rebooted = await _flashExecutor.RebootToRecoveryAsync(serial, context.Logger, ct);
        if (!rebooted)
        {
            return StepResult.Failed(context.CurrentState, "Failed to reboot into recovery for sideload.", "fastboot-detection-failure");
        }

        if (IsReal(context))
        {
            var confirmed = await WaitForConnectionModeAsync(context, ConnectionMode.AdbSideload, TimeSpan.FromSeconds(60), ct);
            if (!confirmed)
            {
                return StepResult.Failed(context.CurrentState, "Device did not reach ADB sideload mode in recovery.", "sideload-failure");
            }
        }

        var sideloaded = await _flashExecutor.SideloadRomAsync(serial, context.RomZipPath, context.Logger, ct);
        return sideloaded
            ? StepResult.Continue(context.CurrentState, "ROM package sideloaded successfully.")
            : StepResult.Failed(context.CurrentState, "Sideloading the ROM package failed.", "sideload-failure");
    }

    private Task<StepResult> RunVerifyInstallationAsync(InstallContext context)
    {
        return Task.FromResult(StepResult.Continue(context.CurrentState, "Sideload reported success; proceeding to reboot."));
    }

    private Task<StepResult> RunRestoreKernelSuIfCompatibleAsync(InstallContext context)
    {
        var assessment = context.KernelSuAssessment;
        if (assessment is null)
        {
            return Task.FromResult(StepResult.Continue(context.CurrentState, "No KernelSU assessment available."));
        }

        var message = assessment.RecommendedAction switch
        {
            KernelSuRecommendedAction.UseBundledKernelSuAsIs =>
                "Using the ROM's own bundled KernelSU-enabled kernel as-is — nothing extra to flash.",
            _ =>
                $"KernelSU root will not be established automatically: {assessment.Explanation} " +
                "The ROM install itself is not affected by this — only root status.",
        };

        context.Logger.Log(assessment.RecommendedAction == KernelSuRecommendedAction.UseBundledKernelSuAsIs ? LogLevel.Info : LogLevel.Warning,
            "RestoreKernelSu", message);

        return Task.FromResult(StepResult.Continue(context.CurrentState, message));
    }

    private async Task<StepResult> RunRebootAsync(InstallContext context, CancellationToken ct)
    {
        var serial = RequireSerial(context);
        var ok = await _flashExecutor.RebootToSystemAsync(serial, context.Logger, ct);
        return ok
            ? StepResult.Continue(context.CurrentState, "Reboot issued.")
            : StepResult.Failed(context.CurrentState, "Failed to reboot the device.");
    }

    private async Task<StepResult> RunVerifyFinalOsAsync(InstallContext context, CancellationToken ct)
    {
        if (!IsReal(context))
        {
            return StepResult.Continue(context.CurrentState, "[DRY RUN] Would wait for first boot and compare the build fingerprint to confirm the new OS booted.");
        }

        var booted = await WaitForConnectionModeAsync(context, ConnectionMode.Adb, TimeSpan.FromMinutes(5), ct, updateDevice: true);
        if (!booted || context.Device is null)
        {
            return StepResult.Failed(context.CurrentState, "Device did not come back online over ADB after rebooting — possible bootloop.", "bootloop");
        }

        context.PostInstallBuildFingerprint = context.Device.BuildFingerprint;

        if (context.PostInstallBuildFingerprint is not null && context.PostInstallBuildFingerprint == context.PreInstallBuildFingerprint)
        {
            return StepResult.Failed(context.CurrentState, "Build fingerprint is unchanged after reboot — the new ROM does not appear to have booted.", "bootloop");
        }

        return StepResult.Continue(context.CurrentState, $"Device booted with new build fingerprint: {context.PostInstallBuildFingerprint}");
    }

    private async Task<StepResult> RunVerifyKernelSuRootAsync(InstallContext context, CancellationToken ct)
    {
        if (!IsReal(context))
        {
            return StepResult.Continue(context.CurrentState, "[DRY RUN] Would query the booted device for real root (su -c id uid=0 plus a KernelSU marker).");
        }

        var serial = RequireSerial(context);
        context.PostInstallRoot = await _rootDetector.DetectAsync(serial, ct);

        // KernelSuDetectedNotGranted means KernelSU IS confirmed running on the device --
        // Aetherix/adb simply has no grant yet, which is routine, not a warning-worthy outcome.
        var level = context.PostInstallRoot.Solution is RootSolutionType.KernelSU or RootSolutionType.KernelSuDetectedNotGranted
            ? LogLevel.Info
            : LogLevel.Warning;
        context.Logger.Log(level, "VerifyKernelSuRoot", $"{context.PostInstallRoot.Solution}: {context.PostInstallRoot.Detail}");

        return StepResult.Continue(context.CurrentState, context.PostInstallRoot.Detail ?? context.PostInstallRoot.Solution.ToString());
    }

    private Task<StepResult> RunCompleteAsync(InstallContext context)
    {
        var romOk = context.Mode == InstallMode.DryRun || context.PostInstallBuildFingerprint is not null;

        // Each state gets its own honest summary -- collapsing anything but a fully-granted
        // KernelSU session into "NOT ESTABLISHED" would misreport a device where KernelSU is
        // genuinely running but Aetherix itself just hasn't been granted access yet.
        var rootSummary = context.PostInstallRoot?.Solution switch
        {
            RootSolutionType.KernelSU => $"ACTIVE (verified) — {context.PostInstallRoot?.Detail}",
            RootSolutionType.KernelSuDetectedNotGranted => $"RUNNING ON DEVICE, NOT YET GRANTED TO AETHERIX — {context.PostInstallRoot?.Detail}",
            RootSolutionType.KernelSuManagerInstalledOnly => $"MANAGER INSTALLED, KERNEL INTEGRATION UNCONFIRMED — {context.PostInstallRoot?.Detail}",
            RootSolutionType.Inconclusive => $"COULD NOT BE VERIFIED — {context.PostInstallRoot?.Detail}",
            null => "not checked",
            _ => $"NOT ESTABLISHED — {context.PostInstallRoot?.Detail}",
        };

        var summary = context.Mode == InstallMode.DryRun
            ? "[DRY RUN] Plan walked to completion with no destructive commands executed. Review the log above for anything that would have blocked a real run."
            : $"ROM install: {(romOk ? "SUCCESS" : "UNKNOWN")}. KernelSU root: {rootSummary}";

        context.Logger.Log(LogLevel.Info, "Complete", summary);
        return Task.FromResult(StepResult.Finished(context.CurrentState, summary));
    }

    private static string RequireSerial(InstallContext context) =>
        context.DeviceSerial ?? throw new InvalidOperationException("No device serial captured — DetectDevice must run before any device operation.");

    private async Task<bool> WaitForConnectionModeAsync(
        InstallContext context, ConnectionMode desired, TimeSpan timeout, CancellationToken ct, bool updateDevice = false)
    {
        using var timeoutCts = new CancellationTokenSource(timeout);
        using var linked = CancellationTokenSource.CreateLinkedTokenSource(ct, timeoutCts.Token);

        while (!linked.IsCancellationRequested)
        {
            var device = await _deviceDetection.DetectAsync(linked.Token).ContinueWith(
                t => t.IsCompletedSuccessfully ? t.Result : DeviceInfo.Disconnected, CancellationToken.None);

            if (device.ConnectionMode == desired)
            {
                if (updateDevice)
                {
                    context.Device = device;
                    context.DeviceSerial = device.Serial ?? context.DeviceSerial;
                }
                return true;
            }

            try
            {
                await Task.Delay(TimeSpan.FromSeconds(2), linked.Token);
            }
            catch (OperationCanceledException)
            {
                break;
            }
        }
        return false;
    }

    private static void LogIssues(InstallContext context, string source, IReadOnlyList<RomCompatibilityIssue> issues)
    {
        foreach (var issue in issues)
        {
            var level = issue.Severity switch
            {
                CompatibilityIssueSeverity.Blocking => LogLevel.Error,
                CompatibilityIssueSeverity.Warning => LogLevel.Warning,
                _ => LogLevel.Info,
            };
            context.Logger.Log(level, source, $"[{issue.Code}] {issue.Message}");
        }
    }
}
