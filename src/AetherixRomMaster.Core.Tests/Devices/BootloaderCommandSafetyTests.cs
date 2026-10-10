using System.Reflection;
using AetherixRomMaster.Core.Devices;
using Xunit;

namespace AetherixRomMaster.Core.Tests.Devices;

/// <summary>
/// A structural regression guard, not a behavioral one: this app must never gain a way to
/// unlock, relock, or otherwise modify a bootloader, no matter what new detection/attestation
/// logic gets added around it (e.g. the user-confirmed-unlocked override). If a future change
/// adds a method that could do this, this test forces a deliberate decision instead of letting
/// it slip in silently.
/// </summary>
public class BootloaderCommandSafetyTests
{
    /// <summary>The one existing, deliberately read-only exception: "fastboot oem
    /// get_unlock_data" only retrieves the unlock-data blob fastboot prints -- it never
    /// performs an unlock. See FastbootClient.OemGetUnlockDataAsync's own doc comment.</summary>
    private static readonly HashSet<string> AllowedLockRelatedMethodNames = ["OemGetUnlockDataAsync"];

    [Fact]
    public void FastbootClient_ExposesNoMethodThatCouldUnlockOrRelockABootloader()
    {
        var suspiciousMethods = typeof(FastbootClient)
            .GetMethods(BindingFlags.Public | BindingFlags.Instance | BindingFlags.DeclaredOnly)
            .Where(m => m.Name.Contains("lock", StringComparison.OrdinalIgnoreCase))
            .Where(m => !AllowedLockRelatedMethodNames.Contains(m.Name))
            .Select(m => m.Name)
            .ToList();

        Assert.Empty(suspiciousMethods);
    }

    [Fact]
    public void AdbClient_ExposesNoMethodThatCouldUnlockOrRelockABootloader()
    {
        var suspiciousMethods = typeof(AdbClient)
            .GetMethods(BindingFlags.Public | BindingFlags.Instance | BindingFlags.DeclaredOnly)
            .Where(m => m.Name.Contains("lock", StringComparison.OrdinalIgnoreCase))
            .Select(m => m.Name)
            .ToList();

        Assert.Empty(suspiciousMethods);
    }
}
