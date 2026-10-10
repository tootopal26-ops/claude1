using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.Installation;
using AetherixRomMaster.Core.KernelSu;
using AetherixRomMaster.Core.Profiles;
using AetherixRomMaster.Core.Rom;
using AetherixRomMaster.Core.Shell;

namespace AetherixRomMaster.Core.Tests.TestDoubles;

/// <summary>Wires a real InstallStateMachine against test doubles for device detection
/// and flashing, so branching logic can be exercised without real hardware or timing.</summary>
public sealed class InstallHarness
{
    public ScriptedDeviceDetectionService Detection { get; } = new();
    public FakeFlashExecutor FlashExecutor { get; } = new();
    public FakeInstallLogger Logger { get; } = new();
    public FakeProcessRunner RootProcessRunner { get; } = new();

    public InstallStateMachine StateMachine { get; }

    public InstallHarness()
    {
        RootProcessRunner.Handler = (_, _) => new ProcessResult { ExitCode = 0, StandardOutput = string.Empty, StandardError = string.Empty };

        var adb = new AdbClient(RootProcessRunner, "adb");
        var rootDetector = new RootDetector(adb);

        StateMachine = new InstallStateMachine(
            Detection,
            new DeviceCompatibilityChecker(),
            new RomValidationService(),
            new KernelSuCompatibilityChecker(),
            rootDetector,
            FlashExecutor);
    }

    public static DeviceInfo AdbCancunf(string fingerprint = "motorola/cancunf/cancunf:15/T1/0001:user/release-keys", string serial = "S1") => new()
    {
        ConnectionMode = ConnectionMode.Adb,
        Serial = serial,
        Manufacturer = "Motorola",
        Model = "Moto G64 5G",
        Codename = "cancunf",
        AndroidVersion = "15",
        BuildFingerprint = fingerprint,
        BootloaderLockState = BootloaderLockState.Unlocked,
        KernelVersion = "5.15.104-android13-8-weighu+",
    };

    public static DeviceInfo FastbootUserspaceCancunf(string serial = "S1") => new()
    {
        ConnectionMode = ConnectionMode.FastbootUserspace,
        Serial = serial,
        Codename = "cancunf",
        BootloaderLockState = BootloaderLockState.Unlocked,
    };

    public static DeviceInfo AdbSideloadCancunf(string serial = "S1") => new()
    {
        ConnectionMode = ConnectionMode.AdbSideload,
        Serial = serial,
    };

    public InstallContext BuildContext(
        InstallMode mode,
        RomProfile romProfile,
        string romZipPath,
        string? initialInstallZipPath,
        bool filesAlreadyLocal = true)
    {
        var plan = new InstallPlanBuilder().Build(romProfile, filesAlreadyLocal);
        return new InstallContext
        {
            Mode = mode,
            DeviceProfile = TestProfiles.Cancunf(),
            RomProfile = romProfile,
            Plan = plan,
            Logger = Logger,
            RomZipPath = romZipPath,
            InitialInstallZipPath = initialInstallZipPath,
        };
    }
}
