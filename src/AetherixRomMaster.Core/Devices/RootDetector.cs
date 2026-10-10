using System.Text.RegularExpressions;

namespace AetherixRomMaster.Core.Devices;

public sealed record RootDetectionResult(RootSolutionType Solution, string? Detail);

/// <summary>
/// Confirms whether root is actually active and, if so, which implementation grants it.
/// A command returning exit code 0 is never treated as proof of root by itself — "su -c id"
/// must additionally echo "uid=0" in its own output, and a KernelSU/Magisk verdict requires
/// the matching on-device marker directory, not just the manager app being installed.
///
/// KernelSU's su grant is per calling identity (package/uid), not global: KernelSU Manager
/// showing "Working" only proves the Manager app itself holds a grant, and says nothing about
/// whether "adb shell" was separately granted one. A real cancunf device was observed
/// reporting "Not rooted" from this app while KernelSU Manager showed "Working" — the ksud
/// daemon was genuinely running; adb shell simply had never been granted access. This detector
/// treats that as its own, honestly-labeled state (KernelSuDetectedNotGranted) instead of
/// collapsing it into either "rooted" or "not rooted".
/// </summary>
public sealed class RootDetector
{
    private static readonly Regex UidZero = new(@"uid=0\b", RegexOptions.Compiled);

