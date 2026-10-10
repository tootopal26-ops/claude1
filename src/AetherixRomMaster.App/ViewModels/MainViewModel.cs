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
        CancelCommand = new RelayCommand(_ => _runCts?.Cancel(), _ => IsBusy);
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

        _engine = new InstallEngine(resolution.AdbPath!, resolution.FastbootPath!, profilesDirectory);
        PlatformToolsReady = true;
        PlatformToolsStatus = $"Platform tools ready ({resolution.Source}).";
        OnPropertyChanged(nameof(PlatformToolsReady));
        OnPropertyChanged(nameof(PlatformToolsStatus));

        DeviceProfile = await _engine.Profiles.LoadDeviceProfileAsync(Codename);
        var romProfiles = await _engine.Profiles.LoadRomProfilesAsync(Codename);
        RomProfile = romProfiles.FirstOrDefault();

        OnPropertyChanged(nameof(DeviceProfile));
        OnPropertyChanged(nameof(RomProfile));
        OnPropertyChanged(nameof(RomProfileSummary));
        OnPropertyChanged(nameof(RomVerificationStatusText));
        OnPropertyChanged(nameof(RomRequiresInitialInstallPackage));

        _devicePollTimer.Start();
        await RefreshDeviceAsync();
    }

    public DeviceProfile? DeviceProfile { get; private set; }
    public RomProfile? RomProfile { get; private set; }

    public string RomProfileSummary => RomProfile is null
        ? "No ROM profile loaded."
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
        nameof(DeviceFastbootConnected), nameof(DeviceFastbootdConnected),
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
        ? "Device profile not loaded."
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

    public string DeviceRootDisplay => Device.RootSolution switch
    {
        RootSolutionType.KernelSU => $"KernelSU — {Device.RootSolutionDetail}",
        RootSolutionType.Magisk => $"Magisk detected (unsupported) — {Device.RootSolutionDetail}",
        RootSolutionType.OtherOrUnknown => $"Unknown root — {Device.RootSolutionDetail}",
        _ => "Not rooted",
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
        _ => "Disconnected",
    };

    public string DeviceAdbConnected => Device.AdbConnected ? "Yes" : "No";
    public string DeviceFastbootConnected => Device.FastbootConnected ? "Yes" : "No";
    public string DeviceFastbootdConnected => Device.FastbootdConnected ? "Yes" : "No";

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
    }
}
