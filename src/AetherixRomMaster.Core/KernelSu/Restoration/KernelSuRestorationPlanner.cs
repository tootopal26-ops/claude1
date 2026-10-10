using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.Profiles;

namespace AetherixRomMaster.Core.KernelSu.Restoration;

/// <summary>
/// Decides whether a KernelSU restoration can safely proceed, and if so, exactly what
/// image/partition it targets. Every refusal states which specific fact is missing or
/// unverified -- this planner never fills a gap with a default like "boot.img" or "boot".
/// </summary>
public sealed class KernelSuRestorationPlanner
{
    public KernelSuRestorationPlan BuildPlan(DeviceInfo device, RomProfile romProfile, KernelSuProfile? kernelSuProfile)
    {
        if (device.Codename is null)
        {
            return KernelSuRestorationPlan.Blocked("Device codename could not be read -- cannot select a restoration profile without it.");
        }

        if (!string.Equals(device.Codename, romProfile.TargetCodename, StringComparison.OrdinalIgnoreCase))
        {
            return KernelSuRestorationPlan.Blocked(
                $"Connected device codename '{device.Codename}' does not match the selected ROM profile's target " +
                $"'{romProfile.TargetCodename}'. Restoration will not proceed against a mismatched device.");
        }

        if (kernelSuProfile is null)
        {
            return KernelSuRestorationPlan.Blocked(
                $"No KernelSU restoration profile exists yet for codename '{romProfile.TargetCodename}' / ROM " +
                $"'{romProfile.RomName}'. AETHERIX ROM MASTER will not guess the required image type, file name, or " +
                "target partition -- add a verified KernelSuProfile for this exact device/ROM (and build, if it varies " +
                "by build) before restoration can proceed.");
        }

        if (!string.Equals(kernelSuProfile.TargetCodename, device.Codename, StringComparison.OrdinalIgnoreCase) ||
            !string.Equals(kernelSuProfile.TargetRomName, romProfile.RomName, StringComparison.OrdinalIgnoreCase))
        {
            return KernelSuRestorationPlan.Blocked(
                "The loaded KernelSU restoration profile targets a different device/ROM than what is currently selected " +
                "-- refusing to use it rather than risk applying another build's image requirements.");
        }

        if (kernelSuProfile.TargetRomBuildFingerprint is { } pinnedBuild &&
            !string.Equals(pinnedBuild, device.BuildFingerprint, StringComparison.Ordinal))
        {
            return KernelSuRestorationPlan.Blocked(
                $"This KernelSU restoration profile is pinned to build fingerprint '{pinnedBuild}', but the device " +
                $"currently reports '{device.BuildFingerprint ?? "unknown"}'. A different build may need a different " +
                "image/partition -- refusing to reuse this profile rather than assume it still applies.");
        }

        if (kernelSuProfile.VerificationStatus != VerificationStatus.Verified)
        {
            return KernelSuRestorationPlan.Blocked(
                $"KernelSU restoration data for this device/ROM is '{kernelSuProfile.VerificationStatus}', not Verified. " +
                (kernelSuProfile.VerificationGapNotes ?? "The required image type, file name, and/or target partition " +
                 "has not been confirmed against an official/authoritative source yet."));
        }

        if (kernelSuProfile.RequiredImageType is null || kernelSuProfile.RequiredImageFileName is null || kernelSuProfile.TargetPartition is null)
        {
            return KernelSuRestorationPlan.Blocked(
                "KernelSU restoration profile is marked Verified but is missing the required image type, file name, or " +
                "target partition. Treating this as unsafe to proceed rather than falling back to a default.");
        }

        return new KernelSuRestorationPlan(
            CanProceed: true,
            BlockingReason: null,
            RequiredImageType: kernelSuProfile.RequiredImageType,
            RequiredImageFileName: kernelSuProfile.RequiredImageFileName,
            TargetPartition: kernelSuProfile.TargetPartition);
    }
}
