using AetherixRomMaster.Core.Profiles;

namespace AetherixRomMaster.Core.Installation;

/// <summary>
/// Decides which standalone maintenance operations are actually appropriate for cancunf
/// (Motorola Moto G64 5G / Moto G54 5G) given the currently selected ROM profile --
/// device-specific reasoning, not a list copied from another device's ROM flasher. Every
/// enabled operation uses only generic, universally-documented adb/fastboot subcommands
/// (reboot targets, "fastboot -w", "adb sideload") that behave the same way on virtually
/// any Android device, because those are the only ones that don't require cancunf-specific
/// verification this app cannot currently obtain (network access to the ROM's own official
/// instructions is blocked in this build environment -- see profiles/*.json for the
/// verification-gap notes this already produced).
/// </summary>
public sealed class AdvancedMaintenancePlanner
{
    public IReadOnlyList<MaintenanceOperation> BuildOperations(RomProfile? romProfile)
    {
        return
        [
            BuildFormatUserDataOperation(romProfile),

            new MaintenanceOperation(
                "erase-metadata",
                "Erase Metadata",
                MaintenanceOperationCategory.UnsupportedUnverified,
                "Not enabled. The only documented case this project found of a fastboot-level metadata-partition erase " +
                "tied to a userdata wipe is a Google Pixel-specific fastbootd behavior (Pixel's own bootloader erases " +
                "metadata alongside userdata to avoid stale file-based-encryption state) -- cancunf is a MediaTek " +
                "Dimensity device, not a Pixel, and no verified source confirms an equivalent requirement or an even a " +
                "'metadata' partition with the same role on this device. 'fastboot -w' (Format / Erase User Data, above) " +
                "already covers the verified, documented wipe behavior for this device.",
                IsAvailable: false),

            new MaintenanceOperation(
                "reboot-recovery", "Reboot to Recovery", MaintenanceOperationCategory.SafeNonDestructive,
                "Standard adb/fastboot reboot target ('adb reboot recovery' or 'fastboot reboot recovery' depending on " +
                "current mode). Does not modify any partition or data.",
                IsAvailable: true),

            new MaintenanceOperation(
                "reboot-bootloader", "Reboot to Bootloader", MaintenanceOperationCategory.SafeNonDestructive,
                "Standard adb/fastboot reboot target ('adb reboot bootloader' or 'fastboot reboot bootloader'). Does " +
                "not modify any partition or data.",
                IsAvailable: true),

            new MaintenanceOperation(
                "reboot-fastbootd", "Reboot to Fastbootd", MaintenanceOperationCategory.SafeNonDestructive,
                "Standard reboot into userspace fastboot ('adb reboot fastboot' or 'fastboot reboot fastboot' " +
                "depending on current mode). Does not modify any partition or data.",
                IsAvailable: true),

            new MaintenanceOperation(
                "reboot-system", "Reboot to System", MaintenanceOperationCategory.SafeNonDestructive,
                "Standard reboot back to the installed OS ('adb reboot' or 'fastboot reboot'). Does not modify any " +
                "partition or data.",
                IsAvailable: true),

            new MaintenanceOperation(
                "adb-sideload", "ADB Sideload", MaintenanceOperationCategory.Destructive,
                "Installs a package you select via the standard 'adb sideload <file>' command, once the device is " +
                "already in ADB sideload mode (use Reboot to Recovery, then select Apply Update → Apply from ADB " +
                "on the device first if it doesn't enter sideload mode automatically). This is a generic ADB operation, " +
                "not specific to cancunf -- the exact file you select is shown before you confirm.",
                IsAvailable: true),
        ];
    }

    private static MaintenanceOperation BuildFormatUserDataOperation(RomProfile? romProfile)
    {
        var requiredByVerifiedProfile = romProfile is { RequiresDataFormat: true, VerificationStatus: VerificationStatus.Verified };

        var explanation = requiredByVerifiedProfile
            ? $"Erases /data via 'fastboot -w' -- a standard, universal fastboot subcommand (not device-specific). " +
              $"The currently selected ROM profile ('{romProfile!.RomName}') is Verified and its official procedure " +
              "requires this step; it also runs automatically, with its own itemized confirmation, at the correct " +
              "point in the guided ROM install. This standalone action exists for ad-hoc use outside a full install."
            : "Erases /data via 'fastboot -w' -- a standard, universal fastboot subcommand (not device-specific). Not " +
              "currently required by a Verified ROM profile for the ROM you have selected; use this only if you " +
              "specifically intend to wipe the device.";

        return new MaintenanceOperation(
            "format-data", "Format / Erase User Data", MaintenanceOperationCategory.Destructive, explanation, IsAvailable: true);
    }
}
