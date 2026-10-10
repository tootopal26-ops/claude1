using AetherixRomMaster.Core.Installation;
using AetherixRomMaster.Core.Logging;

namespace AetherixRomMaster.Core.Tests.TestDoubles;

/// <summary>Records every call and lets a test force any single step to fail, to exercise
/// the state machine's "never auto-continue after an unexpected flashing error" rule.</summary>
public sealed class FakeFlashExecutor : IFlashExecutor
{
    public List<string> Calls { get; } = [];
    public HashSet<string> FailingOperations { get; } = [];

    public Task<bool> RebootToFastbootdAsync(string serial, IInstallLogger logger, CancellationToken ct) => Record("RebootToFastbootd");

    public Task<bool> FlashInitialInstallPackageAsync(string serial, string zipPath, IInstallLogger logger, CancellationToken ct) => Record("FlashInitialInstallPackage");

    public Task<bool> FormatDataAsync(string serial, IInstallLogger logger, CancellationToken ct) => Record("FormatData");

    public Task<bool> RebootToRecoveryAsync(string serial, IInstallLogger logger, CancellationToken ct) => Record("RebootToRecovery");

    public Task<bool> SideloadRomAsync(string serial, string zipPath, IInstallLogger logger, CancellationToken ct) => Record("SideloadRom");

    public Task<bool> RebootToSystemAsync(string serial, IInstallLogger logger, CancellationToken ct) => Record("RebootToSystem");

    private Task<bool> Record(string operation)
    {
        Calls.Add(operation);
        return Task.FromResult(!FailingOperations.Contains(operation));
    }
}
