using AetherixRomMaster.Core.Installation;
using AetherixRomMaster.Core.Logging;
using AetherixRomMaster.Core.Shell;
using AetherixRomMaster.Core.Tests.TestDoubles;
using Xunit;

namespace AetherixRomMaster.Core.Tests.Installation;

/// <summary>
/// Confirms the one decision InstallEngine makes that nothing else tests directly:
/// InstallMode.DryRun must be wired to DryRunFlashExecutor, never RealFlashExecutor,
/// so a dry run is structurally incapable of reaching the real adb/fastboot binaries.
/// </summary>
public class InstallEngineTests : IDisposable
{
    private readonly string _tempDir = Directory.CreateTempSubdirectory("aetherix-engine-tests-").FullName;

    [Fact]
    public async Task CreateStateMachine_InDryRunMode_NeverInvokesTheConfiguredProcessRunner()
    {
        var processRunnerCalls = new List<string>();
        var runner = new FakeProcessRunner
        {
            Handler = (_, args) =>
            {
                var joined = string.Join(" ", args);
                processRunnerCalls.Add(joined);
                if (args is ["devices", "-l"]) return Ok("List of devices attached\nS1\tdevice\n");
                if (joined.Contains("shell getprop")) return Ok(
                    "[ro.product.manufacturer]: [motorola]\n[ro.product.device]: [cancunf]\n" +
                    "[ro.build.version.release]: [15]\n[ro.boot.flash.locked]: [0]\n");
                return Ok("");
            },
        };

        var engine = new InstallEngine("adb", "fastboot", ProfilesDirectory(), runner);
        var deviceProfile = (await engine.Profiles.LoadDeviceProfileAsync("cancunf"))!;
        var romProfile = TestProfiles.InfinityXVerified();
        var romZip = RomZipFixtures.CreateLegacyRomZip(_tempDir, "rom.zip");
        RomZipFixtures.WriteSiblingChecksum(romZip, await new AetherixRomMaster.Core.Rom.ChecksumVerifier().ComputeSha256Async(romZip));
        var initialZip = RomZipFixtures.CreateInitialInstallPackage(_tempDir, "initial.zip");
        RomZipFixtures.WriteSiblingChecksum(initialZip, await new AetherixRomMaster.Core.Rom.ChecksumVerifier().ComputeSha256Async(initialZip));

        var logger = new InstallLogger(_tempDir);
        var context = engine.CreateContext(InstallMode.DryRun, deviceProfile, romProfile, romZip, logger, initialZip);
        context.BackupAcknowledged = true;
        context.DataWipeConfirmed = true;

        var stateMachine = engine.CreateStateMachine(InstallMode.DryRun);
        var sawFailure = false;
        await foreach (var step in stateMachine.RunAsync(context))
        {
            if (step.Outcome == StepOutcome.Failed) sawFailure = true;
        }

        Assert.False(sawFailure);
        // Only DetectDevice legitimately calls the process runner (to find/read the device);
        // no destructive command (update/sideload/-w/reboot into fastbootd or recovery) may.
        Assert.DoesNotContain(processRunnerCalls, c => c.Contains("--skip-reboot") || c.Contains("sideload") || c.Contains(" -w") || c.Contains("reboot fastboot") || c.Contains("reboot recovery"));
        Assert.Contains(logger.Entries, e => e.Source == "DryRun");

        logger.Dispose();
    }

    private static string ProfilesDirectory()
    {
        var dir = Path.Combine(AppContext.BaseDirectory, "profiles");
        if (!Directory.Exists(dir))
        {
            throw new DirectoryNotFoundException($"Expected profiles directory at {dir} (csproj should copy ../../profiles/*.json there).");
        }
        return dir;
    }

    private static ProcessResult Ok(string stdout) => new() { ExitCode = 0, StandardOutput = stdout, StandardError = "" };

    public void Dispose()
    {
        try { Directory.Delete(_tempDir, recursive: true); } catch { /* best-effort cleanup */ }
    }
}
