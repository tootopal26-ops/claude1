using System.Text;

namespace AetherixRomMaster.Core.KernelSu;

/// <summary>
/// Best-effort scan of a boot image's raw bytes for ASCII strings KernelSU-integrated
/// kernels are known to embed (the same technique `strings boot.img | grep -i ksu` uses
/// manually). This is a heuristic signal only, never proof — it reduces false confidence,
/// it never substitutes for the post-flash root verification in VERIFY_KERNELSU_ROOT.
/// </summary>
public sealed class BootImageInspector
{
    private static readonly string[] MarkerTokens =
    [
        "KSU_VERSION",
        "KernelSU",
        "kernelsu",
        "susfs",
        "SUSFS",
    ];

    public async Task<bool> ScanForKernelSuMarkersAsync(string imagePath, CancellationToken ct = default)
    {
        if (!File.Exists(imagePath)) return false;

        var bytes = await File.ReadAllBytesAsync(imagePath, ct);
        var text = Encoding.ASCII.GetString(bytes);

        return MarkerTokens.Any(token => text.Contains(token, StringComparison.Ordinal));
    }
}
