using AetherixRomMaster.Core.Installation;
using AetherixRomMaster.Core.Tests.TestDoubles;
using Xunit;

namespace AetherixRomMaster.Core.Tests.Installation;

/// <summary>
/// DryRunFlashExecutor takes no IProcessRunner/AdbClient/FastbootClient dependency at
/// all -- it is structurally incapable of touching a real device, not just configured
/// not to. These tests confirm every method still reports success (so the plan walks
/// to completion) while only ever writing to the logger.
/// </summary>
public class DryRunFlashExecutorTests
{
    private readonly DryRunFlashExecutor _executor = new();
    private readonly FakeInstallLogger _logger = new();

    [Fact]
    public async Task EveryOperation_SucceedsAndLogsWithoutTouchingADevice()
    {
        Assert.True(await _executor.RebootToFastbootdAsync("S1", _logger, CancellationToken.None));
        Assert.True(await _executor.FlashInitialInstallPackageAsync("S1", "/fake/initial.zip", _logger, CancellationToken.None));
        Assert.True(await _executor.FormatDataAsync("S1", _logger, CancellationToken.None));
        Assert.True(await _executor.RebootToRecoveryAsync("S1", _logger, CancellationToken.None));
        Assert.True(await _executor.SideloadRomAsync("S1", "/fake/rom.zip", _logger, CancellationToken.None));
        Assert.True(await _executor.RebootToSystemAsync("S1", _logger, CancellationToken.None));

        Assert.Equal(6, _logger.Entries.Count);
        Assert.All(_logger.Entries, e => Assert.Contains("[DRY RUN]", e.Message));
    }
}
