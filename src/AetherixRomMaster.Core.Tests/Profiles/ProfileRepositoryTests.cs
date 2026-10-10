using AetherixRomMaster.Core.Profiles;
using Xunit;

namespace AetherixRomMaster.Core.Tests.Profiles;

/// <summary>Validates the actual profiles/*.json files shipped with the app -- not test
/// fixtures -- so a typo in the real cancunf profile data fails CI, not just at runtime.</summary>
public class ProfileRepositoryTests
{
    private static string ProfilesDirectory => Path.Combine(AppContext.BaseDirectory, "profiles");

    [Fact]
    public async Task LoadDeviceProfileAsync_ParsesShippedCancunfProfile()
    {
        var repo = new ProfileRepository(ProfilesDirectory);

        var profile = await repo.LoadDeviceProfileAsync("cancunf");

        Assert.NotNull(profile);
        Assert.Equal("cancunf", profile!.Codename);
        Assert.Equal("Motorola", profile.Manufacturer);
        Assert.Contains("Moto G64 5G", profile.SupportedModels);
        Assert.True(profile.EnforceAntiRollbackCheck);
        Assert.NotEmpty(profile.Citations);
    }

    [Fact]
    public async Task LoadRomProfilesAsync_ParsesShippedInfinityXProfile()
    {
        var repo = new ProfileRepository(ProfilesDirectory);

        var profiles = await repo.LoadRomProfilesAsync("cancunf");

        var infinityX = Assert.Single(profiles);
        Assert.Equal("cancunf", infinityX.TargetCodename);
        Assert.Equal(InstallationMethod.FastbootUpdatePackageThenSideload, infinityX.InstallationMethod);
        Assert.True(infinityX.RequiresInitialInstallPackage);

        // This is the gate that must hold until a human confirms the official page:
        // the shipped ROM profile must NOT claim Verified on its own.
        Assert.Equal(VerificationStatus.Unverified, infinityX.VerificationStatus);
        Assert.False(string.IsNullOrWhiteSpace(infinityX.VerificationGapNotes));
    }

    [Fact]
    public void ListAvailableCodenames_IncludesCancunf()
    {
        var repo = new ProfileRepository(ProfilesDirectory);

        var codenames = repo.ListAvailableCodenames();

        Assert.Contains("cancunf", codenames);
    }

    [Fact]
    public async Task LoadDeviceProfileAsync_ReturnsNull_ForUnknownCodename()
    {
        var repo = new ProfileRepository(ProfilesDirectory);

        var profile = await repo.LoadDeviceProfileAsync("totally_unsupported_device");

        Assert.Null(profile);
    }

    [Fact]
    public void DescribeDeviceProfileLookup_ReportsMissingDirectory_WhenProfilesFolderDoesNotExist()
    {
        var missingDir = Path.Combine(Path.GetTempPath(), "aetherix-test-missing-" + Guid.NewGuid());
        var repo = new ProfileRepository(missingDir);

        var diagnostics = repo.DescribeDeviceProfileLookup("cancunf");

        Assert.False(diagnostics.DirectoryExists);
        Assert.False(diagnostics.DeviceProfileFileExists);
        Assert.Contains("profiles", diagnostics.ToDisplayText(), StringComparison.OrdinalIgnoreCase);
        Assert.Contains(missingDir, diagnostics.ToDisplayText());
    }

    [Fact]
    public void DescribeDeviceProfileLookup_ListsFilesPresent_WhenDirectoryExistsButProfileFileIsMissing()
    {
        var dir = Directory.CreateTempSubdirectory("aetherix-test-").FullName;
        try
        {
            File.WriteAllText(Path.Combine(dir, "unrelated.txt"), "not a profile");
            var repo = new ProfileRepository(dir);

            var diagnostics = repo.DescribeDeviceProfileLookup("cancunf");

            Assert.True(diagnostics.DirectoryExists);
            Assert.False(diagnostics.DeviceProfileFileExists);
            Assert.Contains("unrelated.txt", diagnostics.FilesFoundInDirectory);
            Assert.Contains("unrelated.txt", diagnostics.ToDisplayText());
        }
        finally
        {
            Directory.Delete(dir, recursive: true);
        }
    }

    [Fact]
    public void DescribeDeviceProfileLookup_MatchesRealShippedProfile_ForCancunf()
    {
        var repo = new ProfileRepository(ProfilesDirectory);

        var diagnostics = repo.DescribeDeviceProfileLookup("cancunf");

        Assert.True(diagnostics.DirectoryExists);
        Assert.True(diagnostics.DeviceProfileFileExists);
    }
}
