using System.Collections.Concurrent;

namespace AetherixRomMaster.Core.Logging;

/// <summary>
/// Every entry is appended to disk immediately (not buffered) so a diagnostic log
/// survives even if the app crashes mid-flash — this is the log the recovery-guidance
/// panel and any bug report must be able to point at.
/// </summary>
public sealed class InstallLogger : IInstallLogger, IDisposable
{
    private readonly ConcurrentQueue<InstallLogEntry> _entries = new();
    private readonly StreamWriter? _writer;
    private readonly object _writeLock = new();

    public event EventHandler<InstallLogEntry>? EntryLogged;

    public string? LogFilePath { get; }

    public IReadOnlyList<InstallLogEntry> Entries => _entries.ToList();

    public InstallLogger(string logDirectory)
    {
        Directory.CreateDirectory(logDirectory);
        LogFilePath = Path.Combine(logDirectory, $"install-{DateTimeOffset.Now:yyyyMMdd-HHmmss}.log");
        _writer = new StreamWriter(LogFilePath, append: true) { AutoFlush = true };
    }

    public void Log(LogLevel level, string source, string message)
    {
        var entry = new InstallLogEntry(DateTimeOffset.Now, level, source, message);
        _entries.Enqueue(entry);

        lock (_writeLock)
        {
            _writer?.WriteLine(entry.ToString());
        }

        EntryLogged?.Invoke(this, entry);
    }

    public void Dispose()
    {
        _writer?.Dispose();
    }
}
