using AetherixRomMaster.Core.KernelSu;
using AetherixRomMaster.Core.Tests.TestDoubles;
using Xunit;

namespace AetherixRomMaster.Core.Tests.KernelSu;

public class KernelSuCompatibilityCheckerTests
{
    private readonly KernelSuCompatibilityChecker _checker = new();

    [Theory]
    [InlineData("4.19.157")]
    [InlineData("4.9.337")]
    [InlineData(null)]
    public async Task AssessAsync_ReportsUnsupported_ForNonGkiOrOldKernels(string? kernelVersion)
    {
        var assessment = await _checker.AssessAsync(targetBootImagePath: null, kernelVersion, TestProfiles.InfinityXVerified());

        Assert.Equal(KernelSuRecommendedAction.Unsupported, assessment.RecommendedAction);
        Assert.True(assessment.RequiresManualStop);
    }

    [Fact]
    public async Task AssessAsync_RequiresManualVerification_ForGkiKernelWithoutBundledMarkers()
    {
        var assessment = await _checker.AssessAsync(targetBootImagePath: null, "5.15.104-android13-8-weighu+", TestProfiles.InfinityXVerified());

        Assert.Equal(KernelSuRecommendedAction.ManualVerifiedKernelRequired, assessment.RecommendedAction);
        Assert.True(assessment.RequiresManualStop);
        Assert.True(assessment.KernelLooksGki);
    }

    [Fact]
    public async Task AssessAsync_UsesBundledKernelSu_WhenBootImageContainsMarkers()
    {
        var tempDir = Directory.CreateTempSubdirectory("aetherix-ksu-tests-").FullName;
        try
        {
            var bootImagePath = Path.Combine(tempDir, "boot.img");
            await File.WriteAllTextAsync(bootImagePath, "some binary noise ... KSU_VERSION=12345 ... more noise");

            var assessment = await _checker.AssessAsync(bootImagePath, "5.15.104-android13-8-weighu+", TestProfiles.InfinityXVerified());

            Assert.Equal(KernelSuRecommendedAction.UseBundledKernelSuAsIs, assessment.RecommendedAction);
            Assert.False(assessment.RequiresManualStop);
            Assert.True(assessment.BootImageHasKernelSuMarkers);
        }
        finally
        {
            Directory.Delete(tempDir, recursive: true);
        }
    }

    [Fact]
    public async Task AssessAsync_NeverClaimsSuccess_WhenKernelVersionIsUnknown()
    {
        var assessment = await _checker.AssessAsync(targetBootImagePath: null, currentKernelVersion: null, TestProfiles.InfinityXVerified());

        Assert.True(assessment.RequiresManualStop);
        Assert.NotEqual(KernelSuRecommendedAction.UseBundledKernelSuAsIs, assessment.RecommendedAction);
    }
}
