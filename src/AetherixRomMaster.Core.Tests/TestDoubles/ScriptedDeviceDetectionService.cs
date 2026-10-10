using AetherixRomMaster.Core.Devices;

namespace AetherixRomMaster.Core.Tests.TestDoubles;

/// <summary>
/// Always returns whatever <see cref="Current"/> is set to. Tests drive multi-phase
/// device transitions (ADB -> fastbootd -> sideload -> ADB again) by mutating
/// <see cref="Current"/> between the StepResults an `await foreach` over
/// InstallStateMachine.RunAsync yields, so a polling wait inside one state sees the
/// right answer on its very first check instead of real hardware timing.
/// </summary>
public sealed class ScriptedDeviceDetectionService : IDeviceDetectionService
{
    public DeviceInfo Current { get; set; } = DeviceInfo.Disconnected;

    public Task<DeviceInfo> DetectAsync(CancellationToken ct = default) => Task.FromResult(Current);
}
