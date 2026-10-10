using System.Security.Cryptography;
using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.Profiles;
using AetherixRomMaster.Core.Rom;
using AetherixRomMaster.Core.Tests.TestDoubles;
using Xunit;

namespace AetherixRomMaster.Core.Tests.Rom;

public class RomValidationServiceTests : IDisposable
{
    private readonly string _tempDir = Directory.CreateTempSubdirectory("aetherix-romvalidation-tests-").FullName;
    private readonly RomValidationService _service = new();

    private static DeviceInfo CancunfDevice() => new() { ConnectionMode = ConnectionMode.Adb, Codename = "cancunf", Serial = "S1" };

    [Fact]
    public async Task ValidateAsync_RejectsInvalidZip_AsUnrecognizedFormat()
    {
        var zip = RomZipFixtures.CreateInvalidZip(_tempDir, "garbage.zip");

        var result = await _service.ValidateAsync(zip, CancunfDevice(), TestProfiles.Cancunf(), TestProfiles.InfinityXVerified());

        Assert.Contains(result.Issues, i => i.Code == "UNRECOGNIZED_PACKAGE_FORMAT");
        Assert.True(result.HasBlockingIssues);
    }

    [Fact]
    public async Task ValidateAsync_RejectsRomBuiltForADifferentDevice()
    {
        var zip = RomZipFixtures.CreateLegacyRomZip(_tempDir, "wrong-device-rom.zip", preDevice: "some_other_device");
        RomZipFixtures.WriteSiblingChecksum(zip, await new ChecksumVerifier().ComputeSha256Async(zip));

        var result = await _service.ValidateAsync(zip, CancunfDevice(), TestProfiles.Cancunf(), TestProfiles.InfinityXVerified());

        Assert.Contains(result.Issues, i => i.Code == "ROM_DEVICE_MISMATCH");
        Assert.True(result.HasBlockingIssues);
    }

    [Fact]
    public async Task ValidateAsync_RejectsInitialInstallPackage_DeclaredForAnotherBoard()
    {
        var zip = RomZipFixtures.CreateInitialInstallPackage(_tempDir, "initial-install.zip", board: "some_other_board");
        RomZipFixtures.WriteSiblingChecksum(zip, await new ChecksumVerifier().ComputeSha256Async(zip));

        var result = await _service.ValidateAsync(
            zip, CancunfDevice(), TestProfiles.Cancunf(), TestProfiles.InfinityXVerified(), RomPackageKind.InitialInstallPackage);

        Assert.Contains(result.Issues, i => i.Code == "INITIAL_INSTALL_BOARD_MISMATCH");
    }

    [Fact]
    public async Task ValidateAsync_RejectsChecksumMismatch()
    {
        var zip = RomZipFixtures.CreateLegacyRomZip(_tempDir, "rom.zip");
        RomZipFixtures.WriteSiblingChecksum(zip, new string('a', 64)); // deliberately wrong

        var result = await _service.ValidateAsync(zip, CancunfDevice(), TestProfiles.Cancunf(), TestProfiles.InfinityXVerified());

        Assert.Contains(result.Issues, i => i.Code == "CHECKSUM_MISMATCH");
        Assert.False(result.IsSafeToInstall);
    }

    [Fact]
    public async Task ValidateAsync_RejectsWhenNoChecksumAvailableAtAll()
    {
        var zip = RomZipFixtures.CreateLegacyRomZip(_tempDir, "rom-no-checksum.zip");

        var result = await _service.ValidateAsync(zip, CancunfDevice(), TestProfiles.Cancunf(), TestProfiles.InfinityXVerified());

        Assert.Contains(result.Issues, i => i.Code == "CHECKSUM_UNAVAILABLE");
    }

    [Fact]
    public async Task ValidateAsync_RejectsUnverifiedRomProfile_EvenWithGoodChecksumAndMatchingDevice()
    {
        var zip = RomZipFixtures.CreateLegacyRomZip(_tempDir, "rom.zip");
        RomZipFixtures.WriteSiblingChecksum(zip, await new ChecksumVerifier().ComputeSha256Async(zip));

        var result = await _service.ValidateAsync(zip, CancunfDevice(), TestProfiles.Cancunf(), TestProfiles.InfinityXUnverified());

        Assert.Contains(result.Issues, i => i.Code == "ROM_PROFILE_UNVERIFIED");
        Assert.True(result.HasBlockingIssues);
    }

    [Fact]
    public async Task ValidateAsync_RejectsConnectedDeviceNotInSupportedList()
    {
        var zip = RomZipFixtures.CreateLegacyRomZip(_tempDir, "rom.zip");
        RomZipFixtures.WriteSiblingChecksum(zip, await new ChecksumVerifier().ComputeSha256Async(zip));
        var wrongDevice = new DeviceInfo { ConnectionMode = ConnectionMode.Adb, Codename = "completely_different_phone" };

        var result = await _service.ValidateAsync(zip, wrongDevice, TestProfiles.Cancunf(), TestProfiles.InfinityXVerified());

        Assert.Contains(result.Issues, i => i.Code == "CONNECTED_DEVICE_NOT_SUPPORTED");
    }