    /// <summary>KernelSU Manager's application id. Verified against the project's own
    /// F-Droid/IzzyOnDroid listing (AppID: me.weishu.kernelsu, author weishu -- the
    /// KernelSU project maintainer) rather than assumed, since this detector's whole
    /// purpose is to never report a fact it didn't actually check.</summary>
    private const string KernelSuManagerPackageId = "me.weishu.kernelsu";

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
            return await DetectWithoutRootGrantAsync(serial, ct);
        }

        // Elevated via "su -c" for both checks: /data/adb/ is only readable with an active
        // root grant, so running these unprivileged would false-negative even when the
        // matching root solution is genuinely installed (this was a real bug -- the previous
        // version ran these two checks unprivileged despite only reaching this branch once
        // root was already confirmed).
        var ksuMarker = await _adb.ShellAsync(serial, "su -c \"test -d /data/adb/ksu && echo PRESENT\"", ct);
        var magiskMarker = await _adb.ShellAsync(serial, "su -c \"test -d /data/adb/magisk && echo PRESENT\"", ct);

        var hasKsuMarker = ksuMarker.StandardOutput.Contains("PRESENT", StringComparison.Ordinal);
        var hasMagiskMarker = magiskMarker.StandardOutput.Contains("PRESENT", StringComparison.Ordinal);

        if (hasKsuMarker && !hasMagiskMarker)
        {
            var version = await TryGetKsudVersionAsync(serial, ct);
            return new RootDetectionResult(RootSolutionType.KernelSU, version is null ? "KernelSU detected and operational (ksud marker present, root grant confirmed for this session)." : $"KernelSU detected and operational ({version}, root grant confirmed for this session).");
        }

        if (hasMagiskMarker)
        {
            return new RootDetectionResult(
                RootSolutionType.Magisk,
                "Magisk root detected. This device must not be used with AETHERIX ROM MASTER's automated flow: Magisk is not supported and must be removed before proceeding.");
        }

        return new RootDetectionResult(RootSolutionType.OtherOrUnknown, "Root is active but the implementation could not be identified as KernelSU or Magisk.");
    }

    /// <summary>
    /// "su -c id" failing only proves this adb shell session wasn't granted root — it is not
    /// proof the device has no root solution at all, and it is never treated as "confirmed not
    /// rooted" on its own. Two further unprivileged (no su required) checks run before any
    /// verdict is given, and if either of those checks itself fails to execute, the result is
    /// Inconclusive rather than a guessed None -- absence of evidence in a failed check is not
    /// evidence of absence.
    ///
    /// 1. "ps -A" for the ksud process: it runs as a system service independent of which
    ///    callers currently hold a grant, so finding it is real evidence KernelSU is installed
    ///    and active even though this session has no grant.
    /// 2. "pm list packages" for the KernelSU Manager app: installed-but-not-running tells a
    ///    different, still-useful story (Manager present, kernel integration unconfirmed) from
    ///    neither being found at all.
    /// </summary>
    private async Task<RootDetectionResult> DetectWithoutRootGrantAsync(string serial, CancellationToken ct)
    {
        var psResult = await _adb.ShellAsync(serial, "ps -A", ct);
        var ksudCheckOk = psResult.Succeeded;
        var ksudFound = ksudCheckOk && ContainsProcessNamed(psResult.StandardOutput, "ksud");

        if (ksudFound)
        {
            return new RootDetectionResult(
                RootSolutionType.KernelSuDetectedNotGranted,
                "KernelSU's ksud daemon is running on this device, but this adb/shell session has not been granted root. " +
                "This is expected KernelSU behavior, not an error: root grants are per calling identity, so KernelSU Manager " +
                "showing \"Working\" does not automatically extend a grant to adb shell. Open KernelSU Manager on the device " +
                "and grant root access to \"Shell\"/ADB if AETHERIX ROM MASTER needs to perform a privileged check.");
        }

        if (!ksudCheckOk)
        {
            return new RootDetectionResult(
                RootSolutionType.Inconclusive,
                "Root detection is inconclusive: the device's process list could not be read (\"ps -A\" did not complete), " +
                "so KernelSU's ksud daemon could not be ruled in or out. This is not the same as confirming the device has " +
                "no root solution -- reconnect the device and try again.");
        }

        var managerResult = await _adb.ShellAsync(serial, $"pm list packages {KernelSuManagerPackageId}", ct);
        var managerCheckOk = managerResult.Succeeded;
        var managerFound = managerCheckOk && managerResult.StandardOutput.Contains($"package:{KernelSuManagerPackageId}", StringComparison.Ordinal);

        if (managerFound)
        {
            return new RootDetectionResult(
                RootSolutionType.KernelSuManagerInstalledOnly,
                "The KernelSU Manager app is installed, but no running ksud process was found and this session has no root " +
                "grant. The Manager app being installed is not proof this exact kernel build includes KernelSU support -- " +
                "open KernelSU Manager on the device and check its own kernel-integration status before assuming root is " +
                "available.");
        }

        if (!managerCheckOk)
        {
            return new RootDetectionResult(
                RootSolutionType.Inconclusive,
                "Root detection is inconclusive: the ksud process is confirmed not running, but the installed-package list " +
                "could not be read (\"pm list packages\" did not complete), so the KernelSU Manager app's presence could not " +
                "be confirmed either way.");
        }

        return new RootDetectionResult(
            RootSolutionType.None,
            "No active root grant detected (su -c id did not report uid=0), no running KernelSU ksud process was found " +
            "(ps -A), and the KernelSU Manager app is not installed (pm list packages). This indicates the device most " +
            "likely has no active root solution, but does not by itself rule out Magisk, which was not separately probed " +
            "without a root grant.");
    }

    private static bool ContainsProcessNamed(string psOutput, string processName)
    {
        foreach (var line in psOutput.Split('\n'))
        {
            var trimmed = line.TrimEnd();
            if (trimmed.Length == 0) continue;

            var lastSpace = trimmed.LastIndexOf(' ');
            var nameField = lastSpace >= 0 ? trimmed[(lastSpace + 1)..] : trimmed;
            var baseName = nameField.Contains('/') ? nameField[(nameField.LastIndexOf('/') + 1)..] : nameField;

            if (baseName.Equals(processName, StringComparison.Ordinal))
            {
                return true;
            }
        }
        return false;
    }

    private async Task<string?> TryGetKsudVersionAsync(string serial, CancellationToken ct)
    {
        var result = await _adb.ShellAsync(serial, "su -c \"/data/adb/ksu/bin/ksud -V\"", ct);
        if (!result.Succeeded) return null;
        var value = result.StandardOutput.Trim();
        return value.Length > 0 ? value : null;
    }
}
