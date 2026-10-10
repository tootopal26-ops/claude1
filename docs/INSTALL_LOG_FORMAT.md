# Installation log format

Every dry run and every real install writes a plain-text diagnostic log, one file per
run, so a failure can always be diagnosed or reported after the fact — see
`src/AetherixRomMaster.Core/Logging/InstallLogger.cs`.

## Location

```
<app folder>\logs\install-yyyyMMdd-HHmmss.log
```

A new file is created at the start of every dry run or real run (`MainViewModel.RunAsync`
creates a fresh `InstallLogger` per run). A separate crash log
(`logs\crash-yyyyMMdd-HHmmss.log`) is written by `App.xaml.cs` if the UI itself hits an
unhandled exception, independent of any in-progress install log.

Entries are flushed to disk immediately as they're written (`AutoFlush = true`), not
buffered — so the log reflects the true last state even if the app is killed or crashes
mid-operation.

## Line format

```
[HH:mm:ss.fff] [LEVEL   ] [Source] Message
```

Example:

```
[14:22:01.118] [Info    ] [StateMachine] --- DetectDevice ---
[14:22:01.402] [Info    ] [DetectDevice] Mode=Adb, Manufacturer=motorola, Model=moto g64 5g, Codename=cancunf, Android=15, Kernel=5.15.104-android13-8-weighu+, Bootloader=Unlocked, Root=KernelSU
[14:22:01.403] [Info    ] [StateMachine] --- VerifyBootloader ---
[14:22:03.880] [Info    ] [StateMachine] --- VerifyRom ---
[14:22:04.012] [Error   ] [VerifyRom] [CHECKSUM_MISMATCH] Computed SHA-256 (...) does not match the expected checksum from sibling checksum file (...)
```

## Levels

| Level | Meaning |
|---|---|
| `Debug` | Raw adb/fastboot output lines (streamed command output) |
| `Info` | Normal state transitions and informational findings |
| `Warning` | A dry-run-only "would have blocked" condition, or a non-fatal limitation (e.g. KernelSU root could not be established, but the ROM itself installed fine) |
| `Error` | A specific compatibility/verification issue (see `RomCompatibilityIssue.Code`) |
| `Critical` | Reserved for conditions the UI treats as unrecoverable |

## Source tags

`Source` names the component that produced the line, matching the class/state that
logged it: `StateMachine`, `DetectDevice`, `VerifyBootloader`, `VerifyRom`,
`VerifyChecksums`, `VerifyKernelSuCompatibility`, `RestoreKernelSu`, `Fastboot`, `Adb`,
`DryRun`, `Complete`, etc. Grepping a log for a specific `Source` tag isolates that
component's activity across the whole run.

## Why the final line never says "rooted" on faith

The `Complete` entry is built from `InstallContext.PostInstallRoot`, which is only ever
populated by `RootDetector.DetectAsync` — and that method requires the device to
actually print `uid=0` in response to `su -c id` (plus a `/data/adb/ksu` marker check to
confirm *which* root solution) before reporting `RootSolutionType.KernelSU`. A `0` exit
code alone is never sufficient; see
`src/AetherixRomMaster.Core/Devices/RootDetector.cs` and
`AetherixRomMaster.Core.Tests/Devices/RootDetectorTests.cs` for the tests enforcing
this. If root could not be confirmed, the `Complete` line says
`KernelSU root: NOT ESTABLISHED` and names the reason — it never says `ACTIVE` unless
that query actually succeeded on the booted device, in that run.
