namespace AetherixRomMaster.Core.Profiles;

/// <summary>
/// Exactly what was checked when a profile failed to load, so the UI can show the user
/// (or a developer) something more actionable than "not loaded" -- the precise path
/// tried, whether the directory even exists, and what's actually sitting in it.
/// </summary>
public sealed record ProfileLookupDiagnostics(
    string ProfilesDirectory,
    string AttemptedDeviceProfilePath,
    bool DirectoryExists,
    bool DeviceProfileFileExists,
    IReadOnlyList<string> FilesFoundInDirectory)
{
    public string ToDisplayText()
    {
        if (!DirectoryExists)
        {
            return $"Profiles folder not found at: {ProfilesDirectory}\n" +
                   "The 'profiles' folder must sit next to AetherixRomMaster.exe. If you only copied the .exe " +
                   "out of the published folder, copy 'profiles\\' (and 'tools\\platform-tools\\' if used) alongside it too.";
        }

        if (!DeviceProfileFileExists)
        {
            var found = FilesFoundInDirectory.Count > 0
                ? string.Join(", ", FilesFoundInDirectory)
                : "(empty)";
            return $"Profiles folder found at {ProfilesDirectory}, but {Path.GetFileName(AttemptedDeviceProfilePath)} is missing.\n" +
                   $"Files actually present: {found}";
        }

        return $"Found {AttemptedDeviceProfilePath}, but it could not be parsed as valid JSON. Check the file wasn't corrupted or truncated.";
    }
}
