namespace AetherixRomMaster.Core.Download;

/// <summary>
/// Downloads exactly the URL the user/profile gives it — this app never guesses or
/// constructs a download URL itself (see RomProfile.ChecksumManifestUrl and the
/// VerificationGapNotes on each ROM profile: real URLs must come from an official
/// source a human has confirmed). Only http(s) is accepted.
/// </summary>
public sealed class FileDownloadService
{
    private readonly HttpClient _httpClient;

    public FileDownloadService(HttpClient? httpClient = null)
    {
        _httpClient = httpClient ?? new HttpClient();
    }

    public async Task DownloadFileAsync(
        Uri sourceUrl,
        string destinationPath,
        IProgress<DownloadProgress>? progress = null,
        CancellationToken ct = default)
    {
        if (sourceUrl.Scheme is not ("http" or "https"))
        {
            throw new ArgumentException($"Only http/https URLs are supported, got: {sourceUrl.Scheme}", nameof(sourceUrl));
        }

        var tempPath = destinationPath + ".part";
        Directory.CreateDirectory(Path.GetDirectoryName(destinationPath) ?? ".");

        using (var response = await _httpClient.GetAsync(sourceUrl, HttpCompletionOption.ResponseHeadersRead, ct))
        {
            response.EnsureSuccessStatusCode();
            var totalBytes = response.Content.Headers.ContentLength;

            await using var httpStream = await response.Content.ReadAsStreamAsync(ct);
            await using var fileStream = new FileStream(tempPath, FileMode.Create, FileAccess.Write, FileShare.None, 81920, useAsync: true);

            var buffer = new byte[81920];
            long totalRead = 0;
            int read;
            while ((read = await httpStream.ReadAsync(buffer, ct)) > 0)
            {
                await fileStream.WriteAsync(buffer.AsMemory(0, read), ct);
                totalRead += read;
                progress?.Report(new DownloadProgress(totalRead, totalBytes));
            }
        }

        File.Move(tempPath, destinationPath, overwrite: true);
    }
}
