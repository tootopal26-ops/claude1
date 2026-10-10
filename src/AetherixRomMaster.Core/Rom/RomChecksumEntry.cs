namespace AetherixRomMaster.Core.Rom;

/// <summary>
/// One checksum published by a ROM's own official source for one specific distribution
/// variant (e.g. the separate GApps/Vanilla builds Infinity-X publishes for cancunf).
/// Value must be copied verbatim from the cited official source -- never invented or
/// derived. Matching a local file against a list of these is also how this app
/// determines which variant that file actually is, instead of ever inferring it from a
/// filename alone.
/// </summary>
/// <param name="VariantLabel">Short human label, e.g. "GApps" or "Vanilla".</param>
/// <param name="Algorithm">"MD5" or "SHA256" -- whatever the official source actually
/// publishes. Many ROM download pages only publish MD5; this app never pretends an
/// independently-computed SHA-256 is an official checksum when only MD5 was published.</param>
/// <param name="Value">The checksum value itself, exactly as published.</param>
/// <param name="Source">Human-readable provenance: who recorded this value, from where,
/// and when -- e.g. whether this application's own tooling fetched it directly, or the
/// device owner transcribed it from the official page themselves. Shown to the user
/// verbatim so the provenance is never hidden.</param>
public sealed record RomChecksumEntry(string VariantLabel, string Algorithm, string Value, string Source);
