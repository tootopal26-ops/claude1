namespace AetherixRomMaster.Core.Installation;

public enum InstallMode
{
    /// <summary>Detects, inspects, and plans every operation but never calls a destructive
    /// adb/fastboot command. Always available, and always safe to run.</summary>
    DryRun,

    /// <summary>Executes real, potentially destructive operations. Only reachable once a
    /// dry run of the same plan has completed with no blocking issues and the user has
    /// given the explicit confirmations the plan requires.</summary>
    Real
}
