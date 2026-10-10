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
| User-confirmed-unlocked bootloader override (for unreliable Fastboot USB enumeration) | ✅ Implemented — never overrides a positively-detected Locked state |
| Advanced Maintenance (Format Data, reboot targets, ADB sideload -- device-aware) | ✅ Implemented — see below for what was deliberately left out and why |
| Evidence-based Pre-Flash Safety Check (PASS / BLOCKED / UNABLE TO VERIFY per item) | ✅ Implemented — see "Bootloader detection fix" below |
| MD5 checksum support + GApps/Vanilla variant detection (by checksum, never filename) | ✅ Implemented |
| Dark glassmorphism WPF UI | ✅ Implemented |
| Automated tests (197 tests, see `src/AetherixRomMaster.Core.Tests`) | ✅ Implemented, all passing |
| **WPF app actually compiles & runs on real Windows** | ✅ **Verified on GitHub Actions `windows-latest` — see below, not just claimed** |
| **cancunf + Infinity-X ROM profile: `VerificationStatus = PendingManualConfirmation`** | ⚠️ **Device owner confirmed build/checksums from the official page; this app's own tooling still couldn't independently fetch it — see below** |
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

Latest run observed directly during development (commit `c887fb7` — fixes the
bootloader Locked-on-failure bug and relaxes the ROM-profile gate with real evidence,
including the Pre-Flash Safety Check logic that the WPF layer binds directly to;
[run #13](https://github.com/tootopal26-ops/claude1/actions/runs/38065526232)):

```
dotnet build ... -c Release
  AetherixRomMaster.Core -> ...\AetherixRomMaster.Core.dll
  AetherixRomMaster.App -> ...\net8.0-windows\win-x64\AetherixRomMaster.dll
Build succeeded.

dotnet test ...
Test Run Successful.
Total tests: 197
     Passed: 197
 Total time: 5.5057 Seconds

dotnet publish ... -r win-x64 --self-contained true -p:PublishSingleFile=true ...
  AetherixRomMaster.App -> ...\publish\win-x64\
  (AetherixRomMaster.exe, 162,492,615 bytes; profiles\cancunf.infinity-x.rom.json now
   7,650 bytes, confirming the new knownOfficialChecksums/citations content shipped)

Smoke test: Process is still running after 6 seconds (PID 2368) -- startup looks healthy.
```

Earlier verified runs in this same history: [run #6](https://github.com/tootopal26-ops/claude1/actions/runs/38054491595)
(the profile-loading/bootloader/root-detection fixes — 99/99 tests),
[run #7](https://github.com/tootopal26-ops/claude1/actions/runs/38054626710) (the
data-wipe-consequences message), [run #8](https://github.com/tootopal26-ops/claude1/actions/runs/38057099400)
(KernelSU Root Restoration + Recovery/Installation Automation — 145/145 tests),
[run #10](https://github.com/tootopal26-ops/claude1/actions/runs/38061307669) (the
user-confirmed-unlocked bootloader override — 151/151 tests), and
[run #11](https://github.com/tootopal26-ops/claude1/actions/runs/38062052011) (the
device-aware Advanced Maintenance operations — 174/174 tests) were all independently
green too.

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

## 🔓 User-Confirmed-Unlocked Bootloader Override

On some PC/driver combinations (confirmed on a real Moto G64 5G), Windows loses the USB
connection entirely when the phone enters `AP Fastboot Flash Mode`, so the Fastboot
round-trip this app uses for authoritative bootloader verification can never complete
even though the phone's own screen plainly shows `Flashing_unlocked`. Fastboot
verification was always a defense-in-depth UX check, not the only thing standing between
this app and a locked bootloader — fastboot itself still refuses to flash a genuinely
locked device regardless of what this app believes.

"Confirm Unlocked Manually" (next to the Fastboot verification button) lets a human
attest to this instead. It is tracked per device serial (a different/reconnected device
never inherits a stale attestation), and it **never** overrides a positively-detected
Locked state — it only stands in for a check that couldn't complete. The bootloader
status display always distinguishes `Unlocked` (app-verified) from `Unlocked — User
confirmed` (human-attested), and every run that proceeds on the attestation alone logs
that fact explicitly rather than claiming the app verified it.

## 🧰 Advanced Maintenance

A standalone panel for device-aware operations outside the guided ROM install, decided
by `AdvancedMaintenancePlanner` (`src/AetherixRomMaster.Core/Installation/`) from real,
verified facts about cancunf — not copied from another device's ROM flasher.

**Added, and why each is safe for any Android device's fastboot/adb (not cancunf-specific
guesses):**
- **Format / Erase User Data** (`fastboot -w`) — a standard, universal fastboot
  subcommand. Destructive; always shows device/codename/operation and requires explicit
  confirmation. Its explanation text says so when the selected ROM's Verified profile
  requires it as part of its own procedure.
- **Reboot to Recovery / Bootloader / Fastbootd / System** — standard reboot targets,
  picking the adb or fastboot client based on the device's current connection mode.
  Non-destructive.
- **ADB Sideload** — runs `adb sideload <file>` once the device is already in sideload
  mode (refuses otherwise, with instructions); shows the exact file before confirming.

**Deliberately NOT added:**
- **Erase Metadata** — shown in the UI, disabled, with its reason: the only documented
  case of a fastboot-level metadata erase tied to a userdata wipe is a Google
  Pixel-specific fastbootd behavior. cancunf is a MediaTek Dimensity device, not a Pixel,
  and no verified source confirms an equivalent requirement.
- **FRP bypass/erase, or any "Erase FRP" button** — never implemented, never will be.
  The panel shows a read-only explanation (no live status check, since no verified,
  reliable way to query FRP state on this device was found) and points at the legitimate
  recovery path (signing in with the device's previous Google account).
- **Bootloader unlock/relock** — no code path for this exists anywhere in the app; a
  structural reflection test (`BootloaderCommandSafetyTests`) guards that it stays that way.

Every operation supports Dry Run (a toggle, defaulting on) which issues zero real
process calls — proven by test, not just claimed.

## ✅ Pre-Flash Safety Check

Before **Install Custom ROM** is enabled, `PreFlightSafetyChecker`
(`src/AetherixRomMaster.Core/Installation/PreFlightSafetyChecker.cs`) runs seven checks,
all shown in the UI, re-querying the device fresh rather than trusting a stale dry run.
Every item reports one of three evidence-based statuses (`PreFlightCheckItem.Status`):
**Pass**, **Blocked** (a genuine, confirmed problem), or **UnableToVerify** (this app
simply couldn't obtain evidence either way — never presented as if it were a confirmed
problem):

1. Device is exactly Moto G64 5G / `cancunf` (codename match, never model name)
2. Bootloader state is Unlocked — **Pass** if ADB or an authoritative Fastboot query
   confirms Unlocked, **or** if detection is Unknown and the user explicitly used
   "Confirm Unlocked Manually"; **Blocked** only on a positively-detected Locked state
   (never overridable by a manual confirmation); **UnableToVerify** if detection is
   Unknown and nothing has confirmed it either way yet
3. Required package(s) are selected and present on disk
4. SHA-256/MD5 checksums verified against an official source (whichever algorithm that
   source actually publishes — never a pretended SHA-256 match for a file whose official
   source only ever published MD5)
5. ROM compatible with this device and profile (no blocking validation issues)
6. KernelSU compatibility confirmed — shown but **does not block** installing the ROM
   itself (an unconfirmed KernelSU path affects root only, not device safety; this is
   the one deliberate non-blocking item, and the UI labels it as such)
7. A verified recovery path would exist if an early/mid-plan step failed (device
   reachable + files already checksum-verified) — explicitly **not** a promise that
   every possible failure, including a hard-brick mid-flash, is recoverable

Nothing here (or anywhere else in the app) ever reads, reports, or gates on Play
Integrity, Magisk, or FRP status — see `InstallPrerequisiteSafetyTests.cs` for the
structural regression guard.

Tests: `AetherixRomMaster.Core.Tests/Installation/PreFlightSafetyCheckerTests.cs`,
`InstallPrerequisiteSafetyTests.cs`.

### Bootloader-detection bug fix (2026-10-10)

A real hardware report: a Moto G64 5G whose own Fastboot screen plainly showed
`Flashing_unlocked` was reported **Locked** by this app. Root cause, found by reading
`DeviceDetectionService.DeriveBootloaderLockStateFromFastboot`: `fastboot getvar
unlocked` returning anything other than the exact literal `"yes"` (e.g. `"true"` instead
of `"yes"`, or a partial/garbled read) fell through to an unconditional `else = Locked`
— an *unrecognized* value was being treated as *proof* of Locked. Fixed to recognize
`"yes"/"true"/"1"` and `"no"/"false"/"0"` symmetrically and fall through to the next
signal (then to `Unknown`, i.e. "unable to verify") for anything else — mirroring the
fail-safe design `DeriveBootloaderLockStateFromProps` already had from an earlier fix.
A second, separate bug was fixed alongside it: the Pre-Flash Safety Check's own
bootloader item never consulted `InstallContext.BootloaderUserConfirmedUnlocked` at all
(only `InstallStateMachine` did), so a manually-confirmed-unlocked device stayed blocked
at the Install button regardless. Both are now covered by regression tests in
`DeviceDetectionServiceTests.cs` and `PreFlightSafetyCheckerTests.cs`.

## ⚠️ Before you flash anything real: the one gap you must close

This build environment's network egress policy blocks direct access to the ROM's
official pages (`cyberknight777.dev`, `projectinfinity-x.com`, `droidwin.com` all
denied — HTTP 403 or DNS resolution failure depending on when it was tried — an
organization policy block, not a transient failure or evidence the pages are down).

**Update (2026-10-10):** the device owner opened `https://projectinfinity-x.com/downloads/cancunf`
directly and reported back: Motorola G54/G64, codename `cancunf`, **Infinity-X 3.12**
dated **2026-08-13**; published **MD5** checksums for the **GApps** and **Vanilla**
variants; and an install procedure (FastbootD → matching Initial Install ZIP → Recovery
data format → ADB sideload of the matching ROM ZIP) that independently matches the
pattern already recorded from the `cyberknight777.dev`/`droidwin.com` citations. This
application's own tooling re-attempted the same URL the same day and still got a DNS
failure, and a web-search sweep found no independent third-party mirror of the specific
checksums. So `profiles/cancunf.infinity-x.rom.json`'s `verificationStatus` is now
**`"PendingManualConfirmation"`** — not `"Unverified"`, because real, specific facts
are now recorded; **not `"Verified"` either**, because this app never independently
fetched them itself. See the profile's own `citations` for exactly what was recorded,
from where, and by whom.

This does not weaken anything: `RomValidationService` treats `PendingManualConfirmation`
as non-blocking *only* at the profile level — the real gate is still the live,
evidence-based checksum/device/package checks, which compute the actual local file's own
MD5/SHA-256 and require a genuine match. **Which of the two variants (GApps/Vanilla) a
given local ROM ZIP is gets decided by which official MD5 its own computed hash actually
matches — never by filename.** A file matching neither is rejected
(`CHECKSUM_MISMATCH`), not silently accepted.

**Still genuinely missing, and still correctly blocking:** no checksum (official or
sibling `.sha256` file) has been confirmed for the separate **Initial Install** package
specifically (`knownOfficialInitialInstallChecksums` is empty on purpose) — that
package's own checksum check stays `CHECKSUM_UNAVAILABLE` until one is supplied. **If you
have ROM/initial-install files already downloaded, personally verify they came from the
real official page before trusting them** — do not assume a local file is genuine
Infinity-X/cancunf without checking its exact name/size/checksum against that source
yourself; re-confirm the two MD5 values above against the live page too, since they are
a human transcription this app could not independently re-fetch.

**To fully close the gap:** supply a checksum for the Initial Install package (a sibling
`.sha256` file next to it, or add an entry to `knownOfficialInitialInstallChecksums` in
the profile with the same honest provenance style already used for the ROM ZIP's two
entries), and optionally re-verify the recorded facts against the live page yourself
before flipping `verificationStatus` all the way to `"Verified"`.

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
      AdvancedMaintenancePlanner/Executor.cs  cancunf-specific standalone maintenance ops
  AetherixRomMaster.Core.Tests/    174 xUnit tests (see docs/TESTING.md)
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
