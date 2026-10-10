using System.Text.RegularExpressions;
using AetherixRomMaster.Core.Profiles;

namespace AetherixRomMaster.Core.KernelSu;

/// <summary>
/// Assesses whether KernelSU can realistically work on the kernel that will be running
/// after the ROM is installed. This never flashes, patches, or sources a kernel itself —
/// it only decides whether the installer may proceed with a bundled/verified KernelSU
/// path, or must stop and tell the user why it can't go further. Final proof always
/// comes from VERIFY_KERNELSU_ROOT querying the booted device, never from this assessment.
/// </summary>
public sealed class KernelSuCompatibilityChecker
{
    // Generic Kernel Image version strings look like "5.10.198-android12-9-...".
    // The "-androidNN-" marker is the real, documented GKI naming convention —
    // its presence (not just the numeric version) is what makes a kernel GKI.
    private static readonly Regex GkiVersionPattern = new(
        @"^(?<major>\d+)\.(?<minor>\d+)\.\d+-android\d+-",
        RegexOptions.Compiled);

    private const int MinimumGkiMajorMinor = 510; // KernelSU's documented floor: GKI 2.0, kernel 5.10+.

    private readonly BootImageInspector _bootImageInspector;

    public KernelSuCompatibilityChecker(BootImageInspector? bootImageInspector = null)
    {
        _bootImageInspector = bootImageInspector ?? new BootImageInspector();
    }

    public async Task<KernelSuCompatibilityAssessment> AssessAsync(
        string? targetBootImagePath,
        string? currentKernelVersion,
        RomProfile romProfile,
        CancellationToken ct = default)
    {
        bool? markersFound = null;
        if (targetBootImagePath is not null)
        {
            markersFound = await _bootImageInspector.ScanForKernelSuMarkersAsync(targetBootImagePath, ct);
        }

        var (looksGki, majorMinor) = ParseGkiVersion(currentKernelVersion);

        if (markersFound == true)
        {
            return new KernelSuCompatibilityAssessment
            {
                KernelLooksGki = looksGki,
                ParsedKernelVersion = currentKernelVersion,
                BootImageHasKernelSuMarkers = true,
                RecommendedAction = KernelSuRecommendedAction.UseBundledKernelSuAsIs,
                Explanation = "The target boot image appears to embed KernelSU support (recognized marker strings found). " +
                               "This is a heuristic signal, not proof — AETHERIX ROM MASTER will still require a real " +
                               "root check against the booted device (VERIFY_KERNELSU_ROOT) before reporting success.",
            };
        }

        if (looksGki && majorMinor >= MinimumGkiMajorMinor)
        {
            return new KernelSuCompatibilityAssessment
            {
                KernelLooksGki = true,
                ParsedKernelVersion = currentKernelVersion,
                BootImageHasKernelSuMarkers = markersFound,
                RecommendedAction = KernelSuRecommendedAction.ManualVerifiedKernelRequired,
                Explanation = $"The kernel ('{currentKernelVersion}') meets KernelSU's GKI 2.0 floor (5.10+) and uses GKI " +
                               "naming, so a matching official KernelSU GKI boot image may exist for its exact KMI. " +
                               "AETHERIX ROM MASTER does not automatically download or patch a kernel image. " +
                               (romProfile.KernelSuNotes ?? string.Empty) +
                               " Stopping here: confirm a verified, checksummed KernelSU image for this exact KMI at " +
                               "https://kernelsu.org/guide/installation.html before continuing manually.",
            };
        }

        return new KernelSuCompatibilityAssessment
        {
            KernelLooksGki = looksGki,
            ParsedKernelVersion = currentKernelVersion,
            BootImageHasKernelSuMarkers = markersFound,
            RecommendedAction = KernelSuRecommendedAction.Unsupported,
            Explanation = currentKernelVersion is null
                ? "Kernel version could not be read from the device, so KernelSU compatibility cannot be assessed."
                : $"Kernel version '{currentKernelVersion}' does not match KernelSU's supported GKI 2.0+ floor (5.10+) " +
                  "or does not use GKI naming. A compatible kernel would need to be built manually from source, which " +
                  "this app will not do automatically.",
        };
    }

    private static (bool LooksGki, int MajorMinor) ParseGkiVersion(string? kernelVersion)
    {
        if (kernelVersion is null) return (false, 0);

        var match = GkiVersionPattern.Match(kernelVersion);
        if (!match.Success) return (false, 0);

        var major = int.Parse(match.Groups["major"].Value);
        var minor = int.Parse(match.Groups["minor"].Value);
        return (true, major * 100 + minor);
    }
}
