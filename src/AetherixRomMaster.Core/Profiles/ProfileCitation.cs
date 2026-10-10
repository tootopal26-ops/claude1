namespace AetherixRomMaster.Core.Profiles;

/// <summary>A source consulted while building a profile, kept so a human can re-verify it.</summary>
public sealed record ProfileCitation(string Title, string Url, string Note);
