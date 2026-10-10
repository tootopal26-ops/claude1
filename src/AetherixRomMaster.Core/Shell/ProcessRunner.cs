using System.Diagnostics;
using System.Text;

namespace AetherixRomMaster.Core.Shell;

public sealed class ProcessRunner : IProcessRunner
{
    public async Task<ProcessResult> RunAsync(
        string executablePath,
        IReadOnlyList<string> arguments,
        TimeSpan timeout,
        CancellationToken cancellationToken = default)
    {
        var stdout = new StringBuilder();
        var stderr = new StringBuilder();
        var result = await RunStreamingAsync(
            executablePath,
            arguments,
            _ => { },
            timeout,
            cancellationToken,
            stdout,
            stderr);
        return result;
    }

    public Task<ProcessResult> RunStreamingAsync(
        string executablePath,
        IReadOnlyList<string> arguments,
        Action<string> onOutputLine,
        TimeSpan timeout,
        CancellationToken cancellationToken = default)
        => RunStreamingAsync(executablePath, arguments, onOutputLine, timeout, cancellationToken, new StringBuilder(), new StringBuilder());

    private static async Task<ProcessResult> RunStreamingAsync(
        string executablePath,
        IReadOnlyList<string> arguments,
        Action<string> onOutputLine,
        TimeSpan timeout,
        CancellationToken cancellationToken,
        StringBuilder stdout,
        StringBuilder stderr)
    {
        if (!File.Exists(executablePath))
        {
            return new ProcessResult
            {
                ExitCode = -1,
                StandardOutput = string.Empty,
                StandardError = $"Executable not found: {executablePath}",
            };
        }

        using var process = new Process
        {
            StartInfo = new ProcessStartInfo
            {
                FileName = executablePath,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                UseShellExecute = false,
                CreateNoWindow = true,
            },
            EnableRaisingEvents = true,
        };

        foreach (var arg in arguments)
        {
            process.StartInfo.ArgumentList.Add(arg);
        }

        process.OutputDataReceived += (_, e) =>
        {
            if (e.Data is null) return;
            stdout.AppendLine(e.Data);
            onOutputLine(e.Data);
        };
        process.ErrorDataReceived += (_, e) =>
        {
            if (e.Data is null) return;
            stderr.AppendLine(e.Data);
            onOutputLine(e.Data);
        };

        using var timeoutCts = new CancellationTokenSource(timeout);
        using var linkedCts = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken, timeoutCts.Token);

        try
        {
            process.Start();
            process.BeginOutputReadLine();
            process.BeginErrorReadLine();

            await process.WaitForExitAsync(linkedCts.Token);

            return new ProcessResult
            {
                ExitCode = process.ExitCode,
                StandardOutput = stdout.ToString(),
                StandardError = stderr.ToString(),
            };
        }
        catch (OperationCanceledException)
        {
            TryKill(process);

            return new ProcessResult
            {
                ExitCode = -1,
                StandardOutput = stdout.ToString(),
                StandardError = stderr.ToString(),
                TimedOut = timeoutCts.IsCancellationRequested && !cancellationToken.IsCancellationRequested,
            };
        }
    }

    private static void TryKill(Process process)
    {
        try
        {
            if (!process.HasExited)
            {
                process.Kill(entireProcessTree: true);
            }
        }
        catch
        {
            // Process may have exited between the check and the kill; nothing else to do.
        }
    }
}
