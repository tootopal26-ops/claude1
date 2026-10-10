using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.Recovery;
using AetherixRomMaster.Core.Rom;

namespace AetherixRomMaster.Core.Installation;

/// <summary>
/// The explicit, visible checklist run before real flashing is allowed to start.
/// Reuses whatever a prior dry run already verified (checksum/ROM/KernelSU results
/// live on InstallContext) but re-queries the device fresh, since it may have been
/// reconnected/changed since that dry run. Every item is shown, blocking or not --
/// nothing here is hidden from the user, including the one deliberate non-blocking
/// item (KernelSU), which is called out explicitly in its own detail text.
///
/// Only the device identity, bootloader state, ROM/package validity and a recoverable
/// path are ever blocking: root/KernelSU status is advisory-only, and nothing here (or
/// anywhere else in the app) reads or gates on Play Integrity -- see
/// PreFlightSafetyCheckerTests for the regression tests covering both.
/// </summary>
public sealed class PreFlightSafetyChecker
{
    private readonly IDeviceDetectionService _deviceDetection;
    private readonly DeviceCompatibilityChecker _deviceCompatibility;
    private readonly RecoveryPlanner _recoveryPlanner;

    public PreFlightSafetyChecker(IDeviceDetectionService deviceDetection, RecoveryPlanner recoveryPlanner, DeviceCompatibilityChecker? deviceCompatibility = null)
    {
        _deviceDetection = deviceDetection;
        _recoveryPlanner = recoveryPlanner;
        _deviceCompatibility = deviceCompatibility ?? new DeviceCompatibilityChecker();
    }

    public async Task<PreFlightSafetyReport> RunAsync(InstallContext context, CancellationToken ct = default)
    {
        var device = await _deviceDetection.DetectAsync(ct);
        var items = new List<PreFlightCheckItem>
        {
            CheckDeviceIdentity(device, context),
            CheckBootloaderState(device, context),
            CheckRequiredPackagesPresent(context),
            CheckChecksums(context),
            CheckRomCompatibility(context),
            CheckKernelSuCompatibility(context),
            await CheckRecoveryPathAvailabilityAsync(context, ct),
        };

        return new PreFlightSafetyReport { Items = items };
    }

    private PreFlightCheckItem CheckDeviceIdentity(DeviceInfo device, InstallContext context)
    {
        var result = _deviceCompatibility.Check(device, context.DeviceProfile);
        return new PreFlightCheckItem("Device is exactly Moto G64 5G / cancunf",
            result.IsSupported ? PreFlightCheckStatus.Pass : PreFlightCheckStatus.Blocked, IsBlocking: true, result.Reason);
    }

    /// <summary>
    /// Evidence-based tri-state, matching exactly what was actually observed:
    /// - Pass: the device itself reported VerifiedUnlocked (only ever from an authoritative
    ///   Fastboot round-trip -- no ADB property is trusted as bootloader evidence anymore,
    ///   see DeviceDetectionService.DeriveBootloaderLockStateFromFastboot), OR detection was
    ///   UnableToVerify and the user explicitly attested Unlocked via "Confirm Unlocked
    ///   Manually" (always logged as a human attestation, never presented as something this
    ///   app verified).
    /// - Blocked: the device positively reported VerifiedLocked. A user attestation never
    ///   overrides this -- it only stands in for a check that couldn't complete, never for
    ///   one that actively found the opposite of what's being claimed. Mirrors
    ///   InstallStateMachine.RunVerifyBootloaderAsync's identical rule at install time.
    /// - UnableToVerify: no reliable read-only query could confirm either state (including,
    ///   always, while the device is in plain ADB/Android mode), and no manual confirmation
    ///   has been given yet. This still withholds Install (IsBlocking stays true) but must
    ///   never be reported or logged as "Locked" -- that was a real bug this checklist
    ///   previously had no way to express.
    /// </summary>
    private static PreFlightCheckItem CheckBootloaderState(DeviceInfo device, InstallContext context)
    {
        const string name = "Bootloader state is Unlocked";

        if (device.BootloaderLockState == BootloaderLockState.VerifiedUnlocked)
        {
            return new PreFlightCheckItem(name, PreFlightCheckStatus.Pass, IsBlocking: true,
                $"Verified Unlocked -- confirmed by an authoritative Fastboot query, never user-selectable. (Detected state: {device.BootloaderLockState}.)");
        }

        if (device.BootloaderLockState == BootloaderLockState.VerifiedLocked)
        {
            return new PreFlightCheckItem(name, PreFlightCheckStatus.Blocked, IsBlocking: true,
                "Verified Locked -- an authoritative Fastboot query reports a locked bootloader. AETHERIX ROM MASTER never " +
                "unlocks a bootloader itself and will not proceed; a manual confirmation cannot override a positively-" +
                "detected Locked state.");
        }

        if (context.BootloaderUserConfirmedUnlocked)
        {
            return new PreFlightCheckItem(name, PreFlightCheckStatus.Pass, IsBlocking: true,
                "Unable to verify via a Fastboot query (detected state: UnableToVerify), but the user explicitly confirmed " +
                "Unlocked via \"Confirm Unlocked Manually\". This is a human attestation, not an app-verified fact.");
        }

        if (device.AdbConnected)
        {
            // Deliberately never uses the word "Locked" anywhere in this message, even in
            // passing -- a real bug this guards against had Recovery Mode and this
            // checklist both casually mention "Locked" while explaining an UnableToVerify
            // state, which read, at a glance, like the device had been found locked.
            return new PreFlightCheckItem(name, PreFlightCheckStatus.UnableToVerify, IsBlocking: true,
                "Unable to verify bootloader — reboot to bootloader/Fastboot to verify. The device is currently in " +
                "Android/ADB mode, where no ADB property is trusted as bootloader-lock evidence anymore (several have each " +
                "been proven, on real hardware, to report this device's state incorrectly). Run \"Verify Bootloader via " +
                "Fastboot\" for an authoritative check, or use \"Confirm Unlocked Manually\" if Fastboot is not reachable " +
                "on this PC.");
        }

        return new PreFlightCheckItem(name, PreFlightCheckStatus.UnableToVerify, IsBlocking: true,
            "Unable to verify: no device is connected, or a Fastboot query could not complete (unavailable, timed out, or " +
            "the device was not found). Run \"Verify Bootloader via Fastboot\" for an authoritative check, or use " +
            "\"Confirm Unlocked Manually\".");
    }

