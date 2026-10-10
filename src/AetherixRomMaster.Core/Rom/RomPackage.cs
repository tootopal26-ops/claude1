namespace AetherixRomMaster.Core.Rom;

public sealed class RomPackage
{
    public required string ZipPath { get; init; }
    public required long FileSizeBytes { get; init; }
    public required RomPackageKind Kind { get; init; }
    public RomMetadata? Metadata { get; init; }
    public AndroidInfoManifest? AndroidInfo { get; init; }
    public required IReadOnlyList<string> EntryNames { get; init; }

    public bool ContainsEntry(string name) => EntryNames.Contains(name, StringComparer.OrdinalIgnoreCase);
}