    [Fact]
    public async Task ValidateAsync_Passes_ForFullyMatchingVerifiedRomAndDevice()
    {
        var zip = RomZipFixtures.CreateLegacyRomZip(_tempDir, "rom.zip");
        RomZipFixtures.WriteSiblingChecksum(zip, await new ChecksumVerifier().ComputeSha256Async(zip));

        var result = await _service.ValidateAsync(zip, CancunfDevice(), TestProfiles.Cancunf(), TestProfiles.InfinityXVerified());

        Assert.True(result.IsSafeToInstall);
        Assert.Empty(result.Issues);
    }

    [Fact]
    public async Task ValidateAsync_MatchesOfficialMd5Checksum_AndIdentifiesTheVariant()
    {
        var zip = RomZipFixtures.CreateLegacyRomZip(_tempDir, "Project_Infinity-X-3.12-cancunf-gapps.zip");
        var officialMd5 = Convert.ToHexString(MD5.HashData(await File.ReadAllBytesAsync(zip))).ToLowerInvariant();
        var profile = TestProfiles.InfinityXUnverified() with
        {
            VerificationStatus = VerificationStatus.PendingManualConfirmation,
            KnownOfficialChecksums = [new RomChecksumEntry("GApps", "MD5", officialMd5, "test fixture")],
        };

        var result = await _service.ValidateAsync(zip, CancunfDevice(), TestProfiles.Cancunf(), profile);

        Assert.True(result.Checksum.Matched);
        Assert.Equal("GApps", result.Checksum.MatchedVariantLabel);
        Assert.Equal(officialMd5, result.Checksum.ExpectedMd5);
        Assert.Null(result.Checksum.ExpectedSha256);
        Assert.DoesNotContain(result.Issues, i => i.Code is "CHECKSUM_MISMATCH" or "CHECKSUM_UNAVAILABLE");
        Assert.True(result.IsSafeToInstall);
    }

    [Fact]
    public async Task ValidateAsync_RejectsFile_WhenItMatchesNeitherKnownOfficialMd5Variant()
    {
        var zip = RomZipFixtures.CreateLegacyRomZip(_tempDir, "rom.zip");
        var profile = TestProfiles.InfinityXUnverified() with
        {
            VerificationStatus = VerificationStatus.PendingManualConfirmation,
            KnownOfficialChecksums =
            [
                new RomChecksumEntry("GApps", "MD5", new string('a', 32), "test fixture"),
                new RomChecksumEntry("Vanilla", "MD5", new string('b', 32), "test fixture"),
            ],
        };

        var result = await _service.ValidateAsync(zip, CancunfDevice(), TestProfiles.Cancunf(), profile);

        Assert.False(result.Checksum.Matched);
        var issue = Assert.Single(result.Issues, i => i.Code == "CHECKSUM_MISMATCH");
        Assert.True(result.HasBlockingIssues);
        Assert.False(result.IsSafeToInstall);
        // The exact bug reported: this message must never claim "SHA-256" was the
        // algorithm checked when only MD5 candidates were ever compared.
        Assert.DoesNotContain("expected SHA-256", issue.Message, StringComparison.OrdinalIgnoreCase);
        Assert.Contains("MD5", issue.Message);
    }

    [Fact]
    public async Task ValidateAsync_DoesNotBlock_OnPendingManualConfirmation_WhenRealChecksumEvidenceMatches()
    {
        var zip = RomZipFixtures.CreateLegacyRomZip(_tempDir, "rom.zip");
        var officialMd5 = Convert.ToHexString(MD5.HashData(await File.ReadAllBytesAsync(zip))).ToLowerInvariant();
        var profile = TestProfiles.InfinityXUnverified() with
        {
            VerificationStatus = VerificationStatus.PendingManualConfirmation,
            KnownOfficialChecksums = [new RomChecksumEntry("Vanilla", "MD5", officialMd5, "test fixture")],
        };

        var result = await _service.ValidateAsync(zip, CancunfDevice(), TestProfiles.Cancunf(), profile);

        // PendingManualConfirmation must never blanket-block the way Unverified does --
        // only a genuine, evidence-based problem (a real mismatch/missing fact) should.
        Assert.DoesNotContain(result.Issues, i => i.Code == "ROM_PROFILE_UNVERIFIED");
        Assert.False(result.HasBlockingIssues);
        Assert.True(result.IsSafeToInstall);
    }

