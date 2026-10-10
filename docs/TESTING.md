# Automated tests

```powershell
dotnet test src\AetherixRomMaster.Core.Tests\AetherixRomMaster.Core.Tests.csproj
```

79 tests, all passing, all offline (no phone, no real `adb`/`fastboot` binary, no
network) — they run against `FakeProcessRunner`, `FakeFlashExecutor`, and
`ScriptedDeviceDetectionService` test doubles under `AetherixRomMaster.Core.Tests/TestDoubles/`.
Verified both locally (Linux, this count) and on a real `windows-latest` GitHub Actions
runner via `.github/workflows/windows-build.yml` (61/61 at the commit that added that
workflow, before the Recovery/Pre-Flight tests below existed — see the badge in the root
README for the current run against the full 79).

Separately, `docs/BUILD.md`'s "why no pre-built .exe" note aside, a real (non-fake,
apt-installed) `adb`/`fastboot` integration check was also run manually against this
Core library with no device attached, confirming graceful "no device" handling and zero
destructive subprocess calls end-to-end -- this wasn't kept as a checked-in automated
test since it depends on real platform-tools binaries being present on the machine
running it, but the same guarantee is covered continuously by the fake-based tests below.

## Coverage against the required scenarios

| Required scenario | Test(s) |
|---|---|
| Correct device detection | `DeviceDetectionServiceTests.DetectAsync_PopulatesDeviceInfo_ForSupportedCancunfDevice` |
| Wrong-device rejection | `DeviceCompatibilityCheckerTests.Check_Rejects_WrongDeviceCodename`, `.Check_NeverTrustsModelNameAlone_WhenCodenameMismatches`, `RomValidationServiceTests.ValidateAsync_RejectsRomBuiltForADifferentDevice` / `.ValidateAsync_RejectsConnectedDeviceNotInSupportedList` |
| Invalid ZIP rejection | `RomPackageInspectorTests.InspectAsync_ClassifiesUnknown_ForArbitraryZipWithNoAndroidManifest`, `RomValidationServiceTests.ValidateAsync_RejectsInvalidZip_AsUnrecognizedFormat` |
| Checksum mismatch | `ChecksumVerifierTests` (4 tests), `RomValidationServiceTests.ValidateAsync_RejectsChecksumMismatch` / `.ValidateAsync_RejectsWhenNoChecksumAvailableAtAll` |
| Missing initial-install package | `InstallStateMachineTests.VerifyRom_Fails_WhenRequiredInitialInstallPackageIsMissing` |
| Unsupported KernelSU kernel | `KernelSuCompatibilityCheckerTests.AssessAsync_ReportsUnsupported_ForNonGkiOrOldKernels` / `.AssessAsync_NeverClaimsSuccess_WhenKernelVersionIsUnknown` |
| ADB/Fastboot disconnection | `InstallStateMachineTests.DetectDevice_Fails_WhenNoDeviceIsConnected`, `DeviceDetectionServiceTests.DetectAsync_ReturnsDisconnected_WhenNoAdbOrFastbootDevicesPresent` |
| Flashing failures | `InstallStateMachineTests.EnterRequiredInstallationMode_Fails_WhenRebootCommandItselfFails`, `.InstallRom_Fails_WhenSideloadFails_AfterSuccessfulModeTransitions` |
| Data-wipe confirmation | `InstallStateMachineTests.BackupAndDataWipe_RequireExplicitConfirmation_ThenResume` |
| Successful installation verification | `InstallStateMachineTests.FullDryRunWalk_ReachesComplete_WithNoFailedStep`, `.FullRealWalk_NeverClaimsRootActive_WhenNoRootIsDetectedOnBootedDevice` |
| "Exit code 0 ≠ rooted" | `RootDetectorTests.DetectAsync_DoesNotReportRoot_WhenExitCodeIsZeroButOutputLacksUidZero` (the specific scenario the task calls out by name) |
| Never promises automatic hard-brick recovery | `RecoveryPlannerTests.BuildSessionAsync_NeverPromisesRecovery_ForBootloop_WhenDeviceUnreachable`, `.BuildSessionAsync_NeverOffersExecutableOption_WhenPathIsNotAvailable` |
| Recovery retry never reuses an unverified file | `RecoveryPlannerTests.BuildSessionAsync_NeverOffersRetryWithSameFile_ForChecksumMismatch` |
| Pre-Flash Safety Check gates real installs | `PreFlightSafetyCheckerTests.RunAsync_CanProceed_WhenEverythingIsVerified`, `.RunAsync_Blocks_WhenDeviceIsWrongCodename` / `.RunAsync_Blocks_WhenBootloaderIsLocked` / `.RunAsync_Blocks_WhenChecksumsNotYetVerified` / `.RunAsync_Blocks_WhenRecoveryPathNotAvailable` |
| KernelSU gap never blocks the ROM install itself | `PreFlightSafetyCheckerTests.RunAsync_DoesNotBlock_WhenOnlyKernelSuItemIsUnconfirmed` |

## Other tests worth knowing about

- `ProfileRepositoryTests` loads the **actual shipped** `profiles/*.json` files (not
  test fixtures) — a typo in the real cancunf profile fails the test suite, not just a
  user at runtime. It also asserts the shipped ROM profile's `VerificationStatus` is
  `Unverified`, so the build fails loudly if someone flips that flag without the
  corresponding real verification work.
- `DryRunFlashExecutorTests` proves dry-run's executor is structurally incapable of
  touching a device (it has no `IProcessRunner`/`AdbClient`/`FastbootClient` dependency
  at all — not just "configured off").
- `InstallEngineTests.CreateStateMachine_InDryRunMode_NeverInvokesTheConfiguredProcessRunner`
  is the integration-level version of the same guarantee: it runs a dry-run plan
  through the real `InstallEngine` wiring and asserts the process runner never saw a
  destructive command (`update`, `sideload`, `-w`, `reboot fastboot`, `reboot recovery`).
- `InstallPlanBuilderTests` confirms the plan only includes states a given ROM profile
  actually needs (e.g. `FormatDataIfRequired` is omitted entirely, not run as a no-op,
  when `RequiresDataFormat` is false).

## Test doubles reference

| Double | Purpose |
|---|---|
| `FakeProcessRunner` | Returns scripted output for specific `adb`/`fastboot` argument lists, so `AdbClient`/`FastbootClient`/`DeviceDetectionService` can be tested without a real binary |
| `FakeFlashExecutor` | Records every destructive call and can force any single operation to fail, to test the state machine's error handling |
| `ScriptedDeviceDetectionService` | Returns whatever `DeviceInfo` a test sets as `Current`, so multi-phase device transitions (ADB → fastbootd → sideload → ADB again) can be driven deterministically without real hardware timing |
| `RomZipFixtures` | Builds minimal real ZIP files (with `android-info.txt` or `META-INF/com/android/metadata`) on disk for the ROM-validation tests |
| `TestProfiles` | Cancunf device profile + Infinity-X ROM profile fixtures, in both `Verified` and `Unverified` flavors |
