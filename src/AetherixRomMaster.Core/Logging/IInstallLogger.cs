namespace AetherixRomMaster.Core.Logging;

public interface IInstallLogger
{
    event EventHandler<InstallLogEntry>? EntryLogged;

    IReadOnlyList<InstallLogEntry> Entries { get; }

    string? LogFilePath { get; }

    void Log(LogLevel level, string source, string message);
}
