using AetherixRomMaster.Core.Profiles;

namespace AetherixRomMaster.Core.Installation;

/// <summary>
/// Builds the specific sequence of states/operations for one ROM profile. This is where
/// "use only the states actually required by the selected ROM" is enforced in code —
/// optional states are simply left out of the plan rather than executed as no-ops.
/// </summary>
public sealed class InstallPlanBuilder
{
    public InstallPlan Build(RomProfile romProfile, bool filesAlreadyLocal)
    {
        var states = new List<InstallState>
        {
            InstallState.Initialize,
            InstallState.DetectDevice,
            InstallState.VerifyBootloader,
            InstallState.VerifyRom,
        };
        var operations = new List<InstallOperation>
        {
            new(InstallState.Initialize, "Start AETHERIX ROM MASTER and load the cancunf device/ROM profiles.", false),
            new(InstallState.DetectDevice, "Query adb/fastboot for the connected device's identity and state.", false),
            new(InstallState.VerifyBootloader, "Confirm the detected device is supported and its bootloader is unlocked.", false),
            new(InstallState.VerifyRom, "Inspect the selected ROM package and confirm it targets this exact device.", false),
        };

        if (!filesAlreadyLocal)
        {
            states.Add(InstallState.DownloadRequiredFiles);
            operations.Add(new InstallOperation(InstallState.DownloadRequiredFiles, "Download the ROM and any required initial-install package from the source you provide.", false));
        }

        states.Add(InstallState.VerifyChecksums);
        operations.Add(new InstallOperation(InstallState.VerifyChecksums, "Verify SHA-256 checksums against the official source before trusting any file.", false));

        states.Add(InstallState.VerifyFirmware);
        operations.Add(new InstallOperation(InstallState.VerifyFirmware, $"Confirm installed firmware meets the ROM's requirement (Android {romProfile.RequiredFirmwareAndroidVersion ?? "unspecified"}).", false));

        if (romProfile.InstallationMethod is InstallationMethod.FastbootUpdatePackageThenSideload or InstallationMethod.SingleFlashableZipViaRecovery)
        {
            states.Add(InstallState.VerifyRecovery);
            operations.Add(new InstallOperation(InstallState.VerifyRecovery, "Confirm the device can reach a recovery mode that supports 'adb sideload'.", false));
        }

        states.Add(InstallState.VerifyKernelSuCompatibility);
        operations.Add(new InstallOperation(InstallState.VerifyKernelSuCompatibility, "Assess whether KernelSU can work on the kernel this ROM will boot.", false));

        states.Add(InstallState.CreateBackupGuidance);
        operations.Add(new InstallOperation(InstallState.CreateBackupGuidance, "Show backup guidance and require acknowledgement before any data loss is possible.", false));

        states.Add(InstallState.RequestDataWipeConfirmation);
        operations.Add(new InstallOperation(InstallState.RequestDataWipeConfirmation, "Explain exactly what will be erased and require explicit confirmation.", false));

        states.Add(InstallState.EnterRequiredInstallationMode);
        operations.Add(new InstallOperation(
            InstallState.EnterRequiredInstallationMode,
            "Reboot the device into fastbootd (userspace fastboot).",
            false,
            "adb reboot fastboot"));

        if (romProfile.RequiresInitialInstallPackage)
        {
            states.Add(InstallState.InstallRequiredInitialPackage);
            operations.Add(new InstallOperation(
                InstallState.InstallRequiredInitialPackage,
                "Flash the initial-install/firmware package (boot, vendor_boot, and related partitions as declared by the package itself).",
                true,
                "fastboot --skip-reboot update <initial-install>.zip"));
        }

        if (romProfile.RequiresDataFormat)
        {
            states.Add(InstallState.FormatDataIfRequired);
            operations.Add(new InstallOperation(
                InstallState.FormatDataIfRequired,
                romProfile.DataFormatNotes ?? "Format /data as required for this ROM.",
                true,
                "fastboot -w"));
        }

        states.Add(InstallState.InstallRom);
        operations.Add(new InstallOperation(
            InstallState.InstallRom,
            "Reboot to recovery and sideload the ROM package.",
            true,
            "fastboot reboot recovery && adb sideload <rom>.zip"));

        states.Add(InstallState.VerifyInstallation);
        operations.Add(new InstallOperation(InstallState.VerifyInstallation, "Confirm the sideload/update reported success before continuing.", false));

        states.Add(InstallState.RestoreKernelSuIfCompatible);
        operations.Add(new InstallOperation(InstallState.RestoreKernelSuIfCompatible, "Apply the ROM's own KernelSU path only if VERIFY_KERNELSU_COMPATIBILITY found one — never patch/flash an arbitrary kernel.", false));

        states.Add(InstallState.Reboot);
        operations.Add(new InstallOperation(InstallState.Reboot, "Reboot the device into the newly installed system.", false, "fastboot reboot"));

        states.Add(InstallState.VerifyFinalOs);
        operations.Add(new InstallOperation(InstallState.VerifyFinalOs, "Confirm the device actually booted the new ROM (build fingerprint check).", false));

        states.Add(InstallState.VerifyKernelSuRoot);
        operations.Add(new InstallOperation(InstallState.VerifyKernelSuRoot, "Query the booted device for real root (su -c id uid=0 and a KernelSU marker) — never assumed from a successful flash alone.", false));

        states.Add(InstallState.Complete);
        operations.Add(new InstallOperation(InstallState.Complete, "Show the final, evidence-based success/failure summary.", false));

        return new InstallPlan { States = states, Operations = operations };
    }
}
