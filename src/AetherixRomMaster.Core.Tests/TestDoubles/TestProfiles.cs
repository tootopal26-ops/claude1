using AetherixRomMaster.Core.Profiles;

namespace AetherixRomMaster.Core.Tests.TestDoubles;

public static class TestProfiles
{
    public static DeviceProfile Cancunf() => new()
    {
        Codename = "cancunf",
        Manufacturer = "Motorola",
        SupportedModels = ["Moto G64 5G", "Moto G54 5G"],
        Chipset = "MediaTek Dimensity 7025",
        EnforceAntiRollbackCheck = true,
        VerificationStatus = VerificationStatus.PendingManualConfirmation,
        Citations = [],
    };

    public static RomProfile InfinityXUnverified() => new()
    {
        RomName = "Project Infinity X",
        TargetCodename = "cancunf",
        InstallationMethod = InstallationMethod.FastbootUpdatePackageThenSideload,
        RequiresInitialInstallPackage = true,
        RequiresDataFormat = true,
        DataFormatNotes = "This will erase all data on the device.",
        RequiredFirmwareAndroidVersion = "15",
        VerificationStatus = VerificationStatus.Unverified,
        Citations = [],
    };

    public static RomProfile InfinityXVerified() => new()
    {
        RomName = "Project Infinity X",
        TargetCodename = "cancunf",
        InstallationMethod = InstallationMethod.FastbootUpdatePackageThenSideload,
        RequiresInitialInstallPackage = true,
        RequiresDataFormat = true,
        DataFormatNotes = "This will erase all data on the device.",
        RequiredFirmwareAndroidVersion = "15",
        VerificationStatus = VerificationStatus.Verified,
        Citations = [],
    };
}
