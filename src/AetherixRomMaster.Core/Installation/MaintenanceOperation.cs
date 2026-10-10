namespace AetherixRomMaster.Core.Installation;

/// <summary>One entry in the Advanced Maintenance list: an operation, how risky it is, and
/// why -- the explanation is mandatory and shown in the UI, especially for anything not
/// available, so "disabled" is never left unexplained.</summary>
public sealed record MaintenanceOperation(
    string Id,
    string Name,
    MaintenanceOperationCategory Category,
    string Explanation,
    bool IsAvailable);
