namespace AetherixRomMaster.Core.Shell;

/// <summary>
/// Finds the real directory holding app-adjacent content (profiles, platform-tools) by
/// checking several plausible candidates rather than trusting a single one. This exists
/// because a self-contained single-file publish can leave more than one "directory of
/// the running app" candidate in play (AppContext.BaseDirectory vs. the actual process
/// executable's directory vs. the current working directory), and because users
/// sometimes copy just the .exe out of a published folder without its loose content
/// files. Never silently guesses which one is "right" -- it picks the first candidate
/// that actually contains the marker file, and callers can inspect every candidate that
/// was tried when none of them do.
/// </summary>
public static class AppRelativeDirectoryResolver
{
    public static string? FindFirstContaining(IEnumerable<string> candidateDirectories, string markerFileName)
    {
        foreach (var dir in Deduplicate(candidateDirectories))
        {
            if (TryFileExists(Path.Combine(dir, markerFileName)))
            {
                return dir;
            }
        }
        return null;
    }

    public static IReadOnlyList<string> Deduplicate(IEnumerable<string> candidateDirectories)
    {
        var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        var result = new List<string>();
        foreach (var dir in candidateDirectories)
        {
            if (string.IsNullOrWhiteSpace(dir)) continue;
            var normalized = Path.GetFullPath(dir);
            if (seen.Add(normalized))
            {
                result.Add(normalized);
            }
        }
        return result;
    }

    private static bool TryFileExists(string path)
    {
        try
        {
            return File.Exists(path);
        }
        catch
        {
            // Inaccessible/invalid path -- treat as "not found", never throw during startup probing.
            return false;
        }
    }
}
