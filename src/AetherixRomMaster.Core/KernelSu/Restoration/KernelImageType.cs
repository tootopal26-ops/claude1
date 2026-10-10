namespace AetherixRomMaster.Core.KernelSu.Restoration;

/// <summary>
/// Which boot-related image KernelSU needs to patch for a given device/kernel. Modern
/// devices with a GKI 2.0 kernel and the "boot-hdr-v4"/init_boot split put the actual
/// kernel+ramdisk in init_boot.img and leave boot.img mostly empty -- flashing the wrong
/// one does not produce a working KernelSU root, and on some devices does not boot at
/// all. This is exactly why AETHERIX ROM MASTER never assumes boot.img by default: the
/// correct value must come from a verified <see cref="KernelSuProfile"/>, never a guess.
/// </summary>
public enum KernelImageType
{
    BootImg,
    InitBootImg,

    /// <summary>A verified image type other than the two common cases above. The exact
    /// file name still comes from <see cref="KernelSuProfile.RequiredImageFileName"/> --
    /// this case exists so the model never forces a boot.img/init_boot.img choice onto a
    /// device that genuinely needs something else.</summary>
    OtherVerified,
}
