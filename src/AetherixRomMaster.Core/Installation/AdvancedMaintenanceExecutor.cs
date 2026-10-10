using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.Logging;

namespace AetherixRomMaster.Core.Installation;

/// <summary>
/// Runs the operations AdvancedMaintenancePlanner marks available. Every method logs the
/// exact command before running it, and running real anything is skipped entirely when
/// isDryRun is true -- dry run here means zero process calls, the same guarantee the main
/// install flow's DryRunFlashExecutor makes. Reboot operations pick the adb or fastboot
/// client based on the device's current connection mode, since the right tool to issue a
/// reboot depends on which one the device is currently reachable through.
/// </summary>
public sealed class AdvancedMaintenanceExecutor
{
    private static readonly TimeSpan FormatTimeout = TimeSpan.FromMinutes(5);
    private static readonly TimeSpan SideloadTimeout = TimeSpan.FromMinutes(20);

    private readonly AdbClient _adb;
    private readonly FastbootClient _fastboot;
    private readonly IInstallLogger _logger;

    public AdvancedMaintenanceExecutor(AdbClient adb, FastbootClient fastboot, IInstallLogger logger)
    {
        _adb = adb;
        _fastboot = fastboot;
        _logger = logger;
    }

    public async Task<MaintenanceActionResult> FormatUserDataAsync(string serial, bool isDryRun, CancellationToken ct = default)
    {
        const string command = "fastboot -w";
        Log("FormatData", $"Target: {serial}. Command: {command}");
        if (isDryRun)
        {
            Log("FormatData", $"[DRY RUN] Would run: {command}");
            return new MaintenanceActionResult(true, $"[DRY RUN] Would run: {command}. No command was actually run.");
        }

        var result = await _fastboot.WipeUserdataAsync(serial, line => _logger.Log(LogLevel.Debug, "Maintenance", line), FormatTimeout, ct);
        return LogAndReturn("FormatData", command, result);
    }

    public Task<MaintenanceActionResult> RebootToRecoveryAsync(string serial, ConnectionMode currentMode, bool isDryRun, CancellationToken ct = default) =>
        RebootAsync(serial, currentMode, "recovery", isDryRun, ct);

    public Task<MaintenanceActionResult> RebootToBootloaderAsync(string serial, ConnectionMode currentMode, bool isDryRun, CancellationToken ct = default) =>
        RebootAsync(serial, currentMode, "bootloader", isDryRun, ct);

    public Task<MaintenanceActionResult> RebootToFastbootdAsync(string serial, ConnectionMode currentMode, bool isDryRun, CancellationToken ct = default) =>
        RebootAsync(serial, currentMode, "fastboot", isDryRun, ct);

    public Task<MaintenanceActionResult> RebootToSystemAsync(string serial, ConnectionMode currentMode, bool isDryRun, CancellationToken ct = default) =>
        RebootAsync(serial, currentMode, null, isDryRun, ct);

    private async Task<MaintenanceActionResult> RebootAsync(string serial, ConnectionMode currentMode, string? target, bool isDryRun, CancellationToken ct)
    {
        var useFastboot = currentMode is ConnectionMode.FastbootBootloader or ConnectionMode.FastbootUserspace;
        // FastbootClient.RebootAsync special-cases these three targets into their own
        // hyphenated subcommand (reboot-recovery/-bootloader/-fastboot) rather than
        // "reboot <target>" -- the displayed command must say what's actually sent.
        var command = (useFastboot, target) switch
        {
            (true, "recovery" or "bootloader" or "fastboot") => $"fastboot reboot-{target}",
            (true, null) => "fastboot reboot",
            (true, _) => $"fastboot reboot {target}",
            (false, null) => "adb reboot",
            (false, _) => $"adb reboot {target}",
        };

        Log("Reboot", $"Target: {serial} (currently {currentMode}). Command: {command}");
        if (isDryRun)
        {
            Log("Reboot", $"[DRY RUN] Would run: {command}");
            return new MaintenanceActionResult(true, $"[DRY RUN] Would run: {command}. No command was actually run.");
        }

        var result = useFastboot
            ? await _fastboot.RebootAsync(serial, target, ct)
            : await _adb.RebootAsync(serial, target, ct);
        return LogAndReturn("Reboot", command, result);
    }

    /// <summary>Only ever runs the standard "adb sideload &lt;file&gt;" -- never attempts to
    /// drive the device into sideload mode itself here (that's Reboot to Recovery's job,
    /// followed by the device entering sideload automatically or the user navigating the
    /// recovery menu by hand, exactly as the guided ROM install's own sideload step does).</summary>
    public async Task<MaintenanceActionResult> SideloadPackageAsync(string serial, string packagePath, ConnectionMode currentMode, bool isDryRun, CancellationToken ct = default)
    {
        if (!File.Exists(packagePath))
        {
            return new MaintenanceActionResult(false, $"Selected file does not exist: {packagePath}");
        }

        if (currentMode != ConnectionMode.AdbSideload)
        {
            return new MaintenanceActionResult(
                false,
                $"Device is not in ADB sideload mode (currently {currentMode}). Use \"Reboot to Recovery\" first, then " +
                "select Apply Update → Apply from ADB on the device if it doesn't enter sideload automatically.");
        }

        var command = $"adb sideload \"{Path.GetFileName(packagePath)}\"";
        Log("Sideload", $"Target: {serial}. Command: {command} (full path: {packagePath})");
        if (isDryRun)
        {
            Log("Sideload", $"[DRY RUN] Would run: {command}");
            return new MaintenanceActionResult(true, $"[DRY RUN] Would run: {command}. No command was actually run.");
        }

        var result = await _adb.SideloadAsync(serial, packagePath, line => _logger.Log(LogLevel.Debug, "Maintenance", line), SideloadTimeout, ct);
        return LogAndReturn("Sideload", command, result);
    }

    private void Log(string op, string message) => _logger.Log(LogLevel.Info, $"Maintenance:{op}", message);

    private MaintenanceActionResult LogAndReturn(string op, string command, Shell.ProcessResult result)
    {
        if (result.Succeeded)
        {
            Log(op, $"Succeeded: {command}");
            return new MaintenanceActionResult(true, $"Ran: {command}");
        }

        Log(op, $"Failed: {command} -- exit code {result.ExitCode}{(result.TimedOut ? " (timed out)" : "")}: {result.StandardError}");
        return new MaintenanceActionResult(false, $"Failed: {command} -- {result.StandardError}");
    }
}
