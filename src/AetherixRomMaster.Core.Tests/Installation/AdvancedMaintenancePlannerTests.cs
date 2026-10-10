using AetherixRomMaster.Core.Installation;
using AetherixRomMaster.Core.Profiles;
using Xunit;

namespace AetherixRomMaster.Core.Tests.Installation;

public class AdvancedMaintenancePlannerTests
{
    private static RomProfile BuildRomProfile(VerificationStatus status, bool requiresDataFormat) => new()
    {
        RomName = "Project Infinity X",
        TargetCodename = "cancunf",
        InstallationMethod = InstallationMethod.FastbootUpdatePackageThenSideload,
        RequiresDataFormat = requiresDataFormat,
        VerificationStatus = status,
        Citations = [],
    };

    [Fact]
    public void BuildOperations_NeverEnablesEraseMetadata_RegardlessOfRomProfile()
    {
        foreach (RomProfile? profile in new RomProfile?[] { null, BuildRomProfile(VerificationStatus.Verified, true), BuildRomProfile(VerificationStatus.Unverified, false) })
        {
            var operations = new AdvancedMaintenancePlanner().BuildOperations(profile);
            var metadata = Assert.Single(operations, o => o.Id == "erase-metadata");

            Assert.False(metadata.IsAvailable);
            Assert.Equal(MaintenanceOperationCategory.UnsupportedUnverified, metadata.Category);
            Assert.Contains("Pixel", metadata.Explanation);
        }
    }

    [Fact]
    public void BuildOperations_NeverExposesAnFrpOrUnlockOperation()
    {
        var operations = new AdvancedMaintenancePlanner().BuildOperations(romProfile: null);

        Assert.DoesNotContain(operations, o => o.Name.Contains("FRP", StringComparison.OrdinalIgnoreCase));
        Assert.DoesNotContain(operations, o => o.Name.Contains("unlock", StringComparison.OrdinalIgnoreCase));
        Assert.DoesNotContain(operations, o => o.Name.Contains("relock", StringComparison.OrdinalIgnoreCase));
    }

    [Theory]
    [InlineData("reboot-recovery")]
    [InlineData("reboot-bootloader")]
    [InlineData("reboot-fastbootd")]
    [InlineData("reboot-system")]
    public void BuildOperations_RebootsAreAlwaysSafeAndAvailable_RegardlessOfRomProfile(string operationId)
    {
        var operations = new AdvancedMaintenancePlanner().BuildOperations(romProfile: null);
        var op = Assert.Single(operations, o => o.Id == operationId);

        Assert.True(op.IsAvailable);
        Assert.Equal(MaintenanceOperationCategory.SafeNonDestructive, op.Category);
    }

    [Fact]
    public void BuildOperations_FormatDataExplanation_MentionsVerifiedRomProfile_WhenItRequiresFormatting()
    {
        var operations = new AdvancedMaintenancePlanner().BuildOperations(BuildRomProfile(VerificationStatus.Verified, requiresDataFormat: true));
        var formatData = Assert.Single(operations, o => o.Id == "format-data");

        Assert.True(formatData.IsAvailable);
        Assert.Equal(MaintenanceOperationCategory.Destructive, formatData.Category);
        Assert.Contains("requires this step", formatData.Explanation);
    }

    [Fact]
    public void BuildOperations_FormatDataExplanation_DoesNotClaimRequirement_WhenProfileIsUnverified()
    {
        var operations = new AdvancedMaintenancePlanner().BuildOperations(BuildRomProfile(VerificationStatus.Unverified, requiresDataFormat: true));
        var formatData = Assert.Single(operations, o => o.Id == "format-data");

        Assert.True(formatData.IsAvailable); // still a generic, always-available maintenance action
        Assert.DoesNotContain("requires this step", formatData.Explanation);
    }

    [Fact]
    public void BuildOperations_AdbSideload_ExplainsSideloadModePrerequisite()
    {
        var operations = new AdvancedMaintenancePlanner().BuildOperations(romProfile: null);
        var sideload = Assert.Single(operations, o => o.Id == "adb-sideload");

        Assert.True(sideload.IsAvailable);
        Assert.Equal(MaintenanceOperationCategory.Destructive, sideload.Category);
        Assert.Contains("sideload mode", sideload.Explanation, StringComparison.OrdinalIgnoreCase);
    }
}