    private static PreFlightCheckItem CheckRequiredPackagesPresent(InstallContext context)
    {
        var romPresent = !string.IsNullOrWhiteSpace(context.RomZipPath) && File.Exists(context.RomZipPath);
        var initialRequired = context.RomProfile.RequiresInitialInstallPackage;
        var initialPresent = !initialRequired || (!string.IsNullOrWhiteSpace(context.InitialInstallZipPath) && File.Exists(context.InitialInstallZipPath));
        var ok = romPresent && initialPresent;

        var detail = ok
            ? "ROM package" + (initialRequired ? " and the required initial-install package are" : " is") + " present on disk."
            : (!romPresent ? "ROM package is missing. " : string.Empty) +
              (initialRequired && !initialPresent ? "Required initial-install package is missing." : string.Empty);

        return new PreFlightCheckItem("Required package(s) selected and present",
            ok ? PreFlightCheckStatus.Pass : PreFlightCheckStatus.Blocked, IsBlocking: true, detail);
    }

    private static PreFlightCheckItem CheckChecksums(InstallContext context)
    {
        var initialRequired = context.RomProfile.RequiresInitialInstallPackage;
        var romOk = context.RomValidation?.Checksum.Matched ?? false;

        // The Initial Install package is a deliberate exception, researched and justified
        // in RomValidationService: no checksum requirement exists anywhere in the official
        // cancunf/Infinity-X documentation for this specific package type, so having no
        // reference to compare against is acceptable here (never for the ROM package
        // itself) -- but if a reference DOES exist (now or later), it must still match,
        // exactly as strictly as the ROM package.
        var initialChecksum = context.InitialInstallValidation?.Checksum;
        var initialOk = !initialRequired || (initialChecksum is not null && !initialChecksum.IsGenuineMismatch);

        var ok = romOk && initialOk;
        var evidenceExists = context.RomValidation?.Checksum.HasOfficialReference ?? false;

        return new PreFlightCheckItem("SHA-256/MD5 checksums verified",
            ok ? PreFlightCheckStatus.Pass : evidenceExists ? PreFlightCheckStatus.Blocked : PreFlightCheckStatus.UnableToVerify,
            IsBlocking: true,
            ok ? "ROM package matched an official checksum." +
                 (initialRequired ? (initialChecksum?.HasOfficialReference == true
                     ? " Initial Install package matched an official checksum too."
                     : " Initial Install package has no published checksum to check (researched -- none exists for this package type); verified instead by its declared required-board match.")
                     : "")
               : "At least one selected package has not been checksum-verified against an official source yet -- run a dry run first.");
    }

    private static PreFlightCheckItem CheckRomCompatibility(InstallContext context)
    {
        var ok = context.RomValidation is { HasBlockingIssues: false };
        var issues = context.RomValidation?.Issues
            .Where(i => i.Severity == CompatibilityIssueSeverity.Blocking)
            .Select(i => i.Message)
            .ToList() ?? [];

        return new PreFlightCheckItem("ROM compatible with this device and profile",
            ok ? PreFlightCheckStatus.Pass : PreFlightCheckStatus.Blocked, IsBlocking: true,
            ok ? "No blocking compatibility issues found." : (issues.Count > 0 ? string.Join(" ", issues) : "ROM has not been validated yet -- run a dry run first."));
    }

    /// <summary>Advisory only (IsBlocking: false) -- root/KernelSU status is never a
    /// prerequisite for the ROM install itself. An unconfirmed assessment is reported as
    /// UnableToVerify, not Blocked: it isn't a safety problem, just something not yet
    /// confirmed, and it never withholds Install.</summary>
    private static PreFlightCheckItem CheckKernelSuCompatibility(InstallContext context)
    {
        var assessment = context.KernelSuAssessment;
        var ok = assessment is not null && !assessment.RequiresManualStop;

        var detail = assessment is null
            ? "Not yet assessed -- run a dry run first."
            : assessment.Explanation + " (Does not block the ROM install itself -- this affects root status only, not device safety; see RunRestoreKernelSuIfCompatibleAsync.)";

        return new PreFlightCheckItem("KernelSU compatibility confirmed",
            ok ? PreFlightCheckStatus.Pass : PreFlightCheckStatus.UnableToVerify, IsBlocking: false, detail);
    }

    private async Task<PreFlightCheckItem> CheckRecoveryPathAvailabilityAsync(InstallContext context, CancellationToken ct)
    {
        var (ready, explanation) = await _recoveryPlanner.AssessRetryReadinessAsync(context, ct);
        return new PreFlightCheckItem("Recovery path available if an early step fails",
            ready ? PreFlightCheckStatus.Pass : PreFlightCheckStatus.Blocked, IsBlocking: true, explanation);
    }
}
