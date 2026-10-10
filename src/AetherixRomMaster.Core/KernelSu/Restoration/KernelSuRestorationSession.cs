using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.Logging;
using AetherixRomMaster.Core.Profiles;
using AetherixRomMaster.Core.Rom;

namespace AetherixRomMaster.Core.KernelSu.Restoration;

/// <summary>
/// Drives the "Restore KernelSU Root" workflow for one connected device: checks whether
/// KernelSU is already integrated and operational (reusing the same RootDetector the main
/// installer and device-status panel use, never a separate/looser check), and if not,
/// walks through preparing an original image, waiting for the user to patch it with
/// KernelSU Manager, verifying what comes back, and only then flashing it to a verified
/// partition. Every step that would touch the device for real is skipped (and logged as
/// skipped) when <see cref="IsDryRun"/> is true. A Blocked result is terminal -- callers
/// must start a new session rather than call another step method after one.
/// </summary>
public sealed class KernelSuRestorationSession
{
    private static readonly TimeSpan DefaultTransferTimeout = TimeSpan.FromMinutes(5);
    private static readonly TimeSpan DefaultFastbootWaitTimeout = TimeSpan.FromSeconds(30);
    private static readonly TimeSpan DefaultAdbWaitTimeout = TimeSpan.FromMinutes(3);

    private readonly AdbClient _adb;
    private readonly FastbootClient _fastboot;
    private readonly RootDetector _rootDetector;
    private readonly ChecksumVerifier _checksum = new();
    private readonly KernelSuRestorationPlanner _planner = new();
    private readonly IInstallLogger _logger;
    private readonly TimeSpan _transferTimeout;
    private readonly TimeSpan _fastbootWaitTimeout;
    private readonly TimeSpan _adbWaitTimeout;

    public bool IsDryRun { get; }
    public KernelSuRestorationState CurrentState { get; private set; } = KernelSuRestorationState.CheckingKernelSu;
    public KernelSuRestorationPlan? Plan { get; private set; }
    public RootDetectionResult? LatestRootStatus { get; private set; }
    public ImageArtifact? OriginalImage { get; private set; }
    public ImageArtifact? PatchedImage { get; private set; }
    public string? DeviceSerial { get; private set; }

    public KernelSuRestorationSession(
        AdbClient adb, FastbootClient fastboot, RootDetector rootDetector, IInstallLogger logger, bool isDryRun,
        TimeSpan? transferTimeout = null, TimeSpan? fastbootWaitTimeout = null, TimeSpan? adbWaitTimeout = null)
    {
        _transferTimeout = transferTimeout ?? DefaultTransferTimeout;
        _fastbootWaitTimeout = fastbootWaitTimeout ?? DefaultFastbootWaitTimeout;
        _adbWaitTimeout = adbWaitTimeout ?? DefaultAdbWaitTimeout;
        _adb = adb;
        _fastboot = fastboot;
        _rootDetector = rootDetector;
        _logger = logger;
        IsDryRun = isDryRun;
    }

    private void Log(LogLevel level, string message) => _logger.Log(level, "KernelSuRestoration", message);

    /// <summary>Step 1 (section 3-4 of the spec): check current KernelSU status via the
    /// real device, and build a plan if restoration turns out to be needed. Reusing
    /// RootDetector here is deliberate -- it already distinguishes "not granted" from
    /// "not detected" from "inconclusive", so this never collapses those into "unrooted".</summary>
    public async Task<KernelSuRestorationStepResult> CheckAsync(
        DeviceInfo device, RomProfile romProfile, KernelSuProfile? kernelSuProfile, CancellationToken ct = default)
    {
        CurrentState = KernelSuRestorationState.CheckingKernelSu;
        DeviceSerial = device.Serial;
        Log(LogLevel.Info, "Checking current KernelSU status...");

        if (device.Serial is null)
        {
            return Fail("No connected device (no ADB serial) -- cannot check KernelSU status.");
        }

        LatestRootStatus = await _rootDetector.DetectAsync(device.Serial, ct);
        Log(LogLevel.Info, $"Root detection result: {LatestRootStatus.Solution} -- {LatestRootStatus.Detail}");

        if (LatestRootStatus.Solution == RootSolutionType.KernelSU)
        {
            CurrentState = KernelSuRestorationState.AlreadyIntegrated;
            return KernelSuRestorationStepResult.Finished(
                CurrentState, $"KernelSU is already integrated and operational -- no restoration needed. {LatestRootStatus.Detail}");
        }

        if (LatestRootStatus.Solution == RootSolutionType.Inconclusive)
        {
            // Proceeding to patch-and-flash on an inconclusive read risks overwriting a
            // KernelSU setup that may already be working -- restoration needs a confirmed
            // "not integrated" result first, not just "couldn't tell".
            return Fail($"KernelSU status is inconclusive, not confirmed either way: {LatestRootStatus.Detail} Resolve this before starting restoration.");
        }

        Plan = _planner.BuildPlan(device, romProfile, kernelSuProfile);
        if (!Plan.CanProceed)
        {
            return Fail(Plan.BlockingReason!);
        }

        CurrentState = KernelSuRestorationState.RestorationRequired;
        return KernelSuRestorationStepResult.Continue(
            CurrentState,
            $"KernelSU restoration required (current state: {LatestRootStatus.Solution}). Required image: " +
            $"'{Plan.RequiredImageFileName}' ({Plan.RequiredImageType}) -> target partition '{Plan.TargetPartition}'.");
    }

