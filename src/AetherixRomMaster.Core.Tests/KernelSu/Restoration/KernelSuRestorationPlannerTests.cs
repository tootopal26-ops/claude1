using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.KernelSu.Restoration;
using AetherixRomMaster.Core.Profiles;
using Xunit;

namespace AetherixRomMaster.Core.Tests.KernelSu.Restoration;

public class KernelSuRestorationPlannerTests
{
    private const string RomName = "Project Infinity X (Infinity-X)";
    private const string BuildFingerprint = "motorola/cancunf/cancunf:16/XYZ/1:user/release-keys";

    private static RomProfile BuildRomProfile(string codename = "cancunf") => new()
    {
        RomName = RomName,
        TargetCodename = codename,
        InstallationMethod = InstallationMethod.FastbootUpdatePackageThenSideload,
        VerificationStatus = VerificationStatus.Unverified,
        Citations = [],
    };

    private static DeviceInfo BuildDevice(string codename = "cancunf", string? buildFingerprint = BuildFingerprint) => new()
    {
        ConnectionMode = ConnectionMode.Adb,
        Serial = "SERIAL123",
        Codename = codename,
        BuildFingerprint = buildFingerprint,
    };

    private static KernelSuProfile BuildVerifiedProfile(string codename = "cancunf", string romName = RomName, string? pinnedBuild = null) => new()
    {
        TargetCodename = codename,
        TargetRomName = romName,
        TargetRomBuildFingerprint = pinnedBuild,
        RequiredImageType = KernelImageType.InitBootImg,
        RequiredImageFileName = "init_boot.img",
        TargetPartition = "init_boot",
        VerificationStatus = VerificationStatus.Verified,
        Citations = [],
    };

    [Fact]
    public void BuildPlan_Blocked_WhenNoKernelSuProfileExists()
    {
        var plan = new KernelSuRestorationPlanner().BuildPlan(BuildDevice(), BuildRomProfile(), kernelSuProfile: null);

        Assert.False(plan.CanProceed);
        Assert.Contains("No KernelSU restoration profile", plan.BlockingReason);
    }

    [Fact]
    public void BuildPlan_Blocked_WhenProfileIsUnverified()
    {
        var profile = BuildVerifiedProfile() with { VerificationStatus = VerificationStatus.Unverified };

        var plan = new KernelSuRestorationPlanner().BuildPlan(BuildDevice(), BuildRomProfile(), profile);

        Assert.False(plan.CanProceed);
        Assert.Contains("Unverified", plan.BlockingReason);
    }

    [Fact]
    public void BuildPlan_Blocked_WhenDeviceCodenameDoesNotMatchRomProfile()
    {
        var plan = new KernelSuRestorationPlanner().BuildPlan(
            BuildDevice(codename: "othercodename"), BuildRomProfile(), BuildVerifiedProfile());

        Assert.False(plan.CanProceed);
    }

    [Fact]
    public void BuildPlan_Blocked_WhenKernelSuProfileTargetsADifferentRom()
    {
        var profile = BuildVerifiedProfile(romName: "Some Other ROM");

        var plan = new KernelSuRestorationPlanner().BuildPlan(BuildDevice(), BuildRomProfile(), profile);

        Assert.False(plan.CanProceed);
    }

    [Fact]
    public void BuildPlan_Blocked_WhenPinnedBuildFingerprintDoesNotMatchDevice()
    {
        var profile = BuildVerifiedProfile(pinnedBuild: "some-other-build-fingerprint");

        var plan = new KernelSuRestorationPlanner().BuildPlan(BuildDevice(), BuildRomProfile(), profile);

        Assert.False(plan.CanProceed);
        Assert.Contains("pinned to build fingerprint", plan.BlockingReason);
    }

    [Fact]
    public void BuildPlan_Proceeds_WhenPinnedBuildFingerprintMatchesDevice()
    {
        var profile = BuildVerifiedProfile(pinnedBuild: BuildFingerprint);

        var plan = new KernelSuRestorationPlanner().BuildPlan(BuildDevice(), BuildRomProfile(), profile);

        Assert.True(plan.CanProceed);
    }

    [Theory]
    [InlineData(true, false, false)]
    [InlineData(false, true, false)]
    [InlineData(false, false, true)]
    public void BuildPlan_Blocked_WhenVerifiedProfileIsMissingAnyRequiredField(bool clearType, bool clearFileName, bool clearPartition)
    {
        var profile = BuildVerifiedProfile();
        if (clearType) profile = profile with { RequiredImageType = null };
        if (clearFileName) profile = profile with { RequiredImageFileName = null };
        if (clearPartition) profile = profile with { TargetPartition = null };

        var plan = new KernelSuRestorationPlanner().BuildPlan(BuildDevice(), BuildRomProfile(), profile);

        Assert.False(plan.CanProceed);
    }

    [Fact]
    public void BuildPlan_Proceeds_WhenProfileIsFullyVerifiedAndMatches()
    {
        var plan = new KernelSuRestorationPlanner().BuildPlan(BuildDevice(), BuildRomProfile(), BuildVerifiedProfile());

        Assert.True(plan.CanProceed);
        Assert.Null(plan.BlockingReason);
        Assert.Equal(KernelImageType.InitBootImg, plan.RequiredImageType);
        Assert.Equal("init_boot.img", plan.RequiredImageFileName);
        Assert.Equal("init_boot", plan.TargetPartition);
    }
}
