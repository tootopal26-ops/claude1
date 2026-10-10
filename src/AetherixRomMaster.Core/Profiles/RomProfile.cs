namespace AetherixRomMaster.Core.Profiles;

/// <summary>
/// Everything the app knows about one specific ROM build target for one device codename.
/// Deliberately has no hardcoded expected checksum or download URL for a specific ROM
/// release: builds change too often for that to stay true, and the task's own ground
/// rule forbids inventing one. <see cref="ChecksumManifestUrl"/> points at wherever the
/// ROM's own maintainer publishes checksums; VERIFY_CHECKSUMS always checks the
/// downloaded file against that live source, not a value baked into this file.
/// </summary>
public sealed record RomProfile
{
    public required string RomName { get; init; }
    public required string TargetCodename { get; init; }

    public string? AndroidVersionShipped { get; init; }
    public string? RequiredFirmwareAndroidVersion { get; init; }

    public required InstallationMethod InstallationMethod { get; init; }
    public bool RequiresInitialInstallPackage { get; init; }
    public string? InitialInstallPackageNotes { get; init; }
    public bool RequiresDataFormat { get; init; }
    public string? DataFormatNotes { get; init; }

    public string? KernelSuNotes { get; init; }

    /// <summary>Official place to fetch/confirm checksums for this ROM's releases. Null means
    /// no such page is confirmed yet, and the operator must supply a checksum manually.</summary>
    public string? ChecksumManifestUrl { get; init; }

    public required VerificationStatus VerificationStatus { get; init; }
    public required IReadOnlyList<ProfileCitation> Citations { get; init; }

    /// <summary>Human-readable explanation of what is and is not confirmed yet, shown to the
    /// user whenever VerificationStatus is not Verified.</summary>
    public string? VerificationGapNotes { get; init; }
}
