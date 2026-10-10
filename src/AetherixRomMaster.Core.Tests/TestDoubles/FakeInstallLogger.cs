using AetherixRomMaster.Core.Logging;

namespace AetherixRomMaster.Core.Tests.TestDoubles;

public sealed class FakeInstallLogger : IInstallLogger
{
    private readonly List<InstallLogEntry> _entries = [];

    public event EventHandler<InstallLogEntry>? EntryLogged;

    public IReadOnlyList<InstallLogEntry> Entries => _entries;

    public string? LogFilePath => null;

    public void Log(LogLevel level, string source, string message)
    {
        var entry = new InstallLogEntry(DateTimeOffset.Now, level, source, message);
        _entries.Add(entry);
        EntryLogged?.Invoke(this, entry);
    }
}
