using AetherixRomMaster.Core.Rom;

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

    /// <summary>Checksums for the ROM package's own distinct distribution variants (e.g.
    /// GApps vs Vanilla), as published by its official source. Matching a local file
    /// against this list is also how this app determines which variant it is -- never
    /// inferred from a filename. Empty means no official checksum is recorded yet.</summary>
    public IReadOnlyList<RomChecksumEntry> KnownOfficialChecksums { get; init; } = [];

    /// <summary>Same idea for the separate Initial Install / firmware package, when its
    /// official source publishes one. Empty means none is recorded yet -- that package's
    /// checksum check then stays correctly blocked as CHECKSUM_UNAVAILABLE rather than
    /// ever being skipped.</summary>
    public IReadOnlyList<RomChecksumEntry> KnownOfficialInitialInstallChecksums { get; init; } = [];

    public required VerificationStatus VerificationStatus { get; init; }
    public required IReadOnlyList<ProfileCitation> Citations { get; init; }

    /// <summary>Human-readable explanation of what is and is not confirmed yet, shown to the
    /// user whenever VerificationStatus is not Verified.</summary>
    public string? VerificationGapNotes { get; init; }
}