    /// <summary>Section 5: hash and transfer the user-supplied original image, then hand
    /// off to the user to patch it in KernelSU Manager. Aetherix never extracts this image
    /// from inside a ROM/firmware archive itself -- the user selects the file, exactly like
    /// the existing ROM ZIP / Initial Install ZIP selection already works.</summary>
    public async Task<KernelSuRestorationStepResult> PrepareAndTransferImageAsync(string originalImagePath, CancellationToken ct = default)
    {
        CurrentState = KernelSuRestorationState.PreparingImage;
        if (Plan is not { CanProceed: true }) return Fail("No valid restoration plan -- call CheckAsync first.");
        if (DeviceSerial is null) return Fail("No connected device.");

        if (!File.Exists(originalImagePath))
        {
            return Fail($"Selected original image file does not exist: {originalImagePath}");
        }

        var actualName = Path.GetFileName(originalImagePath);
        if (!actualName.Equals(Plan.RequiredImageFileName, StringComparison.OrdinalIgnoreCase))
        {
            return Fail(
                $"Selected file '{actualName}' does not match the required image for this device/ROM/build: " +
                $"'{Plan.RequiredImageFileName}' ({Plan.RequiredImageType}). Flashing the wrong image type (e.g. " +
                "boot.img when init_boot.img is required, or vice versa) can leave the device without a working " +
                "root grant or fail to boot -- select the correct file and retry.");
        }

        OriginalImage = await ImageArtifact.FromFileAsync(originalImagePath, Plan.RequiredImageType, _checksum, ct);
        Log(LogLevel.Info, $"Original image recorded: {originalImagePath} sha256={OriginalImage.Sha256} size={OriginalImage.SizeBytes}B");

        var remotePath = $"/sdcard/Download/{actualName}";
        CurrentState = KernelSuRestorationState.TransferringImageToDevice;

        if (IsDryRun)
        {
            Log(LogLevel.Info, $"[DRY RUN] Would adb push '{originalImagePath}' to '{remotePath}' on the device.");
        }
        else
        {
            var pushResult = await _adb.PushAsync(DeviceSerial, originalImagePath, remotePath, _transferTimeout, ct);
            if (!pushResult.Succeeded)
            {
                return Fail($"Failed to transfer the image to the device: {pushResult.StandardError}");
            }
        }

        CurrentState = KernelSuRestorationState.WaitingForKernelSuManager;
        return KernelSuRestorationStepResult.NeedsConfirmation(
            CurrentState,
            "KernelSU patching is required. On the phone: 1) Open KernelSU Manager. 2) Select Install → Patch Image. " +
            $"3) Select \"{actualName}\" (transferred to {remotePath}). 4) Let KernelSU Manager patch it. " +
            "5) Do not manually flash anything unless Aetherix explicitly instructs you to do so. " +
            "6) Return to Aetherix after patching is complete.",
            "kernelsu-manager-patch-complete");
    }

