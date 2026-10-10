namespace AetherixRomMaster.Core.Devices;

public interface IDeviceDetectionService
{
    Task<DeviceInfo> DetectAsync(CancellationToken ct = default);
}
