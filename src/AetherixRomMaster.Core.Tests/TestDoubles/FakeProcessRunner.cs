using AetherixRomMaster.Core.Shell;

namespace AetherixRomMaster.Core.Tests.TestDoubles;

/// <summary>
/// Deterministic stand-in for a real adb/fastboot process. Tests configure
/// <see cref="Handler"/> to return canned output for specific argument lists, so device
/// detection / flashing logic can be exercised without a real device or binary.
/// </summary>
public sealed class FakeProcessRunner : IProcessRunner
{
    public List<(string Exe, IReadOnlyList<string> Args)> Calls { get; } = [];

    public Func<string, IReadOnlyList<string>, ProcessResult>? Handler { get; set; }

    public Task<ProcessResult> RunAsync(string executablePath, IReadOnlyList<string> arguments, TimeSpan timeout, CancellationToken cancellationToken = default)
    {
        Calls.Add((executablePath, arguments));
        return Task.FromResult(Invoke(executablePath, arguments));
    }

    public Task<ProcessResult> RunStreamingAsync(
        string executablePath,
        IReadOnlyList<string> arguments,
        Action<string> onOutputLine,
        TimeSpan timeout,
        CancellationToken cancellationToken = default)
    {
        Calls.Add((executablePath, arguments));
        var result = Invoke(executablePath, arguments);
        foreach (var line in result.StandardOutput.Split('\n', StringSplitOptions.RemoveEmptyEntries))
        {
            onOutputLine(line);
        }
        return Task.FromResult(result);
    }

    private ProcessResult Invoke(string executablePath, IReadOnlyList<string> arguments)
        => Handler?.Invoke(executablePath, arguments) ?? new ProcessResult { ExitCode = 0, StandardOutput = string.Empty, StandardError = string.Empty };
}
