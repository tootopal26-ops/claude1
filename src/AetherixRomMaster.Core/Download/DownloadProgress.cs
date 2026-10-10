namespace AetherixRomMaster.Core.Download;

public sealed record DownloadProgress(long BytesReceived, long? TotalBytes)
{
    public double? PercentComplete => TotalBytes is > 0 ? Math.Clamp(100.0 * BytesReceived / TotalBytes.Value, 0, 100) : null;
}
