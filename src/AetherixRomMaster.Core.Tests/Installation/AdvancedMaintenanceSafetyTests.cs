using System.Reflection;
using AetherixRomMaster.Core.Installation;
using Xunit;

namespace AetherixRomMaster.Core.Tests.Installation;

/// <summary>Structural regression guards for the Advanced Maintenance feature: no matter
/// what operations get added later, these types must never gain an FRP-bypass, a
/// bootloader unlock/relock, or anything Magisk-related.</summary>
public class AdvancedMaintenanceSafetyTests
{
    private static readonly string[] ForbiddenSubstrings = ["frp", "unlock", "relock", "magisk"];

    [Fact]
    public void AdvancedMaintenanceExecutor_ExposesNoForbiddenMethod()
    {
        var suspicious = typeof(AdvancedMaintenanceExecutor)
            .GetMethods(BindingFlags.Public | BindingFlags.Instance | BindingFlags.DeclaredOnly)
            .Where(m => ForbiddenSubstrings.Any(f => m.Name.Contains(f, StringComparison.OrdinalIgnoreCase)))
            .Select(m => m.Name)
            .ToList();

        Assert.Empty(suspicious);
    }

    [Fact]
    public void AdvancedMaintenancePlanner_NeverListsAForbiddenOperation()
    {
        var operations = new AdvancedMaintenancePlanner().BuildOperations(romProfile: null);

        var suspicious = operations
            .Where(o => ForbiddenSubstrings.Any(f =>
                o.Id.Contains(f, StringComparison.OrdinalIgnoreCase) ||
                o.Name.Contains(f, StringComparison.OrdinalIgnoreCase)))
            .Select(o => o.Id)
            .ToList();

        Assert.Empty(suspicious);
    }
}
