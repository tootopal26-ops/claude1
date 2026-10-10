using System.Text.RegularExpressions;

namespace AetherixRomMaster.Core.Devices;

public sealed record RootDetectionResult(RootSolutionType Solution, string? Detail);

/// <summary>
/// Confirms whether root is actually active and, if so, which implementation grants it.
/// A command returning exit code 0 is never treated as proof of root by itself — "su -c id"
/// must additionally echo "uid=0" in its own output, and a KernelSU/Magisk verdict requires
/// the matching on-device marker directory, not just the manager app being installed.
/// </summary>
public sealed class RootDetector
{
    private static readonly Regex UidZero = new(@"uid=0\b", RegexOptions.Compiled);
    private static readonly TimeSpan SuProbeTimeout = TimeSpan.FromSeconds(10);

    private readonly AdbClient _adb;

    public RootDetector(AdbClient adb)
    {
        _adb = adb;
    }

    public async Task<RootDetectionResult> DetectAsync(string serial, CancellationToken ct = default)
    {
        var idResult = await _adb.ShellAsync(serial, "su -c id", ct);
        var rootConfirmed = idResult.Succeeded && UidZero.IsMatch(idResult.StandardOutput);

        if (!rootConfirmed)
        {
            return new RootDetectionResult(RootSolutionType.None, "No active root grant detected (su -c id did not report uid=0).");
        }

        var ksuMarker = await _adb.ShellAsync(serial, "test -d /data/adb/ksu && echo PRESENT", ct);
        var magiskMarker = await _adb.ShellAsync(serial, "test -d /data/adb/magisk && echo PRESENT", ct);

        var hasKsuMarker = ksuMarker.StandardOutput.Contains("PRESENT", StringComparison.Ordinal);
        var hasMagiskMarker = magiskMarker.StandardOutput.Contains("PRESENT", StringComparison.Ordinal);

        if (hasKsuMarker && !hasMagiskMarker)
        {
            var version = await TryGetKsudVersionAsync(serial, ct);
            return new RootDetectionResult(RootSolutionType.KernelSU, version is null ? "KernelSU active (ksud marker present)." : $"KernelSU active ({version}).");
        }

        if (hasMagiskMarker)
        {
            return new RootDetectionResult(
                RootSolutionType.Magisk,
                "Magisk root detected. This device must not be used with AETHERIX ROM MASTER's automated flow: Magisk is not supported and must be removed before proceeding.");
        }

        return new RootDetectionResult(RootSolutionType.OtherOrUnknown, "Root is active but the implementation could not be identified as KernelSU or Magisk.");
    }

    private async Task<string?> TryGetKsudVersionAsync(string serial, CancellationToken ct)
    {
        var result = await _adb.ShellAsync(serial, "/data/adb/ksu/bin/ksud -V", ct);
        if (!result.Succeeded) return null;
        var value = result.StandardOutput.Trim();
        return value.Length > 0 ? value : null;
    }
}
