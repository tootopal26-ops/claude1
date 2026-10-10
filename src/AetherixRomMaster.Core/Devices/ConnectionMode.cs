namespace AetherixRomMaster.Core.Devices;

public enum ConnectionMode
{
    Disconnected,
    Adb,
    FastbootBootloader,
    FastbootUserspace,
    AdbSideload,

    /// <summary>ADB reports the device in plain "recovery" state (adb devices shows
    /// "recovery", not "sideload") -- the recovery has booted and has ADB, but has not
    /// been put into sideload mode yet. Most custom recoveries need the user to manually
    /// select "Apply Update" -> "Apply from ADB" to reach sideload from here; this is a
    /// distinct, real, reportable state rather than guessed-at from a timeout.</summary>
    AdbRecoveryManual,

    Unknown
}
