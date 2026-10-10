namespace AetherixRomMaster.Core.KernelSu;

public sealed class KernelSuCompatibilityAssessment
{
    public required bool KernelLooksGki { get; init; }
    public required string? ParsedKernelVersion { get; init; }
    public bool? BootImageHasKernelSuMarkers { get; init; }
    public required KernelSuRecommendedAction RecommendedAction { get; init; }
    public required string Explanation { get; init; }

    /// <summary>True when the installer must halt KernelSU-related steps and explain
    /// the limitation to the user rather than guessing or claiming success.</summary>
    public bool RequiresManualStop =>
        RecommendedAction is KernelSuRecommendedAction.ManualVerifiedKernelRequired or KernelSuRecommendedAction.Unsupported;
}
