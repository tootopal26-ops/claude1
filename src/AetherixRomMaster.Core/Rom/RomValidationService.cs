using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.Profiles;

namespace AetherixRomMaster.Core.Rom;

/// <summary>
/// Validates one selected ZIP (either the ROM itself or its initial-install/firmware
/// package) against the connected device and the loaded profiles. Every blocking issue
/// here is a reason the real flashing engine must refuse to proceed — dry-run mode still
/// reports them, it just never reaches a step that could act on them destructively.
/// </summary>
public sealed class RomValidationService
{
    private readonly RomPackageInspector _inspector;
    private readonly ChecksumVerifier _checksumVerifier;

    public RomValidationService(RomPackageInspector? inspector = null, ChecksumVerifier? checksumVerifier = null)
    {
        _inspector = inspector ?? new RomPackageInspector();
        _checksumVerifier = checksumVerifier ?? new ChecksumVerifier();
    }

    public async Task<RomValidationResult> ValidateAsync(
        string zipPath,
        DeviceInfo detectedDevice,
        DeviceProfile deviceProfile,
        RomProfile romProfile,
        RomPackageKind? expectedKind = null,
        string? explicitChecksum = null,
        CancellationToken ct = default)
    {
        var package = await _inspector.InspectAsync(zipPath, ct);
        var knownChecksums = expectedKind == RomPackageKind.InitialInstallPackage
            ? romProfile.KnownOfficialInitialInstallChecksums
            : romProfile.KnownOfficialChecksums;
        var checksum = await _checksumVerifier.VerifyAsync(zipPath, explicitChecksum, knownChecksums, ct);
        var issues = new List<RomCompatibilityIssue>();

        if (package.Kind == RomPackageKind.Unknown)
        {
            issues.Add(new RomCompatibilityIssue(
                CompatibilityIssueSeverity.Blocking,
                "UNRECOGNIZED_PACKAGE_FORMAT",
                "This file does not contain android-info.txt or META-INF/com/android/metadata, so it cannot be identified as a valid Android flashing package."));
        }

        if (expectedKind is { } kind && package.Kind != RomPackageKind.Unknown && package.Kind != kind)
        {
            issues.Add(new RomCompatibilityIssue(
                CompatibilityIssueSeverity.Blocking,
                "UNEXPECTED_PACKAGE_KIND",
                $"Expected a {kind} package here, but this file is a {package.Kind}."));
        }

        CheckDeclaredDeviceCompatibility(package, detectedDevice, deviceProfile, issues);

        if (!checksum.HasOfficialReference)
        {
            issues.Add(new RomCompatibilityIssue(
                CompatibilityIssueSeverity.Blocking,
                "CHECKSUM_UNAVAILABLE",
                "No official checksum was found for this file (no sibling .sha256/.sha256sum file, no checksum recorded " +
                "on the ROM profile, and none supplied). This application will not flash a file it cannot verify — " +
                "provide the checksum published on the ROM's official download page."));
        }
        else if (!checksum.Matched)
        {
            var expectedDescription = checksum.ExpectedMd5 is not null ? $"MD5 {checksum.ExpectedMd5}" : $"SHA-256 {checksum.ExpectedSha256}";
            var computedDescription = checksum.ExpectedMd5 is not null ? $"MD5 {checksum.ComputedMd5}" : $"SHA-256 {checksum.ComputedSha256}";
            issues.Add(new RomCompatibilityIssue(
                CompatibilityIssueSeverity.Blocking,
                "CHECKSUM_MISMATCH",
                $"Computed {computedDescription} does not match the expected {expectedDescription} from {checksum.Source}. " +
                "The file may be corrupt, incomplete, from a different variant, or tampered with."));
        }

        switch (romProfile.VerificationStatus)
        {
            case VerificationStatus.Verified:
                break;
            case VerificationStatus.PendingManualConfirmation:
                // Deliberately non-blocking: the profile's build/checksum facts were supplied
                // directly by the device owner from the cited official source rather than
                // independently fetched by this application (see Citations for provenance).
                // This does not bypass anything -- the checksum/device/package checks above
                // are the real, evidence-based gate and still block on their own if the actual
                // local file doesn't match what was recorded.
                issues.Add(new RomCompatibilityIssue(
                    CompatibilityIssueSeverity.Info,
                    "ROM_PROFILE_PENDING_MANUAL_CONFIRMATION",
                    "This ROM profile's build/checksum facts were confirmed by the device owner directly from the " +
                    "official source rather than independently fetched by this application — see the profile's " +
                    "citations for exactly what was recorded and from where."));
                break;
            default:
                issues.Add(new RomCompatibilityIssue(
                    CompatibilityIssueSeverity.Blocking,
                    "ROM_PROFILE_UNVERIFIED",
                    $"This ROM profile's installation details are not yet marked Verified (current status: {romProfile.VerificationStatus}). " +
                    (romProfile.VerificationGapNotes ?? "A human must confirm the official installation instructions before real flashing can proceed.")));
                break;
        }

        return new RomValidationResult { Package = package, Checksum = checksum, Issues = issues };
    }

    private static void CheckDeclaredDeviceCompatibility(
        RomPackage package,
        DeviceInfo detectedDevice,
        DeviceProfile deviceProfile,
        List<RomCompatibilityIssue> issues)
    {
        var acceptableCodenames = new HashSet<string>(StringComparer.OrdinalIgnoreCase) { deviceProfile.Codename };

        if (package.Metadata is { PreDevices.Count: > 0 } metadata &&
            !metadata.PreDevices.Any(acceptableCodenames.Contains))
        {
            issues.Add(new RomCompatibilityIssue(
                CompatibilityIssueSeverity.Blocking,
                "ROM_DEVICE_MISMATCH",
                $"This package declares compatibility with [{string.Join(", ", metadata.PreDevices)}], " +
                $"not '{deviceProfile.Codename}'. Flashing a ROM built for a different device can brick the phone."));
        }

        if (package.AndroidInfo is { RequiredBoards.Count: > 0 } androidInfo &&
            !androidInfo.RequiredBoards.Any(acceptableCodenames.Contains))
        {
            issues.Add(new RomCompatibilityIssue(
                CompatibilityIssueSeverity.Blocking,
                "INITIAL_INSTALL_BOARD_MISMATCH",
                $"This initial-install package requires board [{string.Join(", ", androidInfo.RequiredBoards)}], " +
                $"not '{deviceProfile.Codename}'. fastboot would refuse this too, but this app checks first so the reason is clear."));
        }

        if (detectedDevice.Codename is not null && !acceptableCodenames.Contains(detectedDevice.Codename))
        {
            issues.Add(new RomCompatibilityIssue(
                CompatibilityIssueSeverity.Blocking,
                "CONNECTED_DEVICE_NOT_SUPPORTED",
                $"The connected device reports codename '{detectedDevice.Codename}', which is not a device this build of " +
                "AETHERIX ROM MASTER supports. Only devices reporting 'cancunf' (Moto G64 5G / G54 5G) are supported in v1."));
        }
    }
}
