namespace AetherixRomMaster.Core.Profiles;

/// <summary>
/// Everything the app knows about a specific supported device, keyed by codename —
/// never by marketing name, since several models can share one codename (the G54 5G
/// and G64 5G both report "cancunf").
/// </summary>
public sealed class DeviceProfile
{
    public required string Codename { get; init; }
    public required string Manufacturer { get; init; }
    public required IReadOnlyList<string> SupportedModels { get; init; }
    public string? Chipset { get; init; }
    public string? StockOsAtLaunch { get; init; }

    /// <summary>
    /// MediaTek devices enforce an anti-rollback (ARB) counter in the bootloader:
    /// flashing firmware older than the currently-fused ARB index can hard-brick the
    /// device. When true, the installer must read version-bootloader / the anti
    /// fastboot variable and hard-stop rather than flash blindly.
    /// </summary>
    public bool EnforceAntiRollbackCheck { get; init; }

    public required VerificationStatus VerificationStatus { get; init; }
    public required IReadOnlyList<ProfileCitation> Citations { get; init; }

    public bool Matches(string? detectedCodename)
        => detectedCodename is not null &&
           string.Equals(detectedCodename, Codename, StringComparison.OrdinalIgnoreCase);
}
