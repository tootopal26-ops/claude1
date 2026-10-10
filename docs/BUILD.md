# Building AETHERIX ROM MASTER on Windows

The solution has three projects:

| Project | Target | Builds on |
|---|---|---|
| `AetherixRomMaster.Core` | `net8.0` | Windows, Linux, macOS |
| `AetherixRomMaster.Core.Tests` | `net8.0` | Windows, Linux, macOS |
| `AetherixRomMaster.App` (WPF) | `net8.0-windows` | **Windows only** |

WPF is a Windows-only UI framework with no Linux/macOS build toolchain, so the app
project can only be built and run on Windows. `Core` and `Core.Tests` are ordinary
.NET and build/test anywhere — that's how this project's test suite was verified during
development on a Linux CI machine, and your own CI can do the same for regression
testing without needing a Windows runner for every change.

## 1. Prerequisites

- Windows 10 (1809+) or Windows 11
- [.NET 8 SDK](https://dotnet.microsoft.com/download/dotnet/8.0) (the Windows installer
  includes the WPF/WinForms "Desktop Development" workload automatically; if you
  installed the SDK via `winget install Microsoft.DotNet.SDK.8`, that's sufficient —
  no separate "workload install" step is needed for WPF on Windows)
- Android Platform Tools (`adb.exe`, `fastboot.exe`) — see
  [`DEPENDENCIES.md`](DEPENDENCIES.md) for how the app locates these
- Motorola USB drivers — also covered in `DEPENDENCIES.md`

Verify the SDK:

```powershell
dotnet --version
# Should print 8.0.x
```

## 2. Clone and restore

```powershell
git clone <this-repo-url>
cd <repo>/src
dotnet restore AetherixRomMaster.sln
```

## 3. Provision Android Platform Tools (required before first run)

The app looks for `adb.exe`/`fastboot.exe` under
`AetherixRomMaster.App\tools\platform-tools\` next to the built executable, falling
back to your system `PATH` if not found there. It never downloads these itself — see
`DEPENDENCIES.md` for the official download and exactly where to unzip it.

## 4. Build

```powershell
# Debug build, all projects
dotnet build AetherixRomMaster.sln

# Release build
dotnet build AetherixRomMaster.sln -c Release
```

## 5. Run

```powershell
dotnet run --project AetherixRomMaster.App -c Release
```

Or open `AetherixRomMaster.sln` in Visual Studio 2022 (17.8+) and press F5 — the
`.App` project is already marked as `OutputType=WinExe` and will be the natural
startup project.

## 6. Run the test suite

```powershell
dotnet test AetherixRomMaster.Core.Tests\AetherixRomMaster.Core.Tests.csproj
```

This runs entirely offline against fakes — no phone or adb/fastboot binary required.
See [`TESTING.md`](TESTING.md) for what each test actually proves.

## 7. Package a Windows executable (publish)

For a self-contained, single-machine-portable build (no .NET runtime required on the
target machine):

```powershell
dotnet publish AetherixRomMaster.App -c Release -r win-x64 --self-contained true `
    -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true `
    -o publish\win-x64
```

The output lands in `src\AetherixRomMaster.App\publish\win-x64\AetherixRomMaster.exe`.
Copy the `tools\platform-tools\` folder (see `DEPENDENCIES.md`) alongside the published
`.exe` before distributing it — the publish step above does not bundle platform-tools
automatically, since those are a separate, independently-licensed download from Google
that this build intentionally does not vendor into source control.

If you'd rather ship a framework-dependent build (smaller, requires the .NET 8 Desktop
Runtime on the target machine):

```powershell
dotnet publish AetherixRomMaster.App -c Release -r win-x64 --self-contained false -o publish\win-x64-fx
```

> **Why this repository does not include a pre-built `.exe`:** this project was
> developed in a Linux sandbox without a Windows machine or the WPF build toolchain
> available (WPF has no Linux/macOS compiler — this is a platform limitation, not a
> missing package). `AetherixRomMaster.Core` and its 61 tests were built and verified
> on Linux as part of development; the WPF project (XAML + code-behind) was written and
> manually cross-checked (binding paths, resource keys, XML well-formedness) but could
> not be compiled in that environment. Run step 4 above on a real Windows machine to
> produce and verify the first actual `.exe`, and please open an issue if anything
> doesn't compile — see `docs/TESTING.md` for how thoroughly the non-UI logic was
> already verified.
