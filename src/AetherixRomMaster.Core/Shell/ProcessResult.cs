namespace AetherixRomMaster.Core.Shell;

public sealed class ProcessResult
{
    public required int ExitCode { get; init; }
    public required string StandardOutput { get; init; }
    public required string StandardError { get; init; }
    public bool TimedOut { get; init; }

    public bool Succeeded => ExitCode == 0 && !TimedOut;

    public string CombinedOutput => string.IsNullOrEmpty(StandardError)
        ? StandardOutput
        : StandardOutput + "\n" + StandardError;
}
