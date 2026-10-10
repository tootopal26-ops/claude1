using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.Logging;

namespace AetherixRomMaster.Core.Installation;

/// <summary>
/// Executes real adb/fastboot commands against the connected device. Every method
/// returns false on any non-zero exit or timeout and logs the full output — it never
/// retries or "tries the next thing" on its own; that decision belongs to the state
/// machine, which treats a false here as FAILED and stops.
/// </summary>
public sealed class RealFlashExecutor : IFlashExecutor
{
    private static readonly TimeSpan FlashTimeout = TimeSpan.FromMinutes(10);
    private static readonly TimeSpan SideloadTimeout = TimeSpan.FromMinutes(20);
    private static readonly TimeSpan RebootTimeout = TimeSpan.FromSeconds(30);

    private readonly AdbClient _adb;
    private readonly FastbootClient _fastboot;

    public RealFlashExecutor(AdbClient adb, FastbootClient fastboot)
    {
        _adb = adb;
        _fastboot = fastboot;
    }

    public async Task<bool> RebootToFastbootdAsync(string serial, IInstallLogger logger, CancellationToken ct)
    {
        logger.Log(LogLevel.Info, "Fastboot", $"Rebooting {serial} to fastbootd (userspace fastboot)...");
        var result = await _adb.RebootAsync(serial, "fastboot", ct);
        LogResult(logger, "Fastboot", result);
        return result.Succeeded;
    }

    public async Task<bool> FlashInitialInstallPackageAsync(string serial, string zipPath, IInstallLogger logger, CancellationToken ct)
    {
        logger.Log(LogLevel.Info, "Fastboot", $"Flashing initial-install package: {zipPath}");
        var result = await _fastboot.UpdateAsync(
            serial, zipPath, skipReboot: true,
            line => logger.Log(LogLevel.Debug, "Fastboot", line),
            FlashTimeout, ct);
        LogResult(logger, "Fastboot", result);
        return result.Succeeded;
    }

    public async Task<bool> FormatDataAsync(string serial, IInstallLogger logger, CancellationToken ct)
    {
        logger.Log(LogLevel.Warning, "Fastboot", "Formatting /data (fastboot -w) — this erases all user data on the device, as confirmed earlier.");
        var result = await _fastboot.WipeUserdataAsync(
            serial,
            line => logger.Log(LogLevel.Debug, "Fastboot", line),
            FlashTimeout, ct);
        LogResult(logger, "Fastboot", result);
        return result.Succeeded;
    }

    public async Task<bool> RebootToRecoveryAsync(string serial, IInstallLogger logger, CancellationToken ct)
    {
        logger.Log(LogLevel.Info, "Fastboot", $"Rebooting {serial} to recovery...");
        var result = await _fastboot.RebootAsync(serial, "recovery", ct);
        LogResult(logger, "Fastboot", result);
        return result.Succeeded;
    }

    public async Task<bool> SideloadRomAsync(string serial, string zipPath, IInstallLogger logger, CancellationToken ct)
    {
        logger.Log(LogLevel.Info, "Adb", $"Sideloading ROM package: {zipPath}");
        var result = await _adb.SideloadAsync(
            serial, zipPath,
            line => logger.Log(LogLevel.Debug, "Adb", line),
            SideloadTimeout, ct);
        LogResult(logger, "Adb", result);
        return result.Succeeded;
    }

    public async Task<bool> RebootToSystemAsync(string serial, IInstallLogger logger, CancellationToken ct)
    {
        logger.Log(LogLevel.Info, "Fastboot", $"Rebooting {serial} into the installed system...");
        var result = await _fastboot.RebootAsync(serial, null, ct);
        LogResult(logger, "Fastboot", result);
        return result.Succeeded;
    }

    private static void LogResult(IInstallLogger logger, string source, Shell.ProcessResult result)
    {
        if (!string.IsNullOrWhiteSpace(result.StandardOutput))
        {
            logger.Log(LogLevel.Debug, source, result.StandardOutput.Trim());
        }
        if (!string.IsNullOrWhiteSpace(result.StandardError))
        {
            logger.Log(result.Succeeded ? LogLevel.Debug : LogLevel.Error, source, result.StandardError.Trim());
        }
        if (!result.Succeeded)
        {
            logger.Log(LogLevel.Error, source, $"Command failed (exit code {result.ExitCode}{(result.TimedOut ? ", timed out" : "")}).");
        }
    }
}
