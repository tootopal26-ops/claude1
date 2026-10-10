using System.Text.Json;
using System.Text.Json.Serialization;

namespace AetherixRomMaster.Core.Profiles;

public sealed class ProfileRepository
{
    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNameCaseInsensitive = true,
        Converters = { new JsonStringEnumConverter() },
    };

    private readonly string _profilesDirectory;

    public ProfileRepository(string profilesDirectory)
    {
        _profilesDirectory = profilesDirectory;
    }

    public async Task<DeviceProfile?> LoadDeviceProfileAsync(string codename, CancellationToken ct = default)
    {
        var path = Path.Combine(_profilesDirectory, $"{codename}.device.json");
        if (!File.Exists(path)) return null;

        await using var stream = File.OpenRead(path);
        return await JsonSerializer.DeserializeAsync<DeviceProfile>(stream, JsonOptions, ct);
    }

    public async Task<IReadOnlyList<RomProfile>> LoadRomProfilesAsync(string codename, CancellationToken ct = default)
    {
        if (!Directory.Exists(_profilesDirectory)) return [];

        var profiles = new List<RomProfile>();
        foreach (var path in Directory.EnumerateFiles(_profilesDirectory, $"{codename}.*.rom.json"))
        {
            await using var stream = File.OpenRead(path);
            var profile = await JsonSerializer.DeserializeAsync<RomProfile>(stream, JsonOptions, ct);
            if (profile is not null)
            {
                profiles.Add(profile);
            }
        }
        return profiles;
    }

    public IReadOnlyList<string> ListAvailableCodenames()
    {
        if (!Directory.Exists(_profilesDirectory)) return [];

        return Directory.EnumerateFiles(_profilesDirectory, "*.device.json")
            .Select(path => Path.GetFileName(path) ?? string.Empty)
            .Where(name => name.Length > 0)
            .Select(name => name[..name.IndexOf(".device.json", StringComparison.Ordinal)])
            .ToList();
    }
}
