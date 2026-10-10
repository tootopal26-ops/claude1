using AetherixRomMaster.Core.Logging;

namespace AetherixRomMaster.Core.Installation;

/// <summary>
/// Never issues a single adb/fastboot command that could alter the device. Every method
/// just logs the exact command that real mode would run and returns success, so the full
/// plan can be walked and displayed end-to-end with zero risk.
/// </summary>
public sealed class DryRunFlashExecutor : IFlashExecutor
{
    public Task<bool> RebootToFastbootdAsync(string serial, IInstallLogger logger, CancellationToken ct)
        => LogPlannedAsync(logger, $"[DRY RUN] Would run: adb -s {serial} reboot fastboot");

    public Task<bool> FlashInitialInstallPackageAsync(string serial, string zipPath, IInstallLogger logger, CancellationToken ct)
        => LogPlannedAsync(logger, $"[DRY RUN] Would run: fastboot -s {serial} --skip-reboot update \"{zipPath}\"");

    public Task<bool> FormatDataAsync(string serial, IInstallLogger logger, CancellationToken ct)
        => LogPlannedAsync(logger, $"[DRY RUN] Would run: fastboot -s {serial} -w   (erases /data — user-confirmed earlier in the plan)");

    public Task<bool> RebootToRecoveryAsync(string serial, IInstallLogger logger, CancellationToken ct)
        => LogPlannedAsync(logger, $"[DRY RUN] Would run: fastboot -s {serial} reboot recovery");

    public Task<bool> SideloadRomAsync(string serial, string zipPath, IInstallLogger logger, CancellationToken ct)
        => LogPlannedAsync(logger, $"[DRY RUN] Would run: adb -s {serial} sideload \"{zipPath}\"");

    public Task<bool> RebootToSystemAsync(string serial, IInstallLogger logger, CancellationToken ct)
        => LogPlannedAsync(logger, $"[DRY RUN] Would run: fastboot -s {serial} reboot");

    private static Task<bool> LogPlannedAsync(IInstallLogger logger, string message)
    {
        logger.Log(LogLevel.Info, "DryRun", message);
        return Task.FromResult(true);
    }
}
