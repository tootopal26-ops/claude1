# Dependency setup

AETHERIX ROM MASTER relies on exactly three kinds of external software, all from
their official sources. Nothing is downloaded or executed by the app itself at
runtime except the two Google-provided binaries below, and only when you explicitly
start device detection or an install/dry-run.

## 1. .NET 8 Desktop Runtime (end users) / SDK (developers)

- End users running a self-contained published build: nothing to install.
- End users running a framework-dependent build: install the
  [.NET 8 Desktop Runtime](https://dotnet.microsoft.com/download/dotnet/8.0) (x64).
- Developers: install the [.NET 8 SDK](https://dotnet.microsoft.com/download/dotnet/8.0).

## 2. Android Platform Tools (`adb.exe`, `fastboot.exe`)

**Official source:** https://developer.android.com/tools/releases/platform-tools
(Google's own SDK Platform Tools page — "Download SDK Platform-Tools for Windows").

1. Download `platform-tools-latest-windows.zip` from that page.
2. Extract it so that `adb.exe` and `fastboot.exe` end up at:
   ```
   <AETHERIX ROM MASTER install/build folder>\tools\platform-tools\adb.exe
   <AETHERIX ROM MASTER install/build folder>\tools\platform-tools\fastboot.exe
   ```
   (i.e. the whole extracted `platform-tools` folder goes inside a `tools` folder next
   to `AetherixRomMaster.exe`.)
3. Alternatively, if `adb`/`fastboot` are already on your system `PATH` (e.g. you
   installed them via Android Studio or `winget install Google.PlatformTools`), the
   app will find and use those automatically — see
   `src/AetherixRomMaster.Core/Shell/PlatformToolsLocator.cs`.

The app will not start device detection and will show a clear setup banner if neither
location has both binaries. It never substitutes a different binary, never downloads
one on your behalf, and never proceeds with only one of the two present.

## 3. Motorola USB drivers (Windows only)

Windows needs Motorola's USB driver to reliably enumerate the phone in **both** normal
ADB mode and fastboot/fastbootd mode (a very common source of "device not detected" in
fastboot specifically, even when ADB worked fine).

**Official source:** https://en-us.support.motorola.com/app/drivers
(Motorola's own support site — search "USB driver" for your region, or use Motorola's
"Device Manager"/"Rescue and Smart Assistant" tool, which installs the correct drivers
as part of its setup).

Install this before connecting the phone, or reconnect the phone after installing it.

## 4. The ROM itself

This app does **not** bundle, mirror, or embed the Infinity-X ROM or any other
third-party ROM. You obtain it yourself from the ROM's official distribution channel —
see the sources listed in the root `README.md`'s verification-gap section and in
`profiles/cancunf.infinity-x.rom.json`. The app's job is to verify, not to source, the
ROM file.

## Summary of what's vendored vs. not

| Component | Vendored in this repo? | Where it comes from |
|---|---|---|
| Application source code | ✅ Yes | This repository |
| Device/ROM profile JSON | ✅ Yes | Hand-authored from cited research |
| App icon | ✅ Yes | Supplied by the user, `assets/icon/` |
| .NET runtime | ❌ No | Microsoft, official installer |
| `adb.exe` / `fastboot.exe` | ❌ No | Google, official Platform Tools download |
| Motorola USB drivers | ❌ No | Motorola, official support site |
| The ROM ZIP | ❌ No | The ROM's own official distribution |
