namespace AetherixRomMaster.Core.Devices;

/// <summary>
/// The five states RootDetector can report, chosen so that no outcome ever forces a
/// dishonest "rooted" or "not rooted" verdict out of evidence that doesn't support it.
/// KernelSU's own permission model is per calling identity (package/uid), not global --
/// so "is root active" and "is KernelSU present on this device" are different questions,
/// and this enum answers both instead of collapsing them.
/// </summary>
public enum RootSolutionType
{
    /// <summary>KernelSU not detected: every check that completed found no evidence --
    /// not "confirmed not rooted" against solutions that weren't fully probed (Magisk is
    /// only checked once a root grant is already held). See RootDetector's Detail for
    /// exactly what was checked.</summary>
    None,

    /// <summary>KernelSU detected and operational: an active root grant was confirmed for
    /// this session (su -c id reported uid=0) AND the /data/adb/ksu marker was verified
    /// under that grant.</summary>
    KernelSU,

    Magisk,
    OtherOrUnknown,

    /// <summary>KernelSU detected but the current process/session has no root permission:
    /// the ksud daemon is confirmed running (a real, observed, unprivileged "ps -A" signal)
    /// but this specific adb/shell session was not granted root. KernelSU Manager reporting
    /// "Working" for the Manager app itself does not mean adb shell has a grant. Never
    /// conflate this with either "rooted" or "not rooted".</summary>
    KernelSuDetectedNotGranted,

    /// <summary>KernelSU Manager app installed but kernel integration not detected: the
    /// Manager package is present, but neither a root grant nor a running ksud process was
    /// found. The Manager APK being installed is never, by itself, proof that the current
    /// kernel build actually includes KernelSU support.</summary>
    KernelSuManagerInstalledOnly,

    /// <summary>Detection inconclusive: one of the unprivileged fallback checks (ps -A or
    /// pm list packages) did not itself complete, so absence of evidence in that check
    /// cannot be reported as evidence of absence. Never treat this the same as None.</summary>
    Inconclusive,
}
