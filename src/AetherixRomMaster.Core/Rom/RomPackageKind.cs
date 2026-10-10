namespace AetherixRomMaster.Core.Rom;

public enum RomPackageKind
{
    Unknown,

    /// <summary>Contains android-info.txt — the marker `fastboot update` itself requires;
    /// this is the "initial install" / firmware package flashed before sideloading the ROM.</summary>
    InitialInstallPackage,

    /// <summary>META-INF/com/android/metadata + payload.bin — seamless A/B OTA package.</summary>
    AbOtaPayload,

    /// <summary>META-INF/com/android/metadata without payload.bin — legacy block-based
    /// update applied from a recovery (adb sideload or recovery's "apply update").</summary>
    LegacyFlashableZip
}
