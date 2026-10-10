using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.Installation;
using AetherixRomMaster.Core.KernelSu.Restoration;
using AetherixRomMaster.Core.Profiles;
using Xunit;

namespace AetherixRomMaster.Core.Tests.Installation;

public class AutomationCapabilityPlannerTests
{
    private static DeviceInfo BuildDevice() => new()
    {
        ConnectionMode = ConnectionMode.Adb,
        Serial = "S1",
        Codename = "cancunf",
    };

    private static RomProfile BuildRomProfile(VerificationStatus status, bool requiresDataFormat = true) => new()
    {
        RomName = "Project Infinity X",
        TargetCodename = "cancunf",
        InstallationMethod = InstallationMethod.FastbootUpdatePackageThenSideload,
        RequiresInitialInstallPackage = true,
        RequiresDataFormat = requiresDataFormat,
        VerificationStatus = status,
        Citations = [],
    };

    private static KernelSuProfile BuildVerifiedKernelSuProfile() => new()
    {
        TargetCodename = "cancunf",
        TargetRomName = "Project Infinity X",
        RequiredImageType = KernelImageType.InitBootImg,
        RequiredImageFileName = "init_boot.img",
        TargetPartition = "init_boot",
        VerificationStatus = VerificationStatus.Verified,
        Citations = [],
    };

    [Fact]
    public void BuildReport_MarksCoreStepsBlocked_WhenRomProfileIsUnverified()
    {
        var report = new AutomationCapabilityPlanner().BuildReport(
            BuildDevice(), BuildRomProfile(VerificationStatus.Unverified), kernelSuProfile: null, currentRoot: null);

        var romVerification = Assert.Single(report, i => i.StepName == "ROM/firmware verification");
        Assert.Equal(AutomationCapabilityLevel.Blocked, romVerification.Level);

        var fastboot = Assert.Single(report, i => i.StepName == "Fastbootd / fastboot flashing");
        Assert.Equal(AutomationCapabilityLevel.Blocked, fastboot.Level);
    }

    [Fact]
    public void BuildReport_MarksCoreStepsAutomatic_WhenRomProfileIsVerified()
    {
        var report = new AutomationCapabilityPlanner().BuildReport(
            BuildDevice(), BuildRomProfile(VerificationStatus.Verified), kernelSuProfile: null, currentRoot: null);

        var romVerification = Assert.Single(report, i => i.StepName == "ROM/firmware verification");
        Assert.Equal(AutomationCapabilityLevel.Automatic, romVerification.Level);

        var fastboot = Assert.Single(report, i => i.StepName == "Fastbootd / fastboot flashing");
        Assert.Equal(AutomationCapabilityLevel.Automatic, fastboot.Level);
    }

    [Fact]
    public void BuildReport_MarksCoreStepsAutomatic_WhenRomProfileIsPendingManualConfirmation()
    {
        // PendingManualConfirmation (a human confirmed the facts directly from the official
        // source) must not be reported as Blocked the same way Unverified is -- the real,
        // evidence-based checksum/device/package checks are what actually gate real
        // flashing, not this status. Mirrors RomValidationService's identical treatment.
        var report = new AutomationCapabilityPlanner().BuildReport(
            BuildDevice(), BuildRomProfile(VerificationStatus.PendingManualConfirmation), kernelSuProfile: null, currentRoot: null);

        var romVerification = Assert.Single(report, i => i.StepName == "ROM/firmware verification");
        Assert.Equal(AutomationCapabilityLevel.Automatic, romVerification.Level);
        Assert.Contains("Pending Manual Confirmation", romVerification.Explanation);

        var fastboot = Assert.Single(report, i => i.StepName == "Fastbootd / fastboot flashing");
        Assert.Equal(AutomationCapabilityLevel.Automatic, fastboot.Level);
    }

    [Fact]
    public void BuildReport_FormatDataRequiresConfirmation_WhenRomRequiresIt()
    {
        var report = new AutomationCapabilityPlanner().BuildReport(
            BuildDevice(), BuildRomProfile(VerificationStatus.Verified, requiresDataFormat: true), kernelSuProfile: null, currentRoot: null);

        var formatData = Assert.Single(report, i => i.StepName == "Format Data");
        Assert.Equal(AutomationCapabilityLevel.UserConfirmationRequired, formatData.Level);
    }

    [Fact]
    public void BuildReport_FormatDataIsAutomatic_WhenRomDoesNotRequireIt()
    {
        var report = new AutomationCapabilityPlanner().BuildReport(
            BuildDevice(), BuildRomProfile(VerificationStatus.Verified, requiresDataFormat: false), kernelSuProfile: null, currentRoot: null);

        var formatData = Assert.Single(report, i => i.StepName == "Format Data");
        Assert.Equal(AutomationCapabilityLevel.Automatic, formatData.Level);
    }

    [Fact]
    public void BuildReport_KernelSuIsAutomatic_WhenAlreadyIntegratedAndOperational()
    {
        var currentRoot = new RootDetectionResult(RootSolutionType.KernelSU, "active");

        var report = new AutomationCapabilityPlanner().BuildReport(
            BuildDevice(), BuildRomProfile(VerificationStatus.Verified), kernelSuProfile: null, currentRoot);

        var kernelSu = Assert.Single(report, i => i.StepName.Contains("KernelSU"));
        Assert.Equal(AutomationCapabilityLevel.Automatic, kernelSu.Level);
    }

    [Fact]
    public void BuildReport_KernelSuIsBlocked_WhenNoVerifiedRestorationProfileExists()
    {
        var currentRoot = new RootDetectionResult(RootSolutionType.None, "no evidence");

        var report = new AutomationCapabilityPlanner().BuildReport(
            BuildDevice(), BuildRomProfile(VerificationStatus.Verified), kernelSuProfile: null, currentRoot);

        var kernelSu = Assert.Single(report, i => i.StepName.Contains("KernelSU"));
        Assert.Equal(AutomationCapabilityLevel.Blocked, kernelSu.Level);
    }

    [Fact]
    public void BuildReport_KernelSuRequiresManualInteraction_WhenRestorationProfileVerifiedButNotYetIntegrated()
    {
        var currentRoot = new RootDetectionResult(RootSolutionType.None, "no evidence");

        var report = new AutomationCapabilityPlanner().BuildReport(
            BuildDevice(), BuildRomProfile(VerificationStatus.Verified), BuildVerifiedKernelSuProfile(), currentRoot);

        var kernelSu = Assert.Single(report, i => i.StepName.Contains("KernelSU"));
        Assert.Equal(AutomationCapabilityLevel.ManualInteractionRequired, kernelSu.Level);
        Assert.Contains("KernelSU Manager", kernelSu.Explanation);
    }

    [Fact]
    public void Marker_IsCheckmark_OnlyForAutomatic()
    {
        var automatic = new AutomationCapabilityItem("x", AutomationCapabilityLevel.Automatic, "");
        var blocked = new AutomationCapabilityItem("x", AutomationCapabilityLevel.Blocked, "");

        Assert.Equal("✓", automatic.Marker);
        Assert.Equal("⚠", blocked.Marker);
    }
}
