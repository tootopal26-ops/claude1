using AetherixRomMaster.Core.Installation;
using AetherixRomMaster.Core.Tests.TestDoubles;
using Xunit;

namespace AetherixRomMaster.Core.Tests.Installation;

public class InstallPlanBuilderTests
{
    private readonly InstallPlanBuilder _builder = new();

    [Fact]
    public void Build_IncludesInitialInstallState_WhenRomProfileRequiresIt()
    {
        var plan = _builder.Build(TestProfiles.InfinityXVerified(), filesAlreadyLocal: true);

        Assert.Contains(InstallState.InstallRequiredInitialPackage, plan.States);
    }

    [Fact]
    public void Build_OmitsInitialInstallState_WhenRomProfileDoesNotRequireIt()
    {
        var profile = TestProfiles.InfinityXVerified() with { RequiresInitialInstallPackage = false };

        var plan = _builder.Build(profile, filesAlreadyLocal: true);

        Assert.DoesNotContain(InstallState.InstallRequiredInitialPackage, plan.States);
    }

    [Fact]
    public void Build_OmitsDownloadState_WhenFilesAreAlreadyLocal()
    {
        var plan = _builder.Build(TestProfiles.InfinityXVerified(), filesAlreadyLocal: true);

        Assert.DoesNotContain(InstallState.DownloadRequiredFiles, plan.States);
    }

    [Fact]
    public void Build_IncludesDownloadState_WhenFilesAreNotYetLocal()
    {
        var plan = _builder.Build(TestProfiles.InfinityXVerified(), filesAlreadyLocal: false);

        Assert.Contains(InstallState.DownloadRequiredFiles, plan.States);
    }

    [Fact]
    public void Build_OmitsFormatDataState_WhenRomProfileDoesNotRequireIt()
    {
        var profile = TestProfiles.InfinityXVerified() with { RequiresDataFormat = false };

        var plan = _builder.Build(profile, filesAlreadyLocal: true);

        Assert.DoesNotContain(InstallState.FormatDataIfRequired, plan.States);
    }

    [Fact]
    public void Build_AlwaysStartsWithInitializeAndEndsWithComplete()
    {
        var plan = _builder.Build(TestProfiles.InfinityXVerified(), filesAlreadyLocal: true);

        Assert.Equal(InstallState.Initialize, plan.States[0]);
        Assert.Equal(InstallState.Complete, plan.States[^1]);
    }

    [Fact]
    public void Build_ProducesOneOperationPerState()
    {
        var plan = _builder.Build(TestProfiles.InfinityXVerified(), filesAlreadyLocal: true);

        Assert.Equal(plan.States.Count, plan.Operations.Count);
    }
}
