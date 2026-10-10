namespace AetherixRomMaster.Core.Rom;

/// <summary>
/// Parsed android-info.txt — the file `fastboot update` itself reads to refuse flashing
/// a package onto the wrong board. Lines look like "require board=cancunf".
/// </summary>
public sealed class AndroidInfoManifest
{
    public required IReadOnlyList<string> RequiredBoards { get; init; }
    public required string RawText { get; init; }

    public static AndroidInfoManifest Parse(string content)
    {
        var boards = new List<string>();
        foreach (var line in content.Split('\n'))
        {
            var trimmed = line.Trim();
            if (!trimmed.StartsWith("require board=", StringComparison.OrdinalIgnoreCase)) continue;

            var value = trimmed["require board=".Length..];
            boards.AddRange(value.Split('|', StringSplitOptions.TrimEntries | StringSplitOptions.RemoveEmptyEntries));
        }
        return new AndroidInfoManifest { RequiredBoards = boards, RawText = content };
    }
}
