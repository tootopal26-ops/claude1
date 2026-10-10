using AetherixRomMaster.Core.Rom;

namespace AetherixRomMaster.Core.KernelSu.Restoration;

/// <summary>
/// A real file on disk this session has fingerprinted -- an original (pre-patch) or
/// KernelSU-patched boot-related image. Always built from an actual file via
/// <see cref="FromFileAsync"/>, never constructed with a hand-typed hash.
/// </summary>
public sealed record ImageArtifact(
    string FilePath,
    string Sha256,
    long SizeBytes,
    KernelImageType? ImageType,
    DateTimeOffset RecordedAt)
{
    public static async Task<ImageArtifact> FromFileAsync(
        string filePath,
        KernelImageType? imageType,
        ChecksumVerifier checksum,
        CancellationToken ct = default)
    {
        var info = new FileInfo(filePath);
        var sha256 = await checksum.ComputeSha256Async(filePath, ct);
        return new ImageArtifact(filePath, sha256, info.Length, imageType, DateTimeOffset.Now);
    }
}
