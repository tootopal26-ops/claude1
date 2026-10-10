namespace AetherixRomMaster.Core.Recovery;

/// <summary>A file a recovery option would use -- always one already selected and
/// checksum-checked earlier in this session, never a newly sourced one.</summary>
public sealed record RecoveryRequiredFile(string Role, string Path, bool ChecksumVerified, string? Sha256);
