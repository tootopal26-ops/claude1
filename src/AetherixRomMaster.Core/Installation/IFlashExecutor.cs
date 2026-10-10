using AetherixRomMaster.Core.Logging;

namespace AetherixRomMaster.Core.Installation;

/// <summary>
/// Every destructive device operation the installer can perform, behind one seam.
/// DryRunFlashExecutor logs what it would do and always succeeds without touching the
/// device; RealFlashExecutor calls the real adb/fastboot binaries. The state machine
/// never calls AdbClient/FastbootClient directly for a destructive step — only through
/// this interface, so dry-run and real mode can never accidentally diverge in sequence.
/// </summary>
public interface IFlashExecutor
{
    Task<bool> RebootToFastbootdAsync(string serial, IInstallLogger logger, CancellationToken ct);

    Task<bool> FlashInitialInstallPackageAsync(string serial, string zipPath, IInstallLogger logger, CancellationToken ct);

    Task<bool> FormatDataAsync(string serial, IInstallLogger logger, CancellationToken ct);

    Task<bool> RebootToRecoveryAsync(string serial, IInstallLogger logger, CancellationToken ct);

    Task<bool> SideloadRomAsync(string serial, string zipPath, IInstallLogger logger, CancellationToken ct);

    Task<bool> RebootToSystemAsync(string serial, IInstallLogger logger, CancellationToken ct);
}
