using System.Text.RegularExpressions;
using AetherixRomMaster.Core.Shell;

namespace AetherixRomMaster.Core.Devices;

/// <summary>
/// Thin wrapper around the official fastboot binary. Every method maps to a single,
/// publicly documented fastboot subcommand (fastboot --help) — nothing invented.
/// fastboot writes "getvar" results to stderr on some builds and stdout on others,
/// so parsing always looks at the combined stream.
/// </summary>
public sealed class FastbootClient
{
    private static readonly TimeSpan DefaultTimeout = TimeSpan.FromSeconds(15);
    private static readonly Regex GetVarLine = new(
        @"^(?:\(bootloader\)\s*)?(?<key>[\w.\-:]+):\s*(?<value>.*)$",
        RegexOptions.Compiled);

    private readonly IProcessRunner _runner;
    private readonly string _fastbootPath;

    public FastbootClient(IProcessRunner runner, string fastbootPath)
    {
        _runner = runner;
        _fastbootPath = fastbootPath;
    }

    public async Task<IReadOnlyList<string>> ListDevicesAsync(CancellationToken ct = default)
    {
        var result = await _runner.RunAsync(_fastbootPath, ["devices"], DefaultTimeout, ct);
        if (!result.Succeeded) return [];

        var serials = new List<string>();
        foreach (var line in result.StandardOutput.Split('\n', StringSplitOptions.TrimEntries | StringSplitOptions.RemoveEmptyEntries))
        {
            var parts = line.Split((char[]?)null, StringSplitOptions.RemoveEmptyEntries);
            if (parts.Length >= 1)
            {
                serials.Add(parts[0]);
            }
        }
        return serials;
    }

    public async Task<string?> GetVarAsync(string serial, string variableName, CancellationToken ct = default)
    {
        var all = await GetAllVarsAsync(serial, variableName, ct);
        return all.TryGetValue(variableName, out var value) ? value : null;
    }

    /// <summary>
    /// Runs `fastboot getvar &lt;variableName&gt;` (or "all") and parses every
    /// "key: value" line found in the combined stdout/stderr output.
    /// </summary>
    public async Task<IReadOnlyDictionary<string, string>> GetAllVarsAsync(
        string serial,
        string variableName = "all",
        CancellationToken ct = default)
    {
        var result = await _runner.RunAsync(_fastbootPath, ["-s", serial, "getvar", variableName], DefaultTimeout, ct);
        var vars = new Dictionary<string, string>();

        foreach (var line in result.CombinedOutput.Split('\n'))
        {
            var trimmed = line.Trim();
            if (trimmed.Length == 0 || trimmed.StartsWith("finished", StringComparison.OrdinalIgnoreCase)) continue;

            var match = GetVarLine.Match(trimmed);
            if (match.Success)
            {
                vars[match.Groups["key"].Value] = match.Groups["value"].Value.Trim();
            }
        }
        return vars;
    }

    public async Task<ProcessResult> FlashAsync(
        string serial,
        string partition,
        string imagePath,
        Action<string> onOutputLine,
        TimeSpan timeout,
        CancellationToken ct = default)
        => await _runner.RunStreamingAsync(_fastbootPath, ["-s", serial, "flash", partition, imagePath], onOutputLine, timeout, ct);

    /// <summary>
    /// `fastboot update &lt;zip&gt;` flashes every image described by the package's
    /// android-info.txt / images manifest in one step — this is the standard
    /// "initial install package" mechanism used by modern AOSP-derived ROMs.
    /// </summary>
    public async Task<ProcessResult> UpdateAsync(
        string serial,
        string zipPath,
        bool skipReboot,
        Action<string> onOutputLine,
        TimeSpan timeout,
        CancellationToken ct = default)
    {
        List<string> args = skipReboot
            ? ["-s", serial, "--skip-reboot", "update", zipPath]
            : ["-s", serial, "update", zipPath];
        return await _runner.RunStreamingAsync(_fastbootPath, args, onOutputLine, timeout, ct);
    }

    /// <summary>
    /// `fastboot -w` — the dedicated wipe-userdata subcommand (formats /data and /cache).
    /// This is its own fastboot verb, distinct from `flash`/`update`, and the only
    /// command this app ever uses to erase user data.
    /// </summary>
    public async Task<ProcessResult> WipeUserdataAsync(
        string serial,
        Action<string> onOutputLine,
        TimeSpan timeout,
        CancellationToken ct = default)
        => await _runner.RunStreamingAsync(_fastbootPath, ["-s", serial, "-w"], onOutputLine, timeout, ct);

    public async Task<ProcessResult> RebootAsync(string serial, string? target = null, CancellationToken ct = default)
    {
        List<string> args = target switch
        {
            null => ["-s", serial, "reboot"],
            "bootloader" => ["-s", serial, "reboot-bootloader"],
            "recovery" => ["-s", serial, "reboot-recovery"],
            "fastboot" => ["-s", serial, "reboot-fastboot"],
            _ => ["-s", serial, "reboot", target],
        };
        return await _runner.RunAsync(_fastbootPath, args, DefaultTimeout, ct);
    }

    /// <summary>
    /// Diagnostic only — returns the unlock-data blob fastboot prints. This app never
    /// calls `fastboot oem unlock`; bootloader unlock is a prerequisite the user has
    /// already completed, not an operation this tool performs.
    /// </summary>
    public async Task<ProcessResult> OemGetUnlockDataAsync(string serial, CancellationToken ct = default)
        => await _runner.RunAsync(_fastbootPath, ["-s", serial, "oem", "get_unlock_data"], DefaultTimeout, ct);
}
