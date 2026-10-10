using System.Collections.ObjectModel;
using System.IO;
using System.Windows;
using System.Windows.Input;
using System.Windows.Threading;
using AetherixRomMaster.App.MvvmBase;
using AetherixRomMaster.Core.Devices;
using AetherixRomMaster.Core.Diagnostics;
using AetherixRomMaster.Core.Download;
using AetherixRomMaster.Core.Installation;
using AetherixRomMaster.Core.KernelSu.Restoration;
using AetherixRomMaster.Core.Logging;
using AetherixRomMaster.Core.Profiles;
using AetherixRomMaster.Core.Recovery;
using AetherixRomMaster.Core.Shell;

namespace AetherixRomMaster.App.ViewModels;

public sealed class MainViewModel : ObservableObject, IDisposable
{
    private const string Codename = "cancunf";

    private readonly DispatcherTimer _devicePollTimer;
    private readonly FileDownloadService _downloadService = new();
    private InstallEngine? _engine;
    private InstallLogger? _activeLogger;
    private CancellationTokenSource? _runCts;
    private TaskCompletionSource<bool>? _confirmationTcs;
    private InstallContext? _activeContext;
    private InstallStateMachine? _activeStateMachine;
    private KernelSuProfile? _kernelSuProfile;
    private KernelSuRestorationSession? _restorationSession;
    private InstallLogger? _restorationLogger;

