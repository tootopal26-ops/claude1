using System.IO.Compression;

namespace AetherixRomMaster.Core.Tests.TestDoubles;

public static class RomZipFixtures
{
    public static string CreateInitialInstallPackage(string directory, string fileName, string board = "cancunf")
    {
        var path = Path.Combine(directory, fileName);
        using var archive = ZipFile.Open(path, ZipArchiveMode.Create);
        var entry = archive.CreateEntry("android-info.txt");
        using (var writer = new StreamWriter(entry.Open()))
        {
            writer.Write($"require board={board}\nrequire version-bootloader=TEST-BL-1\n");
        }
        return path;
    }

    public static string CreateLegacyRomZip(string directory, string fileName, string preDevice = "cancunf")
    {
        var path = Path.Combine(directory, fileName);
        using var archive = ZipFile.Open(path, ZipArchiveMode.Create);
        var entry = archive.CreateEntry("META-INF/com/android/metadata");
        using (var writer = new StreamWriter(entry.Open()))
        {
            writer.Write($"pre-device={preDevice}\npost-build=motorola/{preDevice}/{preDevice}:15/TEST/0001:user/release-keys\nota-type=BLOCK\n");
        }
        return path;
    }

    public static string CreateInvalidZip(string directory, string fileName)
    {
        var path = Path.Combine(directory, fileName);
        using var archive = ZipFile.Open(path, ZipArchiveMode.Create);
        var entry = archive.CreateEntry("readme.txt");
        using (var writer = new StreamWriter(entry.Open()))
        {
            writer.Write("This is not a flashable Android package.");
        }
        return path;
    }

    public static void WriteSiblingChecksum(string filePath, string sha256Hex)
        => File.WriteAllText(filePath + ".sha256", $"{sha256Hex}  {Path.GetFileName(filePath)}\n");
}
