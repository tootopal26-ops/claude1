namespace AetherixRomMaster.Core.Devices;

public enum ConnectionMode
{
    Disconnected,
    Adb,
    FastbootBootloader,
    FastbootUserspace,
    AdbSideload,
    Unknown
}
