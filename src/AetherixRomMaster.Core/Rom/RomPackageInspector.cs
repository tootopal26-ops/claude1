using System.IO.Compression;

namespace AetherixRomMaster.Core.Rom;

/// <summary>
/// Reads only the small manifest entries out of a ROM zip (metadata, android-info.txt,
/// the entry list) without extracting payload/system images — those can be multiple
/// gigabytes and are never needed just to classify and validate the package.
/// </summary>
public sealed class RomPackageInspector
{
    public async Task<RomPackage> InspectAsync(string zipPath, CancellationToken ct = default)
    {
        if (!File.Exists(zipPath))
        {
            throw new FileNotFoundException("ROM package not found.", zipPath);
        }

        using var archive = ZipFile.OpenRead(zipPath);
        var entryNames = archive.Entries.Select(e => e.FullName).ToList();

        var metadata = await ReadTextEntryAsync(archive, "META-INF/com/android/metadata", ct) is { } metadataText
            ? RomMetadata.Parse(metadataText)
            : null;

        var androidInfo = await ReadTextEntryAsync(archive, "android-info.txt", ct) is { } infoText
            ? AndroidInfoManifest.Parse(infoText)
            : null;

        var kind = ClassifyKind(entryNames, metadata, androidInfo);

        return new RomPackage
        {
            ZipPath = zipPath,
            FileSizeBytes = new FileInfo(zipPath).Length,
            Kind = kind,
            Metadata = metadata,
            AndroidInfo = androidInfo,
            EntryNames = entryNames,
        };
    }

    private static RomPackageKind ClassifyKind(
        IReadOnlyList<string> entryNames,
        RomMetadata? metadata,
        AndroidInfoManifest? androidInfo)
    {
        bool Has(string name) => entryNames.Contains(name, StringComparer.OrdinalIgnoreCase);

        if (androidInfo is not null)
        {
            return RomPackageKind.InitialInstallPackage;
        }
        if (metadata is not null && Has("payload.bin"))
        {
            return RomPackageKind.AbOtaPayload;
        }
        if (metadata is not null)
        {
            return RomPackageKind.LegacyFlashableZip;
        }
        return RomPackageKind.Unknown;
    }

    private static async Task<string?> ReadTextEntryAsync(ZipArchive archive, string entryName, CancellationToken ct)
    {
        var entry = archive.GetEntry(entryName);
        if (entry is null) return null;

        using var stream = entry.Open();
        using var reader = new StreamReader(stream);
        return await reader.ReadToEndAsync(ct);
    }
}
