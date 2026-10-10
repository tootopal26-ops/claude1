namespace AetherixRomMaster.Core.Logging;

public sealed record InstallLogEntry(DateTimeOffset Timestamp, LogLevel Level, string Source, string Message)
{
    public override string ToString() => $"[{Timestamp:HH:mm:ss.fff}] [{Level,-8}] [{Source}] {Message}";
}
