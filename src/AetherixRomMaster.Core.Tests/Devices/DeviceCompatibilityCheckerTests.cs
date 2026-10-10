using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.Tests.TestDoubles;
using Xunit;

namespace AetherixRomMaster.Core.Tests.Devices;

public class DeviceCompatibilityCheckerTests
{
    private readonly DeviceCompatibilityChecker _checker = new();

    [Fact]
    public void Check_Accepts_MatchingCancunfCodename()
    {
        var device = new DeviceInfo { ConnectionMode = ConnectionMode.Adb, Codename = "cancunf" };

        var result = _checker.Check(device, TestProfiles.Cancunf());

        Assert.True(result.IsSupported);
    }

    [Theory]
    [InlineData("marble")]
    [InlineData("cheetah")]
    [InlineData("")]
    public void Check_Rejects_WrongDeviceCodename(string otherCodename)
    {
        var device = new DeviceInfo
        {
            ConnectionMode = ConnectionMode.Adb,
            Codename = otherCodename.Length == 0 ? null : otherCodename,
            Model = "Some Other Phone", // marketing name must never substitute for codename matching
        };

        var result = _checker.Check(device, TestProfiles.Cancunf());

        Assert.False(result.IsSupported);
    }

    [Fact]
    public void Check_Rejects_WhenDisconnected()
    {
        var result = _checker.Check(DeviceInfo.Disconnected, TestProfiles.Cancunf());

        Assert.False(result.IsSupported);
    }

    [Fact]
    public void Check_NeverTrustsModelNameAlone_WhenCodenameMismatches()
    {
        // A device that *claims* a Moto G64-like model string but reports a different codename
        // must still be rejected -- marketing names are not a trust signal.
        var device = new DeviceInfo { ConnectionMode = ConnectionMode.Adb, Codename = "other_codename", Model = "moto g64 5g" };

        var result = _checker.Check(device, TestProfiles.Cancunf());

        Assert.False(result.IsSupported);
    }
}
