using System.Text.RegularExpressions;
using AetherixRomMaster.Core.Shell;

namespace AetherixRomMaster.Core.Devices;

/// <summary>
/// Thin wrapper around the official adb binary. Every method shells out to a single,
/// well-documented adb subcommand — nothing here is a custom or invented protocol.
/// </summary>
public sealed class AdbClient
{
    private static readonly TimeSpan DefaultTimeout = TimeSpan.FromSeconds(15);
    private static readonly Regex GetPropLine = new(@"^\[(?<key>[^\]]+)\]:\s*\[(?<value>.*)\]$", RegexOptions.Compiled);

    private readonly IProcessRunner _runner;
    private readonly string _adbPath;

    public AdbClient(IProcessRunner runner, string adbPath)
    {
        _runner = runner;
        _adbPath = adbPath;
    }

    public async Task<IReadOnlyList<AdbDeviceEntry>> ListDevicesAsync(CancellationToken ct = default)
    {
        var result = await _runner.RunAsync(_adbPath, ["devices", "-l"], DefaultTimeout, ct);
        if (!result.Succeeded) return [];

        var devices = new List<AdbDeviceEntry>();
        foreach (var line in result.StandardOutput.Split('\n', StringSplitOptions.TrimEntries | StringSplitOptions.RemoveEmptyEntries))
        {
            if (line.StartsWith("List of devices", StringComparison.Ordinal)) continue;
            var parts = line.Split((char[]?)null, StringSplitOptions.RemoveEmptyEntries);
            if (parts.Length < 2) continue;
            devices.Add(new AdbDeviceEntry(parts[0], parts[1]));
        }
        return devices;
    }

    public async Task<string?> GetPropAsync(string serial, string propertyName, CancellationToken ct = default)
    {
        var result = await _runner.RunAsync(_adbPath, ["-s", serial, "shell", "getprop", propertyName], DefaultTimeout, ct);
        if (!result.Succeeded) return null;
        var value = result.StandardOutput.Trim();
        return value.Length == 0 ? null : value;
    }

    public async Task<IReadOnlyDictionary<string, string>> GetAllPropsAsync(string serial, CancellationToken ct = default)
    {
        var result = await _runner.RunAsync(_adbPath, ["-s", serial, "shell", "getprop"], DefaultTimeout, ct);
        var props = new Dictionary<string, string>();
        if (!result.Succeeded) return props;

        foreach (var line in result.StandardOutput.Split('\n'))
        {
            var match = GetPropLine.Match(line.Trim());
            if (match.Success)
            {
                props[match.Groups["key"].Value] = match.Groups["value"].Value;
            }
        }
        return props;
    }

    public async Task<ProcessResult> ShellAsync(string serial, string command, CancellationToken ct = default)
        => await _runner.RunAsync(_adbPath, ["-s", serial, "shell", command], DefaultTimeout, ct);

    public async Task<ProcessResult> RebootAsync(string serial, string? target = null, CancellationToken ct = default)
    {
        List<string> args = target is null ? ["-s", serial, "reboot"] : ["-s", serial, "reboot", target];
        return await _runner.RunAsync(_adbPath, args, DefaultTimeout, ct);
    }

    public async Task<ProcessResult> SideloadAsync(
        string serial,
        string zipPath,
        Action<string> onOutputLine,
        TimeSpan timeout,
        CancellationToken ct = default)
        => await _runner.RunStreamingAsync(_adbPath, ["-s", serial, "sideload", zipPath], onOutputLine, timeout, ct);

    public async Task<ProcessResult> WaitForDeviceAsync(string serial, TimeSpan timeout, CancellationToken ct = default)
        => await _runner.RunAsync(_adbPath, ["-s", serial, "wait-for-device"], timeout, ct);
}
