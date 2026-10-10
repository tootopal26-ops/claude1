namespace AetherixRomMaster.Core.Rom;

/// <summary>
/// Parsed contents of META-INF/com/android/metadata — the standard key=value manifest
/// AOSP's ota_from_target_files writes into every OTA/update package, A/B or legacy alike.
/// </summary>
public sealed class RomMetadata
{
    public required IReadOnlyDictionary<string, string> RawEntries { get; init; }

    /// <summary>Device codenames this package declares itself compatible with (pre-device,
    /// comma-separated). This is the field recovery/update_engine itself checks against
    /// ro.product.device before allowing an install.</summary>
    public IReadOnlyList<string> PreDevices =>
        RawEntries.TryGetValue("pre-device", out var value)
            ? value.Split(',', StringSplitOptions.TrimEntries | StringSplitOptions.RemoveEmptyEntries)
            : [];

    public string? PostBuild => RawEntries.GetValueOrDefault("post-build");
    public string? PostTimestamp => RawEntries.GetValueOrDefault("post-timestamp");
    public string? OtaType => RawEntries.GetValueOrDefault("ota-type");

    public static RomMetadata Parse(string content)
    {
        var entries = new Dictionary<string, string>();
        foreach (var line in content.Split('\n'))
        {
            var trimmed = line.Trim();
            if (trimmed.Length == 0 || trimmed.StartsWith('#')) continue;

            var separatorIndex = trimmed.IndexOf('=');
            if (separatorIndex <= 0) continue;

            entries[trimmed[..separatorIndex]] = trimmed[(separatorIndex + 1)..];
        }
        return new RomMetadata { RawEntries = entries };
    }
}
