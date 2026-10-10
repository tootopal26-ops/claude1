using System.Collections.Specialized;
using System.Windows;
using AetherixRomMaster.App.ViewModels;
using Microsoft.Win32;

namespace AetherixRomMaster.App;

public partial class MainWindow : Window
{
    private readonly MainViewModel _viewModel;

    public MainWindow()
    {
        InitializeComponent();

        _viewModel = new MainViewModel();
        _viewModel.RequestBrowseRom += (_, _) => BrowseForZip(path => _viewModel.RomZipPath = path);
        _viewModel.RequestBrowseInitialInstall += (_, _) => BrowseForZip(path => _viewModel.InitialInstallZipPath = path);
        _viewModel.RequestBrowseOriginalKernelSuImage += (_, _) => BrowseForImage(path => _ = _viewModel.SubmitOriginalKernelSuImageAsync(path));
        _viewModel.RequestBrowsePatchedKernelSuImage += (_, _) => BrowseForImage(path => _ = _viewModel.SubmitPatchedKernelSuImageAsync(path));
        _viewModel.RequestBrowseMaintenanceSideloadPackage += (_, _) => BrowseForZip(path => _ = _viewModel.SubmitMaintenanceSideloadPackageAsync(path));
        _viewModel.LogEntries.CollectionChanged += OnLogEntriesChanged;
        DataContext = _viewModel;

        Loaded += OnLoaded;
        Closed += (_, _) => _viewModel.Dispose();
    }

    private async void OnLoaded(object sender, RoutedEventArgs e)
    {
        var profilesDirectory = System.IO.Path.Combine(AppContext.BaseDirectory, "profiles");
        await _viewModel.InitializeAsync(AppContext.BaseDirectory, profilesDirectory);
    }

    private void BrowseForZip(Action<string> onSelected)
    {
        var dialog = new OpenFileDialog
        {
            Filter = "ROM package (*.zip)|*.zip|All files (*.*)|*.*",
            CheckFileExists = true,
            Title = "Select a ROM or initial-install package",
        };
        if (dialog.ShowDialog(this) == true)
        {
            onSelected(dialog.FileName);
        }
    }

    private void BrowseForImage(Action<string> onSelected)
    {
        var dialog = new OpenFileDialog
        {
            Filter = "Boot-related image (*.img)|*.img|All files (*.*)|*.*",
            CheckFileExists = true,
            Title = "Select a boot-related image for KernelSU",
        };
        if (dialog.ShowDialog(this) == true)
        {
            onSelected(dialog.FileName);
        }
    }

    private void OnLogEntriesChanged(object? sender, NotifyCollectionChangedEventArgs e)
    {
        if (e.Action == NotifyCollectionChangedAction.Add)
        {
            LogScrollViewer.ScrollToBottom();
        }
    }
}
