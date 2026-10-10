namespace AetherixRomMaster.Core.Shell;

/// <summary>
/// Resolves the path to the official Android Platform Tools (adb.exe / fastboot.exe).
/// Only ever points at a bundled copy shipped with this application or a copy already
/// present on the user's PATH — this app never downloads or substitutes an unverified
/// binary for these.
/// </summary>
public sealed class PlatformToolsLocator
{
    private readonly string _baseDirectory;

    public PlatformToolsLocator(string? baseDirectory = null)
    {
        _baseDirectory = baseDirectory ?? AppContext.BaseDirectory;
    }

    public string AdbExecutableName => OperatingSystem.IsWindows() ? "adb.exe" : "adb";
    public string FastbootExecutableName => OperatingSystem.IsWindows() ? "fastboot.exe" : "fastboot";

    public PlatformToolsResolution Resolve()
    {
        var bundledDir = Path.Combine(_baseDirectory, "tools", "platform-tools");
        var (adb, adbSource) = FindExecutable(bundledDir, AdbExecutableName);
        var (fastboot, fastbootSource) = FindExecutable(bundledDir, FastbootExecutableName);

        var source = (adb, fastboot) switch
        {
            (null, _) or (_, null) => PlatformToolsSource.NotFound,
            _ when adbSource == PlatformToolsSource.Bundled && fastbootSource == PlatformToolsSource.Bundled => PlatformToolsSource.Bundled,
            _ => PlatformToolsSource.SystemPath,
        };

        return new PlatformToolsResolution
        {
            AdbPath = adb,
            FastbootPath = fastboot,
            Source = source,
        };
    }

    private static (string? Path, PlatformToolsSource Source) FindExecutable(string bundledDir, string executableName)
    {
        var bundledPath = Path.Combine(bundledDir, executableName);
        if (File.Exists(bundledPath))
        {
            return (bundledPath, PlatformToolsSource.Bundled);
        }

        foreach (var dir in (Environment.GetEnvironmentVariable("PATH") ?? string.Empty).Split(Path.PathSeparator))
        {
            if (dir.Length == 0) continue;
            var candidate = Path.Combine(dir, executableName);
            if (File.Exists(candidate))
            {
                return (candidate, PlatformToolsSource.SystemPath);
            }
        }

        return (null, PlatformToolsSource.NotFound);
    }
}

public enum PlatformToolsSource
{
    Bundled,
    SystemPath,
    NotFound
}

public sealed class PlatformToolsResolution
{
    public string? AdbPath { get; init; }
    public string? FastbootPath { get; init; }
    public PlatformToolsSource Source { get; init; }

    public bool IsComplete => AdbPath is not null && FastbootPath is not null;
}
