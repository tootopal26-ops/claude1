using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.KernelSu;
using AetherixRomMaster.Core.KernelSu.Restoration;
using AetherixRomMaster.Core.Logging;
using AetherixRomMaster.Core.Profiles;
using AetherixRomMaster.Core.Recovery;
using AetherixRomMaster.Core.Rom;
using AetherixRomMaster.Core.Shell;

namespace AetherixRomMaster.Core.Installation;

/// <summary>
/// Wires up every service the installer needs from a resolved set of platform tools and
/// a logger, for both the WPF app and tests. This is the one place that decides which
/// IFlashExecutor a given InstallMode gets — DryRun always gets DryRunFlashExecutor,
/// Real always gets RealFlashExecutor talking to the real adb/fastboot binaries.
/// </summary>
public sealed class InstallEngine
{
    public AdbClient Adb { get; }
    public FastbootClient Fastboot { get; }
    public DeviceDetectionService DeviceDetection { get; }
    public DeviceCompatibilityChecker DeviceCompatibility { get; }
    public RomValidationService RomValidation { get; }
    public KernelSuCompatibilityChecker KernelSuChecker { get; }
    public RootDetector RootDetector { get; }
    public ProfileRepository Profiles { get; }
    public RecoveryPlanner RecoveryPlanner { get; }
    public PreFlightSafetyChecker PreFlightSafetyChecker { get; }

    public InstallEngine(string adbPath, string fastbootPath, string profilesDirectory, IProcessRunner? processRunner = null)
    {
        var runner = processRunner ?? new ProcessRunner();
        Adb = new AdbClient(runner, adbPath);
        Fastboot = new FastbootClient(runner, fastbootPath);
        RootDetector = new RootDetector(Adb);
        DeviceDetection = new DeviceDetectionService(Adb, Fastboot, RootDetector);
        DeviceCompatibility = new DeviceCompatibilityChecker();
        RomValidation = new RomValidationService();
        KernelSuChecker = new KernelSuCompatibilityChecker();
        Profiles = new ProfileRepository(profilesDirectory);
        RecoveryPlanner = new RecoveryPlanner(DeviceDetection);
        PreFlightSafetyChecker = new PreFlightSafetyChecker(DeviceDetection, RecoveryPlanner, DeviceCompatibility);
    }

    public InstallStateMachine CreateStateMachine(InstallMode mode)
    {
        IFlashExecutor executor = mode == InstallMode.DryRun
            ? new DryRunFlashExecutor()
            : new RealFlashExecutor(Adb, Fastboot);

        return new InstallStateMachine(DeviceDetection, DeviceCompatibility, RomValidation, KernelSuChecker, RootDetector, executor);
    }

    public KernelSuRestorationSession CreateKernelSuRestorationSession(IInstallLogger logger, bool isDryRun) =>
        new(Adb, Fastboot, RootDetector, logger, isDryRun);

    public InstallContext CreateContext(
        InstallMode mode,
        DeviceProfile deviceProfile,
        RomProfile romProfile,
        string romZipPath,
        IInstallLogger logger,
        string? initialInstallZipPath = null,
        bool filesAlreadyLocal = true,
        string? explicitRomChecksum = null,
        string? explicitInitialInstallChecksum = null)
    {
        var plan = new InstallPlanBuilder().Build(romProfile, filesAlreadyLocal);
        return new InstallContext
        {
            Mode = mode,
            DeviceProfile = deviceProfile,
            RomProfile = romProfile,
            Plan = plan,
            Logger = logger,
            RomZipPath = romZipPath,
            InitialInstallZipPath = initialInstallZipPath,
            ExplicitRomChecksum = explicitRomChecksum,
            ExplicitInitialInstallChecksum = explicitInitialInstallChecksum,
        };
    }
}
