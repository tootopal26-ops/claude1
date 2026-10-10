using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.KernelSu.Restoration;
using AetherixRomMaster.Core.Profiles;

namespace AetherixRomMaster.Core.Installation;

/// <summary>
/// Builds an honest, per-step automation-capability report for the current
/// device/ROM/KernelSU profile combination -- read-only, never executes anything. This is
/// what the "AUTOMATIC / MANUAL / BLOCKED" UI list and the Dry Run automation-plan preview
/// are both built from, so they can never claim a step is automatic when the profile data
/// backing it is actually missing or unverified.
/// </summary>
public sealed class AutomationCapabilityPlanner
{
    public IReadOnlyList<AutomationCapabilityItem> BuildReport(
        DeviceInfo device,
        RomProfile romProfile,
        KernelSuProfile? kernelSuProfile,
        RootDetectionResult? currentRoot)
    {
        var items = new List<AutomationCapabilityItem>
        {
            Item("Device detection", AutomationCapabilityLevel.Automatic,
                "Model, codename, Android version, bootloader and root state are all read via ADB/Fastboot queries."),
        };

        var verified = romProfile.VerificationStatus == VerificationStatus.Verified;
        items.Add(verified
            ? Item("ROM/firmware verification", AutomationCapabilityLevel.Automatic,
                "This ROM profile is Verified -- checksum and compatibility checks run automatically.")
            : Item("ROM/firmware verification", AutomationCapabilityLevel.Blocked,
                $"ROM profile is '{romProfile.VerificationStatus}', not Verified -- {romProfile.VerificationGapNotes ?? "real flashing stays blocked until a human confirms this profile."}"));

        if (romProfile.RequiresInitialInstallPackage)
        {
            items.Add(verified
                ? Item("Initial-install package transfer/flash", AutomationCapabilityLevel.Automatic,
                    "Flashed via the verified fastboot update procedure once the ROM profile is Verified.")
                : Item("Initial-install package transfer/flash", AutomationCapabilityLevel.Blocked,
                    "Requires a Verified ROM profile first."));
        }

        items.Add(romProfile.RequiresDataFormat
            ? Item("Format Data", AutomationCapabilityLevel.UserConfirmationRequired,
                "Erases user data -- Aetherix always stops for an explicit, itemized confirmation before formatting, never bypassed.")
            : Item("Format Data", AutomationCapabilityLevel.Automatic,
                "Not required for this ROM profile."));

        items.Add(Item("Reboot to recovery", AutomationCapabilityLevel.Automatic,
            "Issued via a real fastboot/adb reboot command once prior steps succeed."));

        items.Add(Item("ADB sideload", AutomationCapabilityLevel.Automatic,
            "Attempted automatically first. If the recovery does not enter sideload mode on its own, Aetherix falls back " +
            "to showing manual \"Apply Update → Apply from ADB\" instructions rather than guessing or simulating taps."));

        items.Add(Item("Apply Update (recovery menu)", AutomationCapabilityLevel.ManualInteractionRequired,
            "Only needed as a fallback -- skipped entirely when the recovery enters sideload mode automatically after reboot. " +
            "Aetherix never attempts to tap/navigate a recovery's touchscreen UI itself."));

        items.Add(Item("Fastbootd / fastboot flashing", verified ? AutomationCapabilityLevel.Automatic : AutomationCapabilityLevel.Blocked,
            verified
                ? "Only the verified device/ROM-specific command sequence is ever run -- never an arbitrary or generated flashing command."
                : "Blocked until the ROM profile is Verified -- Aetherix will not generate or guess a flashing command."));

        items.Add(BuildKernelSuItem(romProfile, kernelSuProfile, currentRoot));

        items.Add(Item("Final reboot + ROM/root verification", AutomationCapabilityLevel.Automatic,
            "Waits for ADB to come back, compares build fingerprint, and re-runs the same root detector used everywhere else in the app."));

        return items;
    }

    private static AutomationCapabilityItem BuildKernelSuItem(
        RomProfile romProfile, KernelSuProfile? kernelSuProfile, RootDetectionResult? currentRoot)
    {
        if (currentRoot?.Solution == RootSolutionType.KernelSU)
        {
            return Item("KernelSU root", AutomationCapabilityLevel.Automatic,
                "KernelSU is already integrated and operational -- no restoration/patching needed.");
        }

        if (kernelSuProfile is null || kernelSuProfile.VerificationStatus != VerificationStatus.Verified)
        {
            return Item("KernelSU root restoration", AutomationCapabilityLevel.Blocked,
                "No verified KernelSU restoration profile exists yet for this device/ROM -- the required image type and " +
                "target partition have not been confirmed, so restoration cannot proceed safely.");
        }

        return Item("KernelSU root restoration", AutomationCapabilityLevel.ManualInteractionRequired,
            $"Image transfer, verification, and flashing to '{kernelSuProfile.TargetPartition}' are automatic once " +
            "confirmed, but the patch itself must be done by the user in KernelSU Manager (Install → Patch Image) -- " +
            "Aetherix never patches a kernel image itself and never installs or recommends Magisk.");
    }

    private static AutomationCapabilityItem Item(string step, AutomationCapabilityLevel level, string explanation) =>
        new(step, level, explanation);
}
