namespace AetherixRomMaster.Core.KernelSu;

public enum KernelSuRecommendedAction
{
    /// <summary>The target kernel already appears to embed KernelSU — nothing to flash
    /// for root; just confirm it is active after the ROM boots.</summary>
    UseBundledKernelSuAsIs,

    /// <summary>The device's current KernelSU root is expected to survive this ROM
    /// install unchanged (e.g. boot partition is not being touched).</summary>
    PreserveExistingRoot,

    /// <summary>A separate, verified KernelSU-enabled kernel/boot image would be needed,
    /// and none has been confirmed for this exact device+ROM+kernel combination. This
    /// app does not locate or build one automatically — stop and explain.</summary>
    ManualVerifiedKernelRequired,

    /// <summary>Kernel version is below KernelSU's supported floor, or otherwise unsupported.</summary>
    Unsupported
}
