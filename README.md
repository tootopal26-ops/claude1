# AETHERIX ROM MASTER

[![Windows build, test & package](https://github.com/tootopal26-ops/claude1/actions/workflows/windows-build.yml/badge.svg?branch=claude/aetherix-rom-master-app-lk80tc)](https://github.com/tootopal26-ops/claude1/actions/workflows/windows-build.yml)

A Windows desktop utility that automates installing a verified custom ROM on a
**Motorola Moto G64 5G (codename `cancunf`)** and establishing **KernelSU** root —
and nothing else. Magisk is never installed, recommended, or used anywhere in this
application.

This is a real installer, not a mockup: it drives the official Android Platform Tools
(`adb`/`fastboot`) through a device-specific state machine, validates every file against
checksums before touching the device, runs an explicit Pre-Flash Safety Check before
anything destructive is allowed to start, and refuses to proceed — or claim a hard-brick
is automatically recoverable — the moment something can't be verified.

## Current status

| Area | Status |
|---|---|
| Device detection (ADB/Fastboot/Fastbootd, bootloader lock, root, kernel) | ✅ Implemented |
| ROM package inspection & validation (metadata, device match, checksums) | ✅ Implemented |
| 21-state installation state machine | ✅ Implemented |
| Dry-run mode (full plan preview, zero destructive commands) | ✅ Implemented |
| Real flashing engine (fastboot update / sideload, gated) | ✅ Implemented |
| KernelSU compatibility assessment + honest post-flash root verification | ✅ Implemented |
| Pre-Flash Safety Check (7-point checklist gating real installs) | ✅ Implemented |
| Recovery & Rollback Safety System ("Recovery Mode") | ✅ Implemented |
| KernelSU-aware root detection (5 honest states, never "rooted"/"not rooted" guessed) | ✅ Implemented |
| Authoritative Fastboot bootloader verification + profile-load diagnostics | ✅ Implemented |
| KernelSU Root Restoration workflow (patch-via-Manager, verified image/partition only) | ✅ Implemented — gated Unverified for cancunf, see below |
| Recovery automation (auto sideload detection, manual-navigation fallback) | ✅ Implemented |
| Automation Capability report (AUTOMATIC / MANUAL / BLOCKED per step) | ✅ Implemented |
| Dark glassmorphism WPF UI | ✅ Implemented |
| Automated tests (145 tests, see `src/AetherixRomMaster.Core.Tests`) | ✅ Implemented, all passing |
| **WPF app actually compiles & runs on real Windows** | ✅ **Verified on GitHub Actions `windows-latest` — see below, not just claimed** |
| **cancunf + Infinity-X ROM profile: `VerificationStatus = Unverified`** | ⚠️ **See below — action required before real flashing** |
| **cancunf + Infinity-X KernelSU restoration profile: `VerificationStatus = Unverified`** | ⚠️ **Required image type/partition not yet confirmed — see below** |

## ✅ Verified on real Windows (not just "should build")

This project was developed in a Linux sandbox with no Windows machine attached — WPF has
no Linux/macOS build toolchain at all, so `dotnet build` on the App project cannot even
attempt compilation there (it fails at SDK-resolution, before touching a single line of
code). Rather than leave that unverified, [`.github/workflows/windows-build.yml`](.github/workflows/windows-build.yml)
builds the full solution — WPF app included — on a real `windows-latest` GitHub Actions
runner on every push, runs the whole test suite, publishes a self-contained win-x64 EXE,
and smoke-tests that the EXE actually launches and stays running (not an immediate
startup-exception crash). The badge above always reflects the latest run; check the
[Actions tab](https://github.com/tootopal26-ops/claude1/actions/workflows/windows-build.yml) for details.

Latest run observed directly during development (commit `afe53b7` — adds the KernelSU
Root Restoration workflow and Recovery/Installation Automation, including all of the
new WPF ViewModel/XAML that could not be compiled in the Linux sandbox that wrote it;
[run #8](https://github.com/tootopal26-ops/claude1/actions/runs/38057099400)):

```
dotnet build ... -c Release
  AetherixRomMaster.Core -> ...\AetherixRomMaster.Core.dll
  AetherixRomMaster.App -> ...\net8.0-windows\win-x64\AetherixRomMaster.dll
Build succeeded.

dotnet test ...
Test Run Successful.
Total tests: 145
     Passed: 145
 Total time: 5.2170 Seconds

dotnet publish ... -r win-x64 --self-contained true -p:PublishSingleFile=true ...
  AetherixRomMaster.App -> ...\publish\win-x64\
  (AetherixRomMaster.exe, 162,458,311 bytes; profiles\cancunf.infinity-x.kernelsu.json
   confirmed present in the published output alongside the device/ROM profiles)

Smoke test: Process is still running after 6 seconds (PID 8448) -- startup looks healthy.
```

Earlier verified runs in this same history: [run #6](https://github.com/tootopal26-ops/claude1/actions/runs/38054491595)
(the profile-loading/bootloader/root-detection fixes — 99/99 tests) and
[run #7](https://github.com/tootopal26-ops/claude1/actions/runs/38054626710) (the
data-wipe-consequences message) were both independently green too.

This wasn't green on the first try for the Recovery/Pre-Flight UI commit, and that's worth
being honest about: [run #3](https://github.com/tootopal26-ops/claude1/actions/runs/38051414906)
failed with a real compiler error (`MC3072: The property 'Visibility' does not exist`) —
a `<Run>` inline text element had been given a `Visibility` binding, which only a
`UIElement` (not a `TextElement`/`Inline`) supports. No amount of manual XAML
cross-checking in the Linux sandbox could have caught that; the real Windows compiler
did, immediately. It was fixed (replaced with plain `TextBlock`s) and re-verified green
in run #4 above — see the commit history for both.

## 🛡️ Recovery & Rollback Safety System ("Recovery Mode")

**This app never claims a brick is automatically recoverable.** When a real install
fails, `RecoveryPlanner` (`src/AetherixRomMaster.Core/Recovery/RecoveryPlanner.cs`)
builds a `RecoverySession` by re-querying the device live (never trusting stale
pre-failure state) and classifying the failure:

- **Connectivity issues** (device not detected, lost mid-flash, sideload dropped) →
  if the device is reachable again and the files involved are still checksum-verified,
  the only offered action is **retrying the exact same already-verified step** — no new
  file, image, or command is ever introduced. If the device isn't reachable, the only
  action offered is non-destructive guidance (reconnect, check drivers).
- **A file itself failing verification** (checksum mismatch, invalid package) → the app
  never retries with the same bad file; it tells you to re-download and re-verify.
- **Bootloop after a real flash** → `RecoveryPlanner` checks live whether the device
  still responds over fastboot or ADB. If it does, a verified retry of the already-used
  file(s) is offered. **If it does not, the app stops and says so plainly: no verified
  recovery image, partition layout, or command exists for this app to use, and manual or
  authorized-service recovery may be required.** It will not guess.
- **KernelSU not established** → never treated as a device-risk failure; the ROM install
  itself is unaffected, and this is reported separately and honestly as a root-status gap.

The UI's **Recovery Mode** panel shows: device status & current mode, last successful
state, failed state, the recovery options above (with required files and their
checksum-verified status), step-by-step recovery progress, and the full session log path
— see `MainWindow.xaml`'s "Recovery Mode" card and `MainViewModel.ActiveRecoverySession`.

Tests: `AetherixRomMaster.Core.Tests/Recovery/RecoveryPlannerTests.cs`, including
`BuildSessionAsync_NeverPromisesRecovery_ForBootloop_WhenDeviceUnreachable` — the
specific assertion that a hard-brick-shaped failure never gets an executable "fix it"
option, only an honest "stop" explanation.

## 🔑 KernelSU Root Restoration

Installing a custom ROM can replace the boot-related image (`boot.img` or
`init_boot.img`, depending on the device's kernel) and remove the existing KernelSU root
as a side effect — this is normal, expected, and not itself a failure of the ROM
install. The **KernelSU Root** card detects this and walks through a verified,
user-driven restoration:

1. **Check** — re-runs the same `RootDetector` used everywhere else in the app
   (`src/AetherixRomMaster.Core/Devices/RootDetector.cs`). If KernelSU is already
   integrated and operational, restoration is skipped entirely.
2. **Plan** — `KernelSuRestorationPlanner` resolves the exact required image type, file
   name, and target fastboot partition from a `KernelSuProfile`
   (`profiles/{codename}.{rom}.kernelsu.json`) pinned to this device/ROM/build. If that
   profile is missing, unverified, or incomplete, restoration is **Blocked** with the
   specific missing fact named — never a guessed `boot.img`/`boot` default.
3. **Prepare & transfer** — the user selects the original (pre-patch) image file
   (Aetherix does not extract it from a ROM/firmware archive itself); it's hashed and
   pushed to the device via `adb push`.
4. **Patch (user action)** — Aetherix shows the exact steps: open KernelSU Manager →
   Install → Patch Image → select the transferred file → let it patch → return to
   Aetherix. **Aetherix never patches a kernel image itself and never installs or
   recommends Magisk anywhere in this flow.**
5. **Retrieve & verify** — the user selects the resulting patched file; it's validated
   (exists, readable, plausible size, and not byte-identical to the original — a real,
   concrete check that the Manager actually produced something) and hashed.
6. **Safety summary** — before any flashing, a mandatory summary (device, ROM, build,
   image type, file, SHA-256, partition) is shown with the exact required confirmation
   sentence. If anything is unverified, this step is never reached — the user sees
   *"KernelSU restoration cannot continue safely..."* instead.
7. **Install** — reboots to bootloader, best-effort re-verifies the device's fastboot
   `product` matches the expected codename, flashes **only** the verified partition via
   `fastboot flash`, reboots to system.
8. **Verify** — waits for ADB, then re-runs `RootDetector` again. KernelSU being
   integrated-but-not-yet-granted-to-Aetherix is still reported as success (with that
   caveat) — "KernelSU exists" and "Aetherix currently has root permission" are always
   kept as separate, honestly-labeled facts, never conflated.

Dry Run simulates every step above (including the file-selection/patch-wait UI flow)
without pushing, patching, or flashing anything real. See
`src/AetherixRomMaster.Core/KernelSu/Restoration/` for the full implementation
(`KernelSuRestorationSession`, `KernelSuRestorationPlanner`, `ImageArtifact`,
`KernelSuProfile`) and `AetherixRomMaster.Core.Tests/KernelSu/Restoration/` for its tests.

**cancunf + Infinity-X is Unverified today** — `profiles/cancunf.infinity-x.kernelsu.json`
has no confirmed image type/file name/partition yet, so real restoration for this exact
device/ROM is blocked until a human confirms those facts (e.g. from the device's own
kernel `uname -r` GKI marker plus the ROM's own install instructions) and updates that
file. This is the same Unverified-blocks-real-action pattern as the ROM profile itself.

## 🤖 Recovery & Installation Automation

Every step Aetherix can drive through a documented ADB/Fastboot/recovery interface is
automated; anything that needs a human (a destructive confirmation, or a recovery that
needs its menu navigated by hand) stops and asks, rather than guessing or simulating
touches:

- **Recovery state is a real, distinct signal.** `adb devices` reports `recovery` and
  `sideload` as different states; `DeviceDetectionService` now tells them apart
  (`ConnectionMode.AdbRecoveryManual` vs `ConnectionMode.AdbSideload`) instead of
  treating a plain-recovery ADB session like a normally-booted system.
- **Sideload is attempted automatically first.** If the recovery enters ADB sideload
  mode on its own after `fastboot reboot recovery`, Aetherix proceeds with no user
  interaction. If it only reaches plain recovery ADB (most custom recoveries need the
  menu navigated to "Apply Update → Apply from ADB"), Aetherix shows **"Recovery
  requires manual interaction"** with the exact instructions and waits for confirmation
  — it never attempts to tap or script the recovery's touchscreen UI itself. Retrying
  never re-issues the reboot command to a device that's already left fastboot mode.
- **Format Data always shows a dedicated, itemized warning** (device, codename, ROM,
  Android version, reason for the wipe, expected result) before the one explicit
  confirmation gate, never bypassed.
- **Automation Capability report** (`AutomationCapabilityPlanner`) lists every pipeline
  step with an honest AUTOMATIC / needs-confirmation / MANUAL / BLOCKED label and why —
  shown in the UI's "Automation Capability" card and usable as a Dry Run preview of
  what would and wouldn't need you. A step is only ever marked Automatic when the
  profile data backing it is actually Verified.

See `RunInstallRomAsync`/`RunRequestDataWipeConfirmationAsync` in
`src/AetherixRomMaster.Core/Installation/InstallStateMachine.cs` and
`AetherixRomMaster.Core/Installation/AutomationCapabilityPlanner.cs`.

## ✅ Pre-Flash Safety Check

Before **Install Custom ROM** is enabled, `PreFlightSafetyChecker`
(`src/AetherixRomMaster.Core/Installation/PreFlightSafetyChecker.cs`) runs seven checks,
all shown in the UI, re-querying the device fresh rather than trusting a stale dry run:

1. Device is exactly Moto G64 5G / `cancunf` (codename match, never model name)
2. Bootloader state is Unlocked (read from the device, never user-selectable)
3. Required package(s) are selected and present on disk
4. SHA-256 checksums verified against an official source
5. ROM compatible with this device and profile (no blocking validation issues)
6. KernelSU compatibility confirmed — shown but **does not block** installing the ROM
   itself (an unconfirmed KernelSU path affects root only, not device safety; this is
   the one deliberate non-blocking item, and the UI labels it as such)
7. A verified recovery path would exist if an early/mid-plan step failed (device
   reachable + files already checksum-verified) — explicitly **not** a promise that
   every possible failure, including a hard-brick mid-flash, is recoverable

Tests: `AetherixRomMaster.Core.Tests/Installation/PreFlightSafetyCheckerTests.cs`.

## ⚠️ Before you flash anything real: the one gap you must close

This build environment's network egress policy blocked direct access to the ROM's
official pages (`cyberknight777.dev`, `projectinfinity-x.com`, `droidwin.com` all
returned HTTP 403 — an organization policy block, not a transient failure). Research via
search confirmed, with multiple independent sources, that:

- `cancunf` is the shared codename for the Moto G54 5G **and** Moto G64 5G.
- Project Infinity X ships Android-16-based builds for `cancunf`, installed via
  `fastboot --skip-reboot update <initial-install>.zip` then `adb sideload <rom>.zip`
  from the stock recovery.
- cancunf is a MediaTek device with anti-rollback (ARB) enforcement — flashing firmware
  older than the fused ARB index can **hard-brick** the device.

What could **not** be confirmed first-hand, and is therefore deliberately left blank
rather than invented anywhere in this codebase: the exact current Infinity-X download
URL, the ROM zip's file name, its published SHA-256 checksum, and the verbatim
initial-install command sequence for the current build.

A later session re-attempted this directly (`https://projectinfinity-x.com/downloads/cancunf`)
and hit the same network policy block (this time a DNS failure rather than HTTP 403 — a
sandbox/proxy denial either way, not evidence the page is down). A web-search sweep at
that time additionally found no independent confirmation that an Infinity-X build for
cancunf exists at all — one search summary stated the official Infinity-X device list
shows 0 supported devices, though that's a search-snippet inference, not a direct page
read, so it's recorded as a open question, not a fact, in `profiles/cancunf.infinity-x.rom.json`'s
citations. **If you have ROM/initial-install files already downloaded, personally verify
they came from the real official page before trusting them** — do not assume a local
file is genuine Infinity-X/cancunf without checking its exact name/size/checksum against
that source yourself.

**`profiles/cancunf.infinity-x.rom.json`** encodes this honestly: `verificationStatus`
is `"Unverified"`, and `RomValidationService` (see
`src/AetherixRomMaster.Core/Rom/RomValidationService.cs`) hard-blocks real flashing
with a `ROM_PROFILE_UNVERIFIED` issue (surfaced in the Pre-Flash Safety Check's "ROM
compatible" item too) until that field is changed to `"Verified"`. Dry run works today,
right now, with no changes needed.

**To unlock real flashing:** open the cited pages below yourself, confirm the current
build's exact file name/checksum/commands, then either place a `.sha256` file next to
the downloaded ROM zip (the app reads it automatically) and flip
`verificationStatus` to `"Verified"` in the profile, or supply a checksum directly in
the app. Never hand-edit that field to `"Verified"` without actually re-checking the
source — that field exists specifically so nobody, including a future AI session, skips
this step.

Sources to check:
- https://cyberknight777.dev/instructions/cancunf/ — device-specific flashing guide
- https://projectinfinity-x.com/download/gsi/ — official Infinity-X site (this exact
  page is the GSI/Treble downloads list, not the cancunf device build — look for the
  device-specific download section from here)
- https://droidwin.com/how-to-install-android-16-custom-roms-on-moto-g54-5g-g64/

## Supported device (v1)

- **Device:** Motorola Moto G64 5G (also covers Moto G54 5G — same codename)
- **Codename:** `cancunf`
- **Chipset:** MediaTek Dimensity 7025
- Any other device is rejected at the `VERIFY_BOOTLOADER` state by codename match —
  never by model name.

## Repository layout

```
.github/workflows/
  windows-build.yml                Real windows-latest CI: build, test, publish, smoke-test
src/
  AetherixRomMaster.Core/          Device detection, ROM validation, state machine,
                                    KernelSU compatibility, Recovery & Pre-Flight safety
                                    systems, logging — cross-platform, no WPF dependency.
    Recovery/                      RecoveryPlanner, RecoverySession, RecoveryOption
    KernelSu/Restoration/          KernelSuRestorationSession/Planner/Profile, ImageArtifact
    Installation/
      AutomationCapabilityPlanner.cs  AUTOMATIC/MANUAL/BLOCKED per-step reporting
  AetherixRomMaster.Core.Tests/    145 xUnit tests (see docs/TESTING.md)
  AetherixRomMaster.App/           WPF UI (net8.0-windows — Windows-only to build/run)
profiles/
  cancunf.device.json              Device profile (researched facts + citations)
  cancunf.infinity-x.rom.json      ROM profile (verification-gated, see above)
  cancunf.infinity-x.kernelsu.json KernelSU restoration profile (verification-gated)
assets/icon/                       App icon source (source.png) + rendered sizes/.ico
docs/
  BUILD.md                         Windows build instructions
  DEPENDENCIES.md                  Platform tools / USB drivers / SDK setup
  INSTALL_LOG_FORMAT.md            Diagnostic log format reference
  TESTING.md                       What each test proves and how to run them
```

## Quick start (Windows)

See [`docs/BUILD.md`](docs/BUILD.md) for full instructions, including how to produce a
packaged `.exe`. Short version:

```powershell
dotnet restore src\AetherixRomMaster.sln
dotnet build src\AetherixRomMaster.sln -c Release
dotnet run --project src\AetherixRomMaster.App -c Release
```

To build the same way CI does, including the packaged EXE:

```powershell
dotnet test src\AetherixRomMaster.Core.Tests\AetherixRomMaster.Core.Tests.csproj -c Release
dotnet publish src\AetherixRomMaster.App -c Release -r win-x64 --self-contained true `
    -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true -o publish\win-x64
```

Then copy Android Platform Tools into `publish\win-x64\tools\platform-tools\` (see
[`docs/DEPENDENCIES.md`](docs/DEPENDENCIES.md)) before running `AetherixRomMaster.exe`.

## Safety model, in one paragraph

Every destructive action goes through `IFlashExecutor`
(`src/AetherixRomMaster.Core/Installation/IFlashExecutor.cs`). `DryRunFlashExecutor`
takes no device-access dependency at all — it is structurally incapable of touching a
real phone (confirmed with real, apt-installed adb/fastboot binaries against an
unconnected device during development, not just fakes). `RealFlashExecutor` is the only
implementation that calls real `adb`/`fastboot`, and the state machine only ever reaches
it after device compatibility, ROM-device match, checksum verification, ROM-profile
verification status, and the full Pre-Flash Safety Check have all passed, plus explicit
user confirmation of the data wipe. A command failure is never retried automatically —
the only retry path is the user explicitly choosing a Recovery Mode option that
`RecoveryPlanner` classified as safe. Root status is always derived from a fresh
`su -c id` query containing `uid=0`, never assumed from a successful flash — see
`RunVerifyKernelSuRootAsync` and `RunCompleteAsync` in `InstallStateMachine.cs`.

## License / provenance

Built against publicly documented Android tooling (`adb`/`fastboot` subcommands,
`META-INF/com/android/metadata`, `android-info.txt`) and the sources cited in
`profiles/*.json`. No code or data in this repository was copied from the ROM's own
source; the ROM itself must be obtained by the user from its official distribution
channel. The app icon in `assets/icon/` was supplied by the project's requester.
