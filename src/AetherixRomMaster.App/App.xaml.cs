using System.IO;
using System.Windows;
using System.Windows.Threading;

namespace AetherixRomMaster.App;

public partial class App : Application
{
    protected override void OnStartup(StartupEventArgs e)
    {
        base.OnStartup(e);
        DispatcherUnhandledException += OnDispatcherUnhandledException;
        AppDomain.CurrentDomain.UnhandledException += OnUnhandledException;
    }

    /// <summary>A crash must never lose the diagnostic log of what the installer was
    /// doing — write the final crash details even when the UI itself cannot recover.</summary>
    private void OnDispatcherUnhandledException(object sender, DispatcherUnhandledExceptionEventArgs e)
    {
        TryWriteCrashLog(e.Exception);
        MessageBox.Show(
            $"AETHERIX ROM MASTER hit an unexpected error and must close.\n\n{e.Exception.Message}\n\nA crash log was written to the logs folder.",
            "Unexpected error", MessageBoxButton.OK, MessageBoxImage.Error);
        e.Handled = true;
        Shutdown(-1);
    }

    private void OnUnhandledException(object sender, UnhandledExceptionEventArgs e)
    {
        if (e.ExceptionObject is Exception ex) TryWriteCrashLog(ex);
    }

    private static void TryWriteCrashLog(Exception ex)
    {
        try
        {
            var logDir = Path.Combine(AppContext.BaseDirectory, "logs");
            Directory.CreateDirectory(logDir);
            var path = Path.Combine(logDir, $"crash-{DateTimeOffset.Now:yyyyMMdd-HHmmss}.log");
            File.WriteAllText(path, ex.ToString());
        }
        catch
        {
            // If we can't even write the crash log, there is nothing further we can do here.
        }
    }
}
