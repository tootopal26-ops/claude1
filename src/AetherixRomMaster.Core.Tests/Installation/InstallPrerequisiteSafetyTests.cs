using System.Reflection;
using AetherixRomMaster.Core.Installation;
using AetherixRomMaster.Core.Rom;
using Xunit;

namespace AetherixRomMaster.Core.Tests.Installation;

/// <summary>
/// Structural regression guard: Root, KernelSU, Magisk and Play Integrity are post-install
/// features, never prerequisites for the ROM install itself (see TASK 3/4/6 of this
/// session's own instructions). This never checks for an FRP/unlock/relock/Magisk method
/// either, for the same reason as AdvancedMaintenanceSafetyTests/BootloaderCommandSafetyTests
/// -- a reflection-based check over public method names can't catch a string buried inside
/// an existing method's body, but it guards against one of these ever becoming a new,
/// deliberately-named prerequisite without a human consciously revisiting this rule.
/// </summary>
public class InstallPrerequisiteSafetyTests
{
    private static readonly string[] ForbiddenSubstrings = ["playintegrity", "integrity", "frp", "unlock", "relock", "magisk"];

    [Theory]
    [MemberData(nameof(TypesThatGateRealFlashing))]
    public void GatingTypes_ExposeNoMethodNamedAfterAForbiddenPrerequisite(Type type)
    {
        var suspicious = type
            .GetMethods(BindingFlags.Public | BindingFlags.NonPublic | BindingFlags.Instance | BindingFlags.Static | BindingFlags.DeclaredOnly)
            .Where(m => ForbiddenSubstrings.Any(f => m.Name.Contains(f, StringComparison.OrdinalIgnoreCase)))
            .Select(m => m.Name)
            .ToList();

        Assert.Empty(suspicious);
    }

    public static IEnumerable<object[]> TypesThatGateRealFlashing() =>
        new[] { typeof(PreFlightSafetyChecker), typeof(RomValidationService), typeof(InstallStateMachine) }
            .Select(t => new object[] { t });
}