    // Researched and confirmed (2026-10-10): no checksum requirement for the Initial
    // Install package exists anywhere in the official cancunf/Infinity-X documentation,
    // nor does android-info.txt's own format carry such a field. A missing checksum for
    // THIS package type specifically is therefore no longer an artificial blocker -- its
    // identity is instead verified strictly by its declared required-board match, which
    // still blocks unconditionally on a mismatch. The ROM package gets no such exception.

    [Fact]
    public async Task ValidateAsync_InitialInstallPackage_NoLongerBlocksOnMissingChecksum_WhenBoardMatches()
    {
        var zip = RomZipFixtures.CreateInitialInstallPackage(_tempDir, "infinity_gms_initial_install.zip");
        var profile = TestProfiles.InfinityXUnverified() with
        {
            VerificationStatus = VerificationStatus.PendingManualConfirmation,
            KnownOfficialChecksums = [new RomChecksumEntry("GApps", "MD5", new string('a', 32), "test fixture")],
            // Deliberately empty, mirroring the real cancunf.infinity-x.rom.json: no official
            // checksum has ever been found for the Initial Install package specifically.
            KnownOfficialInitialInstallChecksums = [],
        };

        var result = await _service.ValidateAsync(
            zip, CancunfDevice(), TestProfiles.Cancunf(), profile, RomPackageKind.InitialInstallPackage);

        var checksumIssue = Assert.Single(result.Issues, i => i.Code == "CHECKSUM_UNAVAILABLE");
        Assert.Equal(CompatibilityIssueSeverity.Warning, checksumIssue.Severity);
        Assert.False(result.HasBlockingIssues);
    }

    [Fact]
    public async Task ValidateAsync_InitialInstallPackage_StillBlocks_WhenDeclaredBoardDoesNotMatch_DespiteNoChecksumRequirement()
    {
        // The missing-checksum exception never substitutes for identity verification --
        // a declared board mismatch is still a hard, confirmed problem.
        var zip = RomZipFixtures.CreateInitialInstallPackage(_tempDir, "infinity_gms_initial_install.zip", board: "some_other_board");
        var profile = TestProfiles.InfinityXUnverified() with { VerificationStatus = VerificationStatus.PendingManualConfirmation };

        var result = await _service.ValidateAsync(
            zip, CancunfDevice(), TestProfiles.Cancunf(), profile, RomPackageKind.InitialInstallPackage);

        Assert.Contains(result.Issues, i => i.Code == "INITIAL_INSTALL_BOARD_MISMATCH" && i.Severity == CompatibilityIssueSeverity.Blocking);
        Assert.True(result.HasBlockingIssues);
    }

    [Fact]
    public async Task ValidateAsync_InitialInstallPackage_StillBlocks_OnAGenuineChecksumMismatch_WhenAnOfficialReferenceDoesExist()
    {
        // If an official checksum for this package type is ever found and recorded, a real
        // mismatch against it is still a hard, confirmed problem -- the exception above
        // only covers the case where no reference exists at all.
        var zip = RomZipFixtures.CreateInitialInstallPackage(_tempDir, "infinity_gms_initial_install.zip");
        var profile = TestProfiles.InfinityXUnverified() with
        {
            VerificationStatus = VerificationStatus.PendingManualConfirmation,
            KnownOfficialInitialInstallChecksums = [new RomChecksumEntry("Initial Install", "MD5", new string('a', 32), "test fixture")],
        };

        var result = await _service.ValidateAsync(
            zip, CancunfDevice(), TestProfiles.Cancunf(), profile, RomPackageKind.InitialInstallPackage);

        var checksumIssue = Assert.Single(result.Issues, i => i.Code == "CHECKSUM_MISMATCH");
        Assert.Equal(CompatibilityIssueSeverity.Blocking, checksumIssue.Severity);
        Assert.True(result.HasBlockingIssues);
    }

    [Fact]
    public async Task ValidateAsync_RomPackage_StillBlocksOnMissingChecksum_UnlikeInitialInstall()
    {
        // Explicit regression guard: the missing-checksum exception is Initial-Install-only.
        // The ROM package itself must never be affected by this redesign.
        var zip = RomZipFixtures.CreateLegacyRomZip(_tempDir, "rom-no-checksum.zip");

        var result = await _service.ValidateAsync(zip, CancunfDevice(), TestProfiles.Cancunf(), TestProfiles.InfinityXVerified());

        var checksumIssue = Assert.Single(result.Issues, i => i.Code == "CHECKSUM_UNAVAILABLE");
        Assert.Equal(CompatibilityIssueSeverity.Blocking, checksumIssue.Severity);
        Assert.True(result.HasBlockingIssues);
    }

    public void Dispose()
    {
        try { Directory.Delete(_tempDir, recursive: true); } catch { /* best-effort cleanup */ }
    }
}