    /// <summary>Section 6-7: validate whatever file the user points at as the patched
    /// image. Never assumes KernelSU Manager's output filename -- the user supplies the
    /// path (e.g. after adb-pulling it themselves, or Aetherix's UI lists /sdcard/Download
    /// via AdbClient.ListFilesAsync and the user picks from that).</summary>
    public async Task<KernelSuRestorationStepResult> RetrieveAndVerifyPatchedImageAsync(string patchedImageLocalPath, CancellationToken ct = default)
    {
        CurrentState = KernelSuRestorationState.VerifyingPatchedImage;
        if (Plan is not { CanProceed: true }) return Fail("No valid restoration plan.");

        var validation = ValidateCandidateFile(patchedImageLocalPath);
        if (!validation.IsValid)
        {
            return Fail($"Patched image failed validation: {string.Join("; ", validation.Issues)}");
        }

        PatchedImage = await ImageArtifact.FromFileAsync(patchedImageLocalPath, Plan.RequiredImageType, _checksum, ct);
        Log(LogLevel.Info, $"Patched image recorded: {patchedImageLocalPath} sha256={PatchedImage.Sha256} size={PatchedImage.SizeBytes}B");

        if (OriginalImage is not null && string.Equals(PatchedImage.Sha256, OriginalImage.Sha256, StringComparison.Ordinal))
        {
            return Fail("The selected patched image has the exact same SHA-256 as the original image -- it does not look like KernelSU Manager actually patched it. Re-check its output in KernelSU Manager before retrying.");
        }

        CurrentState = KernelSuRestorationState.PatchedImageVerified;
        return KernelSuRestorationStepResult.Continue(
            CurrentState,
            $"Patched image verified: {Path.GetFileName(patchedImageLocalPath)} (sha256={PatchedImage.Sha256}, {PatchedImage.SizeBytes} bytes).");
    }

    private static ImageVerificationResult ValidateCandidateFile(string path)
    {
        if (!File.Exists(path))
        {
            return ImageVerificationResult.Invalid($"File does not exist: {path}");
        }

        FileInfo info;
        try
        {
            info = new FileInfo(path);
            using var probe = File.OpenRead(path);
        }
        catch (Exception ex)
        {
            return ImageVerificationResult.Invalid($"File is not readable: {ex.Message}");
        }

        // A real boot/init_boot image is realistically several MB or more; anything under
        // ~1MB is not a plausible kernel+ramdisk image and is rejected rather than trusted.
        return info.Length >= 1_000_000
            ? ImageVerificationResult.Valid()
            : ImageVerificationResult.Invalid($"File size ({info.Length} bytes) is too small to plausibly be a boot-related image.");
    }

    /// <summary>Section 7's required safety summary and explicit confirmation text,
    /// verbatim. Refuses to build a summary -- and therefore never reaches the
    /// confirmation prompt -- unless every listed value is actually known.</summary>
    public KernelSuRestorationStepResult BuildSafetySummary(DeviceInfo device, RomProfile romProfile)
    {
        CurrentState = KernelSuRestorationState.ReadyToRestore;
        if (Plan is not { CanProceed: true } || PatchedImage is null)
        {
            return Fail("KernelSU restoration cannot continue safely because the required image or installation procedure could not be verified.");
        }

        var summary =
            $"DEVICE: {device.Manufacturer ?? "?"} {device.Model ?? "?"} ({device.Codename ?? "?"})\n" +
            $"ROM: {romProfile.RomName}\n" +
            $"BUILD: {device.BuildFingerprint ?? "unknown"}\n" +
            $"ANDROID VERSION: {device.AndroidVersion ?? "unknown"}\n" +
            $"IMAGE TYPE: {Plan.RequiredImageType}\n" +
            $"IMAGE FILE: {Path.GetFileName(PatchedImage.FilePath)}\n" +
            $"SHA-256: {PatchedImage.Sha256}\n" +
            "INSTALLATION MODE: fastboot flash\n" +
            $"TARGET PARTITION: {Plan.TargetPartition}\n\n" +
            "Confirm: \"I understand that this will install the verified KernelSU-modified image for this exact device and build.\"";

        return KernelSuRestorationStepResult.NeedsConfirmation(CurrentState, summary, "install-patched-kernelsu-image");
    }

