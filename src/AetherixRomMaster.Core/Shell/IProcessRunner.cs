namespace AetherixRomMaster.Core.Shell;

/// <summary>
/// Abstraction over launching an external executable (adb.exe / fastboot.exe).
/// Exists so the detection and flashing logic can be unit-tested with a fake
/// runner instead of a real connected device — see AetherixRomMaster.Core.Tests.
/// </summary>
public interface IProcessRunner
{
    Task<ProcessResult> RunAsync(
        string executablePath,
        IReadOnlyList<string> arguments,
        TimeSpan timeout,
        CancellationToken cancellationToken = default);

    /// <summary>
    /// Runs a long-lived command (e.g. sideload, flashall) and streams each output
    /// line to <paramref name="onOutputLine"/> as it arrives, for live log display.
    /// </summary>
    Task<ProcessResult> RunStreamingAsync(
        string executablePath,
        IReadOnlyList<string> arguments,
        Action<string> onOutputLine,
        TimeSpan timeout,
        CancellationToken cancellationToken = default);
}
