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
            CheckBootloaderState(device),
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
        return new PreFlightCheckItem("Device is exactly Moto G64 5G / cancunf", result.IsSupported, IsBlocking: true, result.Reason);
    }

    private static PreFlightCheckItem CheckBootloaderState(DeviceInfo device)
    {
        var ok = device.BootloaderLockState == BootloaderLockState.Unlocked;
        return new PreFlightCheckItem("Bootloader state is Unlocked", ok, IsBlocking: true,
            $"Detected state: {device.BootloaderLockState} (read from the device, never user-selectable).");
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

        return new PreFlightCheckItem("Required package(s) selected and present", ok, IsBlocking: true, detail);
    }

    private static PreFlightCheckItem CheckChecksums(InstallContext context)
    {
        var initialRequired = context.RomProfile.RequiresInitialInstallPackage;
        var romOk = context.RomValidation?.Checksum.Matched ?? false;
        var initialOk = !initialRequired || (context.InitialInstallValidation?.Checksum.Matched ?? false);
        var ok = romOk && initialOk;

        return new PreFlightCheckItem("SHA-256 checksums verified", ok, IsBlocking: true,
            ok ? "All selected package(s) matched their official checksum."
               : "At least one selected package has not been checksum-verified against an official source yet -- run a dry run first.");
    }

    private static PreFlightCheckItem CheckRomCompatibility(InstallContext context)
    {
        var ok = context.RomValidation is { HasBlockingIssues: false };
        var issues = context.RomValidation?.Issues
            .Where(i => i.Severity == CompatibilityIssueSeverity.Blocking)
            .Select(i => i.Message)
            .ToList() ?? [];

        return new PreFlightCheckItem("ROM compatible with this device and profile", ok, IsBlocking: true,
            ok ? "No blocking compatibility issues found." : (issues.Count > 0 ? string.Join(" ", issues) : "ROM has not been validated yet -- run a dry run first."));
    }

    private static PreFlightCheckItem CheckKernelSuCompatibility(InstallContext context)
    {
        var assessment = context.KernelSuAssessment;
        var ok = assessment is not null && !assessment.RequiresManualStop;

        var detail = assessment is null
            ? "Not yet assessed -- run a dry run first."
            : assessment.Explanation + " (Does not block the ROM install itself -- this affects root status only, not device safety; see RunRestoreKernelSuIfCompatibleAsync.)";

        return new PreFlightCheckItem("KernelSU compatibility confirmed", ok, IsBlocking: false, detail);
    }

    private async Task<PreFlightCheckItem> CheckRecoveryPathAvailabilityAsync(InstallContext context, CancellationToken ct)
    {
        var (ready, explanation) = await _recoveryPlanner.AssessRetryReadinessAsync(context, ct);
        return new PreFlightCheckItem("Recovery path available if an early step fails", ready, IsBlocking: true, explanation);
    }
}
