# AETHERIX ROM MASTER

A Windows desktop utility that automates installing a verified custom ROM on a
**Motorola Moto G64 5G (codename `cancunf`)** and establishing **KernelSU** root —
and nothing else. Magisk is never installed, recommended, or used anywhere in this
application.

This is a real installer, not a mockup: it drives the official Android Platform Tools
(`adb`/`fastboot`) through a device-specific state machine, validates every file against
checksums before touching the device, and refuses to proceed the moment something can't
be verified.

## Current status

| Area | Status |
|---|---|
| Device detection (ADB/Fastboot/Fastbootd, bootloader lock, root, kernel) | ✅ Implemented |
| ROM package inspection & validation (metadata, device match, checksums) | ✅ Implemented |
| 21-state installation state machine | ✅ Implemented |
| Dry-run mode (full plan preview, zero destructive commands) | ✅ Implemented |
| Real flashing engine (fastboot update / sideload, gated) | ✅ Implemented |
| KernelSU compatibility assessment + honest post-flash root verification | ✅ Implemented |
| Dark glassmorphism WPF UI | ✅ Implemented |
| Automated tests (61 tests, see `src/AetherixRomMaster.Core.Tests`) | ✅ Implemented, all passing |
| **cancunf + Infinity-X ROM profile: `VerificationStatus = Unverified`** | ⚠️ **See below — action required before real flashing** |

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

**`profiles/cancunf.infinity-x.rom.json`** encodes this honestly: `verificationStatus`
is `"Unverified"`, and `RomValidationService` (see
`src/AetherixRomMaster.Core/Rom/RomValidationService.cs`) hard-blocks real flashing
with a `ROM_PROFILE_UNVERIFIED` issue until that field is changed to `"Verified"`. Dry
run works today, right now, with no changes needed.

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
src/
  AetherixRomMaster.Core/          Device detection, ROM validation, state machine,
                                    KernelSU compatibility, logging — cross-platform,
                                    no WPF dependency. Builds and tests on any OS.
  AetherixRomMaster.Core.Tests/    61 xUnit tests (see docs/TESTING.md)
  AetherixRomMaster.App/           WPF UI (net8.0-windows — Windows-only to build/run)
profiles/
  cancunf.device.json              Device profile (researched facts + citations)
  cancunf.infinity-x.rom.json      ROM profile (verification-gated, see above)
assets/icon/                       Source SVG + rendered app icon
docs/
  BUILD.md                         Windows build instructions
  DEPENDENCIES.md                  Platform tools / USB drivers / SDK setup
  INSTALL_LOG_FORMAT.md            Diagnostic log format reference
  TESTING.md                       What each test proves and how to run them
```

## Quick start (Windows)

See [`docs/BUILD.md`](docs/BUILD.md) for full instructions. Short version:

```powershell
dotnet restore src/AetherixRomMaster.sln
dotnet build src/AetherixRomMaster.sln -c Release
dotnet run --project src/AetherixRomMaster.App
```

## Safety model, in one paragraph

Every destructive action goes through `IFlashExecutor`
(`src/AetherixRomMaster.Core/Installation/IFlashExecutor.cs`). `DryRunFlashExecutor`
takes no device-access dependency at all — it is structurally incapable of touching a
real phone. `RealFlashExecutor` is the only implementation that calls real
`adb`/`fastboot`, and the state machine only ever reaches it after device compatibility,
ROM-device match, checksum verification, and ROM-profile verification status have all
passed, plus explicit user confirmation of the data wipe. A command failure is never
retried automatically — see `RunRestoreKernelSuIfCompatibleAsync` and
`RunCompleteAsync` in `InstallStateMachine.cs` for how root status is reported: it is
always derived from a fresh `su -c id` query containing `uid=0`, never assumed from a
successful flash.

## License / provenance

Built against publicly documented Android tooling (`adb`/`fastboot` subcommands,
`META-INF/com/android/metadata`, `android-info.txt`) and the sources cited in
`profiles/*.json`. No code or data in this repository was copied from the ROM's own
source; the ROM itself must be obtained by the user from its official distribution
channel.
