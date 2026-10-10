using AetherixRomMaster.Core.Profiles;

namespace AetherixRomMaster.Core.Devices;

/// <summary>
/// The very first gate: is the connected device even one this build supports? Checked
/// against the device-reported codename only — a model name match is never sufficient,
/// since marketing names are reused and can be spoofed/misleading.
/// </summary>
public sealed class DeviceCompatibilityChecker
{
    public DeviceCompatibilityResult Check(DeviceInfo device, DeviceProfile? supportedProfile)
    {
        if (device.ConnectionMode == ConnectionMode.Disconnected)
        {
            return new DeviceCompatibilityResult(false, "No device detected. Connect the phone via USB and enable USB debugging if Android is booted.");
        }

        if (device.Codename is null)
        {
            return new DeviceCompatibilityResult(false, "Could not read the device's codename (ro.product.device / fastboot product variable). Cannot verify compatibility without it.");
        }

        if (supportedProfile is null)
        {
            return new DeviceCompatibilityResult(false, "No device profile is loaded to compare against.");
        }

        if (!supportedProfile.Matches(device.Codename))
        {
            return new DeviceCompatibilityResult(
                false,
                $"Detected device codename '{device.Codename}' is not supported. AETHERIX ROM MASTER v1 only supports " +
                $"'{supportedProfile.Codename}' ({string.Join(" / ", supportedProfile.SupportedModels)}). Refusing to continue to avoid bricking an unsupported device.");
        }

        return new DeviceCompatibilityResult(true, $"Device codename '{device.Codename}' matches the supported profile ({string.Join(" / ", supportedProfile.SupportedModels)}).");
    }
}