    /// <summary>Section 8: only ever reached after BuildSafetySummary's explicit
    /// confirmation. Reboots to bootloader, best-effort re-checks the device identity,
    /// flashes ONLY the verified target partition, then reboots back to system.</summary>
    public async Task<KernelSuRestorationStepResult> InstallPatchedImageAsync(string? expectedCodename, CancellationToken ct = default)
    {
        if (Plan is not { CanProceed: true } || PatchedImage is null || DeviceSerial is null)
        {
            return Fail("Cannot install without a valid plan, a verified patched image, and a connected device.");
        }

        CurrentState = KernelSuRestorationState.RebootingToRequiredMode;
        if (IsDryRun)
        {
            Log(LogLevel.Info, "[DRY RUN] Would reboot to bootloader and verify device identity before flashing.");
        }
        else
        {
            var rebootResult = await _adb.RebootAsync(DeviceSerial, "bootloader", ct);
            if (!rebootResult.Succeeded) return Fail($"Could not reboot to bootloader: {rebootResult.StandardError}");

            if (!await WaitForFastbootAsync(DeviceSerial, _fastbootWaitTimeout, ct))
            {
                return Fail("Device did not appear in fastboot within the timeout.");
            }

            if (expectedCodename is not null)
            {
                var vars = await _fastboot.GetAllVarsAsync(DeviceSerial, "product", ct);
                if (vars.TryGetValue("product", out var reportedProduct) &&
                    !string.Equals(reportedProduct, expectedCodename, StringComparison.OrdinalIgnoreCase))
                {
                    return Fail($"Device in fastboot reports product '{reportedProduct}', not the expected '{expectedCodename}'. Refusing to flash a possibly-wrong device.");
                }
            }
        }

        CurrentState = KernelSuRestorationState.Installing;
        if (IsDryRun)
        {
            Log(LogLevel.Info, $"[DRY RUN] Would run: fastboot flash {Plan.TargetPartition} \"{PatchedImage.FilePath}\"");
        }
        else
        {
            var flashResult = await _fastboot.FlashAsync(DeviceSerial, Plan.TargetPartition!, PatchedImage.FilePath, _ => { }, _transferTimeout, ct);
            if (!flashResult.Succeeded)
            {
                return Fail($"Flashing the patched image to partition '{Plan.TargetPartition}' failed: {flashResult.StandardError}");
            }
        }

        CurrentState = KernelSuRestorationState.RebootingToSystem;
        if (IsDryRun)
        {
            Log(LogLevel.Info, "[DRY RUN] Would reboot back to system.");
        }
        else
        {
            await _fastboot.RebootAsync(DeviceSerial, null, ct);
        }

        return KernelSuRestorationStepResult.Continue(
            CurrentState,
            IsDryRun ? "[DRY RUN] Install step simulated -- no partition was actually flashed." : "Patched image flashed; device rebooting to system.");
    }

    /// <summary>Section 9: wait for ADB, then re-run the same RootDetector used
    /// everywhere else in the app. Never reports success without this real re-check.</summary>
    public async Task<KernelSuRestorationStepResult> VerifyAfterRebootAsync(string serial, CancellationToken ct = default)
    {
        CurrentState = KernelSuRestorationState.VerifyingKernelSu;
        if (IsDryRun)
        {
            CurrentState = KernelSuRestorationState.RestoredSuccessfully;
            return KernelSuRestorationStepResult.Finished(CurrentState, "[DRY RUN] Would wait for ADB and re-verify KernelSU on the booted device. No real change was made.");
        }

        var waitResult = await _adb.WaitForDeviceAsync(serial, _adbWaitTimeout, ct);
        if (!waitResult.Succeeded)
        {
            return Fail("Device did not come back up on ADB within the timeout after rebooting -- cannot verify KernelSU yet. Reconnect it and retry verification.");
        }

        LatestRootStatus = await _rootDetector.DetectAsync(serial, ct);

        // "KernelSU exists" (the daemon/marker is present) is not the same claim as
        // "Aetherix currently has root permission" -- both are a real restoration result,
        // but only the fully-granted case gets the unqualified RestoredSuccessfully label.
        if (LatestRootStatus.Solution == RootSolutionType.KernelSU)
        {
            CurrentState = KernelSuRestorationState.RestoredSuccessfully;
            return KernelSuRestorationStepResult.Finished(CurrentState, $"KernelSU restoration verified: {LatestRootStatus.Detail}");
        }

        if (LatestRootStatus.Solution == RootSolutionType.KernelSuDetectedNotGranted)
        {
            CurrentState = KernelSuRestorationState.RestoredSuccessfully;
            return KernelSuRestorationStepResult.Finished(
                CurrentState,
                $"KernelSU is integrated and running after restoration, but Aetherix has not been granted root yet. " +
                $"{LatestRootStatus.Detail}");
        }

        return Fail($"KernelSU was not detected after installing the patched image ({LatestRootStatus.Solution} -- {LatestRootStatus.Detail}). Do not assume restoration succeeded.");
    }

    private async Task<bool> WaitForFastbootAsync(string serial, TimeSpan timeout, CancellationToken ct)
    {
        using var timeoutCts = new CancellationTokenSource(timeout);
        using var linked = CancellationTokenSource.CreateLinkedTokenSource(ct, timeoutCts.Token);

        while (!linked.IsCancellationRequested)
        {
            var devices = await _fastboot.ListDevicesAsync(linked.Token);
            if (devices.Contains(serial)) return true;

            try
            {
                await Task.Delay(TimeSpan.FromSeconds(1), linked.Token);
            }
            catch (OperationCanceledException)
            {
                break;
            }
        }
        return false;
    }

    private KernelSuRestorationStepResult Fail(string message)
    {
        CurrentState = KernelSuRestorationState.Blocked;
        Log(LogLevel.Error, message);
        return KernelSuRestorationStepResult.Blocked(message);
    }
}
