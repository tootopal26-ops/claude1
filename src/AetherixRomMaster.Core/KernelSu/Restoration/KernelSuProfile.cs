using AetherixRomMaster.Core.Profiles;

namespace AetherixRomMaster.Core.KernelSu.Restoration;

/// <summary>
/// Everything AETHERIX ROM MASTER needs to know to safely patch-and-restore KernelSU for
/// one specific device codename + ROM (optionally pinned to one exact build fingerprint).
/// Deliberately separate from <see cref="Profiles.RomProfile"/>: a ROM can ship several
/// builds over time with different kernels, and this data must be re-verified per build,
/// not assumed to still hold. Every field defaults to null/Unverified rather than a
/// guessed value -- KernelSuRestorationPlanner refuses to build an actionable plan until
/// RequiredImageType, RequiredImageFileName and TargetPartition are all present AND
/// VerificationStatus is Verified.
/// </summary>
public sealed record KernelSuProfile
{
    public required string TargetCodename { get; init; }
    public required string TargetRomName { get; init; }

    /// <summary>Null means "applies to any build of this ROM"; set this once different
    /// builds are known to need different images, so old data is never reused blindly
    /// against a newer build.</summary>
    public string? TargetRomBuildFingerprint { get; init; }

    public KernelImageType? RequiredImageType { get; init; }

    /// <summary>The exact on-device/in-package file name, e.g. "boot.img" or
    /// "init_boot.img" -- never inferred from <see cref="RequiredImageType"/> alone.</summary>
    public string? RequiredImageFileName { get; init; }

    /// <summary>The fastboot partition name this image must be flashed to, e.g. "boot" or
    /// "init_boot". Independent of the file name on purpose.</summary>
    public string? TargetPartition { get; init; }

    public string? KmiIdentifier { get; init; }
    public string? SecurityPatchLevel { get; init; }

    public required VerificationStatus VerificationStatus { get; init; }
    public required IReadOnlyList<ProfileCitation> Citations { get; init; }
    public string? VerificationGapNotes { get; init; }
}
