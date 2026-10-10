namespace AetherixRomMaster.Core.Profiles;

public enum InstallationMethod
{
    Unknown,

    /// <summary>
    /// `fastboot update initial-install.zip` (flashes boot/vendor_boot/dtbo/etc. as a set)
    /// followed by `adb sideload rom.zip` from recovery. The pattern confirmed for cancunf.
    /// </summary>
    FastbootUpdatePackageThenSideload,

    /// <summary>Single flashable zip applied from a custom recovery, no separate initial-install step.</summary>
    SingleFlashableZipViaRecovery,

    /// <summary>A/B seamless update delivered as payload.bin inside the zip.</summary>
    AbOtaPayload
}
