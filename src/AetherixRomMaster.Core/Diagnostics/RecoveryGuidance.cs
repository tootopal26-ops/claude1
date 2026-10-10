namespace AetherixRomMaster.Core.Diagnostics;

public sealed record RecoveryGuidanceEntry(string Key, string Title, IReadOnlyList<string> Steps);

/// <summary>Static, human-written recovery guidance shown whenever a step fails. Keyed
/// so the UI's recovery-assistance panel can look up the right advice for the failure.</summary>
public static class RecoveryGuidance
{
    public static readonly IReadOnlyDictionary<string, RecoveryGuidanceEntry> Entries =
        new List<RecoveryGuidanceEntry>
        {
            new("device-not-detected", "Device not detected", new[]
            {
                "Check the USB cable actually supports data (not charge-only) and try a different port/cable.",
                "On the phone, confirm a prompt to \"Allow USB debugging\" isn't waiting for approval.",
                "Make sure Motorola's USB drivers are installed (see DEPENDENCIES.md).",
                "Try `adb devices` / `fastboot devices` from a terminal in the tools\\platform-tools folder to see raw output.",
            }),
            new("adb-disconnection", "ADB connection lost mid-operation", new[]
            {
                "Reconnect the USB cable without unplugging power if possible.",
                "If the phone rebooted on its own, wait for it to finish booting, then reopen AETHERIX ROM MASTER.",
                "Do not disconnect again while an operation is shown as in progress.",
            }),
            new("fastboot-detection-failure", "Device not responding in fastboot/fastbootd", new[]
            {
                "Confirm the phone's screen shows a fastboot/bootloader menu.",
                "Reinstall Motorola's USB drivers — Windows sometimes loads the wrong driver for fastboot mode specifically.",
                "Try a different USB port, preferably directly on the motherboard rather than a hub.",
            }),
            new("sideload-failure", "ADB sideload failed", new[]
            {
                "On the phone's recovery screen, confirm \"Apply update from ADB\" is selected before sideload starts.",
                "Re-run `adb devices` — the device must show state \"sideload\", not \"device\" or \"recovery\".",
                "If it stalls at a fixed percentage for several minutes, the cable or port is likely the cause — try another.",
            }),
            new("rom-verification-error", "ROM file failed verification", new[]
            {
                "Re-download the file — it is most likely incomplete or corrupted.",
                "Re-check the checksum published on the ROM's official page matches what you supplied.",
                "Confirm you downloaded the build for 'cancunf', not a similarly named device.",
            }),
            new("bootloop", "Device is stuck bootlooping after install", new[]
            {
                "Do not keep rebooting repeatedly — hold Power + Volume Down to return to the bootloader instead.",
                "From the bootloader, you can re-enter fastbootd and re-attempt the initial-install package flash.",
                "If the device still won't boot, consult the ROM's official thread for a cancunf-specific unbrick/recovery guide before trying further commands.",
            }),
            new("kernelsu-installation-failure", "KernelSU root was not established", new[]
            {
                "This does not mean the ROM install failed — check the main screen's OS status separately.",
                "AETHERIX ROM MASTER never patches or flashes an unverified kernel automatically; see the KernelSU status panel for the specific reason it stopped.",
                "Check the ROM's official thread for whether this exact build ships KernelSU support, and how its maintainer documents enabling it.",
            }),
            new("checksum-mismatch", "Checksum verification failed", new[]
            {
                "The downloaded file does not match the official checksum and must not be flashed.",
                "Delete the file and download it again from the official source.",
                "If this keeps happening, your network or download tool may be corrupting the file in transit.",
            }),
        }.ToDictionary(e => e.Key);

    public static RecoveryGuidanceEntry? Lookup(string? key) =>
        key is not null && Entries.TryGetValue(key, out var entry) ? entry : null;
}
