using AetherixRomMaster.Core.Shell;
using Xunit;

namespace AetherixRomMaster.Core.Tests.Shell;

public class AppRelativeDirectoryResolverTests
{
    [Fact]
    public void FindFirstContaining_SkipsCandidatesWithoutMarker_ReturnsFirstThatHasIt()
    {
        using var root = new TempDirs(3);
        File.WriteAllText(Path.Combine(root.Dirs[2], "cancunf.device.json"), "{}");

        var found = AppRelativeDirectoryResolver.FindFirstContaining(root.Dirs, "cancunf.device.json");

        Assert.Equal(root.Dirs[2], Path.GetFullPath(found!));
    }

    [Fact]
    public void FindFirstContaining_ReturnsNull_WhenNoCandidateHasMarker()
    {
        using var root = new TempDirs(2);

        var found = AppRelativeDirectoryResolver.FindFirstContaining(root.Dirs, "cancunf.device.json");

        Assert.Null(found);
    }

    [Fact]
    public void FindFirstContaining_NeverThrows_ForNonexistentOrInvalidCandidate()
    {
        // A published single-file exe can hand this resolver a candidate directory that
        // doesn't exist at all (e.g. the user only copied the .exe) -- probing it must
        // report "not found", never throw and crash startup.
        var candidates = new[] { "/this/path/does/not/exist/at/all", "" };

        var found = AppRelativeDirectoryResolver.FindFirstContaining(candidates, "cancunf.device.json");

        Assert.Null(found);
    }

    [Fact]
    public void Deduplicate_CollapsesCaseInsensitiveDuplicatesAfterNormalization()
    {
        using var dir = new TempDirs(1);
        var original = dir.Dirs[0];
        var upper = original.ToUpperInvariant();

        var result = AppRelativeDirectoryResolver.Deduplicate([original, upper, original]);

        Assert.Single(result);
    }

    /// <summary>Creates N real temp directories and deletes them on dispose.</summary>
    private sealed class TempDirs : IDisposable
    {
        public IReadOnlyList<string> Dirs { get; }

        public TempDirs(int count)
        {
            var dirs = new List<string>();
            for (var i = 0; i < count; i++)
            {
                dirs.Add(Directory.CreateTempSubdirectory("aetherix-test-").FullName);
            }
            Dirs = dirs;
        }

        public void Dispose()
        {
            foreach (var dir in Dirs)
            {
                try { Directory.Delete(dir, recursive: true); } catch { /* best-effort cleanup */ }
            }
        }
    }
}