    public MainViewModel()
    {
        LogEntries = new ObservableCollection<InstallLogEntry>();

        RefreshDeviceCommand = new AsyncRelayCommand(RefreshDeviceAsync);
        BrowseRomCommand = new RelayCommand(_ => RequestBrowseRom?.Invoke(this, EventArgs.Empty));
        BrowseInitialInstallCommand = new RelayCommand(_ => RequestBrowseInitialInstall?.Invoke(this, EventArgs.Empty));
        DownloadRomCommand = new AsyncRelayCommand(() => DownloadAsync(isInitialInstall: false), () => !IsBusy && !string.IsNullOrWhiteSpace(RomDownloadUrl));
        DownloadInitialInstallCommand = new AsyncRelayCommand(() => DownloadAsync(isInitialInstall: true), () => !IsBusy && !string.IsNullOrWhiteSpace(InitialInstallDownloadUrl));
        DryRunCommand = new AsyncRelayCommand(() => RunAsync(InstallMode.DryRun), CanStartRun);
        InstallCommand = new AsyncRelayCommand(() => RunAsync(InstallMode.Real), () => CanStartRun() && (PreFlightReport?.CanProceed ?? false));
        VerifyBootloaderCommand = new AsyncRelayCommand(VerifyBootloaderAsync, CanVerifyBootloader);
        CancelCommand = new RelayCommand(_ => _runCts?.Cancel(), _ => IsBusy);
        RestoreKernelSuRootCommand = new AsyncRelayCommand(() => StartKernelSuRestorationAsync(isDryRun: false), CanStartKernelSuRestoration);
        DryRunKernelSuRestorationCommand = new AsyncRelayCommand(() => StartKernelSuRestorationAsync(isDryRun: true), CanStartKernelSuRestoration);
        BrowseOriginalKernelSuImageCommand = new RelayCommand(_ => RequestBrowseOriginalKernelSuImage?.Invoke(this, EventArgs.Empty), _ => RestorationState == KernelSuRestorationState.RestorationRequired);
        BrowsePatchedKernelSuImageCommand = new RelayCommand(_ => RequestBrowsePatchedKernelSuImage?.Invoke(this, EventArgs.Empty), _ => RestorationState == KernelSuRestorationState.WaitingForKernelSuManager);
        ConfirmKernelSuInstallCommand = new AsyncRelayCommand(ConfirmKernelSuInstallAsync, () => !IsBusy && RestorationState == KernelSuRestorationState.ReadyToRestore);
        CancelKernelSuRestorationCommand = new RelayCommand(_ => ResetKernelSuRestoration(), _ => _restorationSession is not null && !IsBusy);
        ConfirmCommand = new RelayCommand(_ => CompleteConfirmation(true), _ => IsConfirmationPending);
        DeclineCommand = new RelayCommand(_ => CompleteConfirmation(false), _ => IsConfirmationPending);
        RetryRecoveryCommand = new AsyncRelayCommand(RetryFailedStepAsync, CanRetryRecovery);

        _devicePollTimer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(2.5) };
        _devicePollTimer.Tick += async (_, _) => { if (!IsBusy) await RefreshDeviceAsync(); };
    }

    // ----- Startup / platform tools -----

    public bool PlatformToolsReady { get; private set; }
    public string PlatformToolsStatus { get; private set; } = "Not checked yet.";

    public async Task InitializeAsync(string appBaseDirectory, string profilesDirectory)
    {
        var resolution = new PlatformToolsLocator(appBaseDirectory).Resolve();
        if (!resolution.IsComplete)
        {
            PlatformToolsReady = false;
            PlatformToolsStatus = "Android Platform Tools (adb/fastboot) were not found bundled under tools\\platform-tools, " +
                                   "and not found on PATH. See DEPENDENCIES.md to install them before continuing.";
            OnPropertyChanged(nameof(PlatformToolsReady));
            OnPropertyChanged(nameof(PlatformToolsStatus));
            return;
        }

        // A published single-file exe can leave more than one plausible "app directory"
        // candidate in play (AppContext.BaseDirectory vs. the process executable's own
        // directory vs. the current working directory) -- try each rather than trusting
        // the one the caller happened to pass in, which is the scenario a real user hit
        // (profiles/ not found) when they didn't copy the folder next to a relocated .exe.
        var resolvedProfilesDirectory = ResolveProfilesDirectory(appBaseDirectory, profilesDirectory);

        _engine = new InstallEngine(resolution.AdbPath!, resolution.FastbootPath!, resolvedProfilesDirectory);
        PlatformToolsReady = true;
        PlatformToolsStatus = $"Platform tools ready ({resolution.Source}).";
        OnPropertyChanged(nameof(PlatformToolsReady));
        OnPropertyChanged(nameof(PlatformToolsStatus));

        DeviceProfile = await _engine.Profiles.LoadDeviceProfileAsync(Codename);
        _deviceProfileDiagnostics = DeviceProfile is null ? _engine.Profiles.DescribeDeviceProfileLookup(Codename) : null;

        var romProfiles = await _engine.Profiles.LoadRomProfilesAsync(Codename);
        RomProfile = romProfiles.FirstOrDefault();
        _romProfileLoadIssue = RomProfile is null ? BuildRomProfileLoadIssueText(resolvedProfilesDirectory) : null;

        var kernelSuProfiles = await _engine.Profiles.LoadKernelSuProfilesAsync(Codename);
        _kernelSuProfile = RomProfile is null
            ? null
            : kernelSuProfiles.FirstOrDefault(p => string.Equals(p.TargetRomName, RomProfile.RomName, StringComparison.OrdinalIgnoreCase));

        OnPropertyChanged(nameof(DeviceProfile));
        OnPropertyChanged(nameof(RomProfile));
        OnPropertyChanged(nameof(RomProfileSummary));
        OnPropertyChanged(nameof(RomVerificationStatusText));
        OnPropertyChanged(nameof(RomRequiresInitialInstallPackage));
        OnPropertyChanged(nameof(DeviceCompatibilityMessage));
        OnPropertyChanged(nameof(AutomationCapabilityReport));

        _devicePollTimer.Start();
        await RefreshDeviceAsync();
    }

    private static string ResolveProfilesDirectory(string appBaseDirectory, string fallbackProfilesDirectory)
    {
        string[] appDirCandidates =
        [
            appBaseDirectory,
            Environment.ProcessPath is { } processPath ? Path.GetDirectoryName(processPath) ?? appBaseDirectory : appBaseDirectory,
            Directory.GetCurrentDirectory(),
        ];

        var found = AppRelativeDirectoryResolver.FindFirstContaining(
            appDirCandidates.Select(dir => Path.Combine(dir, "profiles")),
            $"{Codename}.device.json");

        return found ?? fallbackProfilesDirectory;
    }

    private string BuildRomProfileLoadIssueText(string profilesDirectory)
    {
        var expected = $"{Codename}.<rom-name>.rom.json";
        return _deviceProfileDiagnostics is { } d
            ? $"No ROM profile found for '{Codename}' (expected a file named like '{expected}'). {d.ToDisplayText()}"
            : $"No file matching '{expected}' was found in {profilesDirectory}.";
    }

    public DeviceProfile? DeviceProfile { get; private set; }
    public RomProfile? RomProfile { get; private set; }
    private ProfileLookupDiagnostics? _deviceProfileDiagnostics;
    private string? _romProfileLoadIssue;

    public string RomProfileSummary => RomProfile is null
        ? _romProfileLoadIssue ?? "No ROM profile loaded."
        : $"{RomProfile.RomName} — targets {RomProfile.TargetCodename}, firmware Android {RomProfile.RequiredFirmwareAndroidVersion ?? "unspecified"}.";

    public string RomVerificationStatusText => RomProfile is null
        ? "—"
        : RomProfile.VerificationStatus == VerificationStatus.Verified
            ? "Verified against official source"
            : $"{RomProfile.VerificationStatus} — {RomProfile.VerificationGapNotes ?? "not yet confirmed against the official source"}";

    public bool RomRequiresInitialInstallPackage => RomProfile?.RequiresInitialInstallPackage ?? false;

    // ----- Device status -----

    private static readonly string[] DeviceDerivedPropertyNames =
    [
        nameof(IsDeviceSupported), nameof(DeviceCompatibilityMessage), nameof(IsDeviceConnected),
        nameof(DeviceManufacturerModel), nameof(DeviceSummaryLine), nameof(DeviceFingerprintDisplay),
        nameof(DeviceBootloaderDisplay), nameof(DeviceRootDisplay), nameof(DeviceStorageDisplay),
        nameof(DeviceBatteryDisplay), nameof(DeviceConnectivityDisplay), nameof(DeviceAdbConnected),
        nameof(DeviceFastbootConnected), nameof(DeviceFastbootdConnected), nameof(AutomationCapabilityReport),
    ];

    private DeviceInfo _device = DeviceInfo.Disconnected;
    public DeviceInfo Device
    {
        get => _device;
        private set
        {
            if (SetProperty(ref _device, value))
            {
                foreach (var name in DeviceDerivedPropertyNames) OnPropertyChanged(name);
            }
        }
    }

    public bool IsDeviceSupported => DeviceProfile is not null && new DeviceCompatibilityChecker().Check(Device, DeviceProfile).IsSupported;

    public string DeviceCompatibilityMessage => DeviceProfile is null
        ? _deviceProfileDiagnostics?.ToDisplayText() ?? "Device profile not loaded."
        : new DeviceCompatibilityChecker().Check(Device, DeviceProfile).Reason;

    public bool IsDeviceConnected => Device.ConnectionMode != ConnectionMode.Disconnected;

    public string DeviceManufacturerModel => IsDeviceConnected
        ? $"{Device.Manufacturer ?? "Unknown"} {Device.Model ?? ""}".Trim()
        : "No device connected";

    public string DeviceSummaryLine => IsDeviceConnected
        ? $"Codename: {Device.Codename ?? "unknown"}  ·  Android {Device.AndroidVersion ?? "?"}  ·  Kernel {Device.KernelVersion ?? "?"}"
        : "Connect the phone via USB and enable USB debugging if Android is booted.";

    public string DeviceFingerprintDisplay => Device.BuildFingerprint ?? "—";

    public string DeviceBootloaderDisplay => Device.BootloaderLockState.ToString();

    // Never collapse to a bare "Not rooted": KernelSU grants root per calling identity, so
    // Aetherix/adb lacking a grant is a distinct, expected state from the device having no
    // root solution at all -- each RootSolutionType gets its own honest label instead.
    public string DeviceRootDisplay => Device.RootSolution switch
    {
        RootSolutionType.KernelSU => $"KernelSU (active) — {Device.RootSolutionDetail}",
        RootSolutionType.KernelSuDetectedNotGranted => $"KernelSU running, not granted to Aetherix — {Device.RootSolutionDetail}",
        RootSolutionType.KernelSuManagerInstalledOnly => $"KernelSU Manager installed, kernel integration unconfirmed — {Device.RootSolutionDetail}",
        RootSolutionType.Magisk => $"Magisk detected (unsupported) — {Device.RootSolutionDetail}",
        RootSolutionType.OtherOrUnknown => $"Unknown root — {Device.RootSolutionDetail}",
        RootSolutionType.Inconclusive => $"Root status inconclusive — {Device.RootSolutionDetail}",
        RootSolutionType.None => Device.RootSolutionDetail ?? "No root solution detected",
        _ => Device.RootSolutionDetail ?? "Unknown",
    };

    public string DeviceStorageDisplay => Device.AvailableStorageBytes is { } bytes
        ? $"{bytes / 1024.0 / 1024.0 / 1024.0:F1} GB free"
        : "—";

    public string DeviceBatteryDisplay => Device.BatteryPercent is { } percent ? $"{percent}%" : "—";

    public string DeviceConnectivityDisplay => Device.ConnectionMode switch
    {
        ConnectionMode.Adb => "ADB",
        ConnectionMode.FastbootBootloader => "Fastboot (bootloader)",
        ConnectionMode.FastbootUserspace => "Fastboot (fastbootd)",
        ConnectionMode.AdbSideload => "ADB sideload",
        ConnectionMode.AdbRecoveryManual => "Recovery (manual — not yet in sideload)",
        _ => "Disconnected",
    };

    public string DeviceAdbConnected => Device.AdbConnected ? "Yes" : "No";
    public string DeviceFastbootConnected => Device.FastbootConnected ? "Yes" : "No";
    public string DeviceFastbootdConnected => Device.FastbootdConnected ? "Yes" : "No";

    /// <summary>What Aetherix can automate right now for this exact device/ROM/KernelSU
    /// profile combination -- read-only, recomputed from current state, never a fixed claim.</summary>
    public IReadOnlyList<AutomationCapabilityItem> AutomationCapabilityReport => RomProfile is null
        ? []
        : new AutomationCapabilityPlanner().BuildReport(Device, RomProfile, _kernelSuProfile, new RootDetectionResult(Device.RootSolution, Device.RootSolutionDetail));

    private async Task RefreshDeviceAsync()
    {
        if (_engine is null) return;
        try
        {
            Device = await _engine.DeviceDetection.DetectAsync();
        }
        catch
        {
            Device = DeviceInfo.Disconnected;
        }
    }

    public ICommand RefreshDeviceCommand { get; }

    // ----- Authoritative Fastboot bootloader verification -----
    // This is a real, user-visible device action (two reboots), so it is only ever
    // triggered by this explicit, user-initiated command -- never from the polling timer.

    private string _bootloaderVerificationStatus = "Not yet verified via fastboot. ADB-reported bootloader state above is a best-effort inference and can be wrong.";
    public string BootloaderVerificationStatus
    {
        get => _bootloaderVerificationStatus;
        private set => SetProperty(ref _bootloaderVerificationStatus, value);
    }

    public ICommand VerifyBootloaderCommand { get; }

    private bool CanVerifyBootloader() => !IsBusy && _engine is not null && Device.ConnectionMode == ConnectionMode.Adb && Device.Serial is not null;

    private async Task VerifyBootloaderAsync()
    {
        if (_engine is null || Device.Serial is null) return;

        IsBusy = true;
        BootloaderVerificationStatus = "Rebooting to bootloader for an authoritative fastboot check (the device will reboot twice: to bootloader, then back to system)…";
        try
        {
            var result = await _engine.DeviceDetection.VerifyBootloaderViaFastbootAsync(Device.Serial, TimeSpan.FromSeconds(30));
            BootloaderVerificationStatus = result.Succeeded
                ? $"Fastboot-verified: {result.LockState} (unlocked={result.RawUnlockedVar ?? "n/a"}, securestate={result.RawSecureStateVar ?? "n/a"})."
                : $"Fastboot verification failed: {result.ErrorMessage}";
        }
        catch (Exception ex)
        {
            BootloaderVerificationStatus = $"Fastboot verification failed: {ex.Message}";
        }
        finally
        {
            IsBusy = false;
        }

        await RefreshDeviceAsync();
    }

    // ----- ROM selection -----

    private string? _romZipPath;
    public string? RomZipPath
    {
        get => _romZipPath;
        set { if (SetProperty(ref _romZipPath, value)) { InvalidatePreFlightReport(); RaiseAllCanExecuteChanged(); } }
    }

    private string? _initialInstallZipPath;
    public string? InitialInstallZipPath
    {
        get => _initialInstallZipPath;
        set { if (SetProperty(ref _initialInstallZipPath, value)) { InvalidatePreFlightReport(); RaiseAllCanExecuteChanged(); } }
    }

    /// <summary>A new file selection invalidates any prior Pre-Flash Safety Check --
    /// Install stays disabled until a fresh dry run re-confirms the new selection.</summary>
    private void InvalidatePreFlightReport() => PreFlightReport = null;

    public string RomDownloadUrl { get => _romDownloadUrl; set => SetProperty(ref _romDownloadUrl, value); }
    private string _romDownloadUrl = string.Empty;

    public string InitialInstallDownloadUrl { get => _initialInstallDownloadUrl; set => SetProperty(ref _initialInstallDownloadUrl, value); }
    private string _initialInstallDownloadUrl = string.Empty;

    public double DownloadProgressPercent { get => _downloadProgressPercent; private set => SetProperty(ref _downloadProgressPercent, value); }
    private double _downloadProgressPercent;

    public string DownloadStatusText { get => _downloadStatusText; private set => SetProperty(ref _downloadStatusText, value); }
    private string _downloadStatusText = string.Empty;

    public event EventHandler? RequestBrowseRom;
    public event EventHandler? RequestBrowseInitialInstall;
    public event EventHandler? RequestBrowseOriginalKernelSuImage;
    public event EventHandler? RequestBrowsePatchedKernelSuImage;

    public ICommand BrowseRomCommand { get; }
    public ICommand BrowseInitialInstallCommand { get; }
    public ICommand DownloadRomCommand { get; }
    public ICommand DownloadInitialInstallCommand { get; }

    private async Task DownloadAsync(bool isInitialInstall)
    {
        var url = isInitialInstall ? InitialInstallDownloadUrl : RomDownloadUrl;
        if (!Uri.TryCreate(url, UriKind.Absolute, out var uri))
        {
            DownloadStatusText = "Enter a valid http(s) URL from the ROM's official download page first.";
            return;
        }

        var downloadsDir = Path.Combine(AppContext.BaseDirectory, "downloads");
        var fileName = Path.GetFileName(uri.LocalPath) is { Length: > 0 } name ? name : (isInitialInstall ? "initial-install.zip" : "rom.zip");
        var destination = Path.Combine(downloadsDir, fileName);

        IsBusy = true;
        DownloadStatusText = $"Downloading {fileName}...";
        try
        {
            var progress = new Progress<DownloadProgress>(p =>
            {
                DownloadProgressPercent = p.PercentComplete ?? 0;
                DownloadStatusText = p.PercentComplete is { } pct ? $"Downloading {fileName}... {pct:F0}%" : $"Downloading {fileName}...";
            });
            await _downloadService.DownloadFileAsync(uri, destination, progress);

            if (isInitialInstall) InitialInstallZipPath = destination; else RomZipPath = destination;
            DownloadStatusText = $"Downloaded {fileName}. Checksum will be verified during VerifyChecksums — supply a .sha256 file alongside it if one wasn't downloaded automatically.";
        }
        catch (Exception ex)
        {
            DownloadStatusText = $"Download failed: {ex.Message}";
        }
        finally
        {
            IsBusy = false;
        }
    }

    // ----- KernelSU status -----

    public string KernelSuStatusText { get => _kernelSuStatusText; private set => SetProperty(ref _kernelSuStatusText, value); }
    private string _kernelSuStatusText = "Not yet assessed — run a dry run to check kernel compatibility.";

    // ----- Run state / progress -----

    public bool IsBusy { get => _isBusy; private set { if (SetProperty(ref _isBusy, value)) RaiseAllCanExecuteChanged(); } }
    private bool _isBusy;

    public InstallMode? ActiveMode { get => _activeMode; private set => SetProperty(ref _activeMode, value); }
    private InstallMode? _activeMode;

    public string CurrentStateText { get => _currentStateText; private set => SetProperty(ref _currentStateText, value); }
    private string _currentStateText = "Idle.";

    public double ProgressPercent { get => _progressPercent; private set => SetProperty(ref _progressPercent, value); }
    private double _progressPercent;

    public ObservableCollection<InstallLogEntry> LogEntries { get; }

    public bool IsConfirmationPending { get => _isConfirmationPending; private set { if (SetProperty(ref _isConfirmationPending, value)) RaiseAllCanExecuteChanged(); } }
    private bool _isConfirmationPending;

    public string ConfirmationMessage { get => _confirmationMessage; private set => SetProperty(ref _confirmationMessage, value); }
    private string _confirmationMessage = string.Empty;

    public bool HasFailure { get => _hasFailure; private set => SetProperty(ref _hasFailure, value); }
    private bool _hasFailure;

    public string FailureMessage { get => _failureMessage; private set => SetProperty(ref _failureMessage, value); }
    private string _failureMessage = string.Empty;

    public RecoveryGuidanceEntry? ActiveRecoveryGuidance { get => _activeRecoveryGuidance; private set => SetProperty(ref _activeRecoveryGuidance, value); }
    private RecoveryGuidanceEntry? _activeRecoveryGuidance;

    public string FinalSummary
    {
        get => _finalSummary;
        private set
        {
            if (SetProperty(ref _finalSummary, value)) OnPropertyChanged(nameof(HasFinalSummary));
        }
    }
    private string _finalSummary = string.Empty;

    public bool HasFinalSummary => !string.IsNullOrEmpty(FinalSummary);

    // ----- Pre-Flash Safety Check -----

    public PreFlightSafetyReport? PreFlightReport
    {
        get => _preFlightReport;
        private set { if (SetProperty(ref _preFlightReport, value)) RaiseAllCanExecuteChanged(); }
    }
    private PreFlightSafetyReport? _preFlightReport;

    // ----- Recovery Mode -----

    public RecoverySession? ActiveRecoverySession { get => _activeRecoverySession; private set { if (SetProperty(ref _activeRecoverySession, value)) RaiseAllCanExecuteChanged(); } }
    private RecoverySession? _activeRecoverySession;

    public ICommand DryRunCommand { get; }
    public ICommand InstallCommand { get; }
    public ICommand CancelCommand { get; }
    public ICommand ConfirmCommand { get; }
    public ICommand DeclineCommand { get; }
    public ICommand RetryRecoveryCommand { get; }

    private bool CanStartRun() =>
        !IsBusy && PlatformToolsReady && DeviceProfile is not null && RomProfile is not null &&
        IsDeviceSupported && !string.IsNullOrWhiteSpace(RomZipPath) &&
        (!RomRequiresInitialInstallPackage || !string.IsNullOrWhiteSpace(InitialInstallZipPath));

    private bool CanRetryRecovery() =>
        !IsBusy && ActiveRecoverySession is { PathAvailability: RecoveryPathAvailability.Available } session &&
        session.Options.Any(o => o.CanBeExecutedByThisApp);

    private async Task RunAsync(InstallMode mode)
    {
        if (_engine is null || DeviceProfile is null || RomProfile is null || RomZipPath is null) return;

        ActiveMode = mode;
        HasFailure = false;
        ActiveRecoveryGuidance = null;
        ActiveRecoverySession = null;
        PreFlightReport = null;
        FinalSummary = string.Empty;
        LogEntries.Clear();

        // A fresh run (not a retry) gets its own logger -- dispose whatever the previous
        // run left behind first, now that nothing can still retry into it.
        DisposeActiveLogger();
        var logDir = Path.Combine(AppContext.BaseDirectory, "logs");
        _activeLogger = new InstallLogger(logDir);
        _activeLogger.EntryLogged += OnLogEntry;

        var context = _engine.CreateContext(mode, DeviceProfile, RomProfile, RomZipPath, _activeLogger, InitialInstallZipPath);
        if (mode == InstallMode.DryRun)
        {
            // A dry run previews the whole plan unattended -- it can never act destructively
            // regardless of these flags, so there is nothing unsafe about not stopping for them here.
            context.BackupAcknowledged = true;
            context.DataWipeConfirmed = true;
        }

        _activeContext = context;
        _activeStateMachine = _engine.CreateStateMachine(mode);

        await DriveAndFinalizeAsync();

        if (mode == InstallMode.DryRun && !HasFailure)
        {
            PreFlightReport = await _engine.PreFlightSafetyChecker.RunAsync(context);
        }
    }

    /// <summary>
    /// Resumes the same, already-built context/state machine from wherever it stopped --
    /// since a Failed outcome never advances CurrentStateIndex, this simply re-runs the
    /// failed state using the exact same already-verified files. Only offered when
    /// RecoveryPlanner classified the failure as Available with an executable option.
    /// </summary>
    private async Task RetryFailedStepAsync()
    {
        if (_activeContext is null || _activeStateMachine is null) return;

        HasFailure = false;
        FailureMessage = string.Empty;
        ActiveRecoveryGuidance = null;
        ActiveRecoverySession = null;

        await DriveAndFinalizeAsync();
    }

    private async Task DriveAndFinalizeAsync()
    {
        if (_activeContext is null || _activeStateMachine is null) return;

        IsBusy = true;
        _runCts = new CancellationTokenSource();
        try
        {
            await DriveToCompletionAsync(_activeStateMachine, _activeContext, _runCts.Token);
        }
        finally
        {
            IsBusy = false;
            RaiseAllCanExecuteChanged();
        }
    }

    private void DisposeActiveLogger()
    {
        if (_activeLogger is null) return;
        _activeLogger.EntryLogged -= OnLogEntry;
        _activeLogger.Dispose();
        _activeLogger = null;
    }

    private async Task DriveToCompletionAsync(InstallStateMachine stateMachine, InstallContext context, CancellationToken ct)
    {
        while (true)
        {
            StepResult? last = null;
            await foreach (var step in stateMachine.RunAsync(context, ct))
            {
                last = step;
                CurrentStateText = $"{step.State}: {step.Message}";
                ProgressPercent = 100.0 * (context.CurrentStateIndex + 1) / context.Plan.States.Count;

                if (step.State == InstallState.VerifyKernelSuCompatibility && context.KernelSuAssessment is { } assessment)
                {
                    KernelSuStatusText = $"{assessment.RecommendedAction}: {assessment.Explanation}";
                }
            }

            if (last is null) return;

            switch (last.Outcome)
            {
                case StepOutcome.NeedsConfirmation:
                    var approved = await RequestConfirmationAsync(last.ConfirmationKey!, last.Message);
                    if (!approved)
                    {
                        CurrentStateText = "Cancelled before data-wipe/backup confirmation.";
                        return;
                    }
                    if (last.ConfirmationKey == "backup-ack") context.BackupAcknowledged = true;
                    if (last.ConfirmationKey == "data-wipe") context.DataWipeConfirmed = true;
                    continue; // re-enter RunAsync at the same state, now confirmed.

                case StepOutcome.Failed:
                    HasFailure = true;
                    FailureMessage = last.Message;
                    ActiveRecoveryGuidance = RecoveryGuidance.Lookup(last.RecoveryGuidanceKey);
                    if (_engine is not null)
                    {
                        ActiveRecoverySession = await _engine.RecoveryPlanner.BuildSessionAsync(context, ct);
                    }
                    return;

                case StepOutcome.Cancelled:
                    CurrentStateText = "Cancelled.";
                    return;

                case StepOutcome.Finished:
                    FinalSummary = last.Message;
                    return;
            }
            return;
        }
    }

    // ----- KernelSU Root restoration -----
    // A ROM install can replace the boot-related image and remove KernelSU root with it.
    // This section drives the separate "Restore KernelSU Root" workflow: it reuses the
    // same RootDetector as everywhere else in the app (never a looser check), and real
    // flashing here is gated by KernelSuRestorationPlanner exactly like the main installer
    // is gated by VerificationStatus -- an unverified device/ROM/image combination blocks
    // before anything is transferred or flashed, never silently guessed.

    public ICommand RestoreKernelSuRootCommand { get; }
    public ICommand DryRunKernelSuRestorationCommand { get; }
    public ICommand BrowseOriginalKernelSuImageCommand { get; }
    public ICommand BrowsePatchedKernelSuImageCommand { get; }
    public ICommand ConfirmKernelSuInstallCommand { get; }
    public ICommand CancelKernelSuRestorationCommand { get; }

    private KernelSuRestorationState? _restorationState;
    public KernelSuRestorationState? RestorationState
    {
        get => _restorationState;
        private set
        {
            if (SetProperty(ref _restorationState, value))
            {
                OnPropertyChanged(nameof(RestorationStateDisplay));
                OnPropertyChanged(nameof(IsRestorationAwaitingOriginalImage));
                OnPropertyChanged(nameof(IsRestorationAwaitingPatchedImage));
                OnPropertyChanged(nameof(IsRestorationReadyToInstall));
                RaiseAllCanExecuteChanged();
            }
        }
    }

    private string? _restorationMessage;
    public string? RestorationMessage { get => _restorationMessage; private set => SetProperty(ref _restorationMessage, value); }

    public bool HasActiveKernelSuRestoration => _restorationSession is not null;

    public string RestorationStateDisplay => RestorationState switch
    {
        null => "Not started.",
        KernelSuRestorationState.CheckingKernelSu => "Checking KernelSU...",
        KernelSuRestorationState.AlreadyIntegrated => "KernelSU already integrated",
        KernelSuRestorationState.RestorationRequired => "KernelSU restoration required",
        KernelSuRestorationState.PreparingImage => "Preparing image...",
        KernelSuRestorationState.TransferringImageToDevice => "Transferring image to phone...",
        KernelSuRestorationState.WaitingForKernelSuManager => "Waiting for KernelSU Manager...",
        KernelSuRestorationState.WaitingForPatchedImage => "Waiting for patched image...",
        KernelSuRestorationState.VerifyingPatchedImage => "Verifying patched image...",
        KernelSuRestorationState.PatchedImageVerified => "Patched image verified",
        KernelSuRestorationState.ReadyToRestore => "Ready to restore KernelSU",
        KernelSuRestorationState.RebootingToRequiredMode => "Rebooting to required mode...",
        KernelSuRestorationState.Installing => "Installing...",
        KernelSuRestorationState.RebootingToSystem => "Rebooting...",
        KernelSuRestorationState.VerifyingKernelSu => "Verifying KernelSU...",
        KernelSuRestorationState.RestoredSuccessfully => "KernelSU restored successfully",
        KernelSuRestorationState.Blocked => "Restoration blocked — compatibility could not be verified",
        _ => RestorationState.ToString() ?? "Unknown",
    };

    public bool IsRestorationAwaitingOriginalImage => RestorationState == KernelSuRestorationState.RestorationRequired;
    public bool IsRestorationAwaitingPatchedImage => RestorationState == KernelSuRestorationState.WaitingForKernelSuManager;
    public bool IsRestorationReadyToInstall => RestorationState == KernelSuRestorationState.ReadyToRestore;

    private bool CanStartKernelSuRestoration() =>
        !IsBusy && _engine is not null && RomProfile is not null && IsDeviceConnected && Device.ConnectionMode == ConnectionMode.Adb;

    private async Task StartKernelSuRestorationAsync(bool isDryRun)
    {
        if (_engine is null || RomProfile is null) return;

        DisposeRestorationLogger();
        _restorationLogger = new InstallLogger(Path.Combine(AppContext.BaseDirectory, "logs"));
        _restorationSession = _engine.CreateKernelSuRestorationSession(_restorationLogger, isDryRun);
        OnPropertyChanged(nameof(HasActiveKernelSuRestoration));

        IsBusy = true;
        try
        {
            var result = await _restorationSession.CheckAsync(Device, RomProfile, _kernelSuProfile);
            ApplyRestorationStepResult(result);
        }
        catch (Exception ex)
        {
            RestorationMessage = $"Error checking KernelSU status: {ex.Message}";
        }
        finally
        {
            IsBusy = false;
        }
    }

    /// <summary>Called by the view after the user picks the original (pre-patch) image
    /// file in response to RequestBrowseOriginalKernelSuImage.</summary>
    public async Task SubmitOriginalKernelSuImageAsync(string path)
    {
        if (_restorationSession is null) return;
        IsBusy = true;
        try
        {
            var result = await _restorationSession.PrepareAndTransferImageAsync(path);
            ApplyRestorationStepResult(result);
        }
        catch (Exception ex)
        {
            RestorationMessage = $"Error preparing/transferring image: {ex.Message}";
        }
        finally
        {
            IsBusy = false;
        }
    }

    /// <summary>Called by the view after the user picks the KernelSU-patched image file
    /// in response to RequestBrowsePatchedKernelSuImage. Verifying it successfully chains
    /// straight into building the mandatory safety summary -- there is nothing else to do
    /// with a verified patched image except show it before asking for install confirmation.</summary>
    public async Task SubmitPatchedKernelSuImageAsync(string path)
    {
        if (_restorationSession is null || RomProfile is null) return;
        IsBusy = true;
        try
        {
            var verifyResult = await _restorationSession.RetrieveAndVerifyPatchedImageAsync(path);
            ApplyRestorationStepResult(verifyResult);
            if (verifyResult.Outcome == StepOutcome.Continue)
            {
                ApplyRestorationStepResult(_restorationSession.BuildSafetySummary(Device, RomProfile));
            }
        }
        catch (Exception ex)
        {
            RestorationMessage = $"Error verifying patched image: {ex.Message}";
        }
        finally
        {
            IsBusy = false;
        }
    }

    private async Task ConfirmKernelSuInstallAsync()
    {
        var serial = Device.Serial;
        if (_restorationSession is null || serial is null) return;
        IsBusy = true;
        try
        {
            var installResult = await _restorationSession.InstallPatchedImageAsync(Device.Codename);
            ApplyRestorationStepResult(installResult);
            if (installResult.Outcome == StepOutcome.Continue)
            {
                ApplyRestorationStepResult(await _restorationSession.VerifyAfterRebootAsync(serial));
            }
        }
        catch (Exception ex)
        {
            RestorationMessage = $"Error installing patched image: {ex.Message}";
        }
        finally
        {
            IsBusy = false;
        }
        await RefreshDeviceAsync();
    }

    private void ResetKernelSuRestoration()
    {
        _restorationSession = null;
        RestorationState = null;
        RestorationMessage = null;
        DisposeRestorationLogger();
        OnPropertyChanged(nameof(HasActiveKernelSuRestoration));
    }

    private void ApplyRestorationStepResult(KernelSuRestorationStepResult result)
    {
        RestorationState = result.State;
        RestorationMessage = result.Message;
    }

    private void DisposeRestorationLogger()
    {
        _restorationLogger?.Dispose();
        _restorationLogger = null;
    }

    private Task<bool> RequestConfirmationAsync(string key, string message)
    {
        _confirmationTcs = new TaskCompletionSource<bool>();
        ConfirmationMessage = message;
        IsConfirmationPending = true;
        return _confirmationTcs.Task;
    }

    private void CompleteConfirmation(bool approved)
    {
        IsConfirmationPending = false;
        _confirmationTcs?.TrySetResult(approved);
    }

    private void OnLogEntry(object? sender, InstallLogEntry entry)
    {
        var dispatcher = Application.Current?.Dispatcher;
        if (dispatcher is null || dispatcher.CheckAccess())
        {
            LogEntries.Add(entry);
        }
        else
        {
            dispatcher.Invoke(() => LogEntries.Add(entry));
        }
    }

    private void RaiseAllCanExecuteChanged() => RelayCommand.RaiseCanExecuteChangedForAll();

    public void Dispose()
    {
        _devicePollTimer.Stop();
        _runCts?.Cancel();
        DisposeActiveLogger();
        DisposeRestorationLogger();
    }
}
