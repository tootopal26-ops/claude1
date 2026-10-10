using System.Windows.Input;

namespace AetherixRomMaster.App.MvvmBase;

/// <summary>ICommand for an async handler. Guards against re-entrancy (a double-click
/// can't start a second flash while one is already running) and surfaces exceptions to
/// <see cref="OnError"/> instead of letting them vanish into a fire-and-forget task.</summary>
public sealed class AsyncRelayCommand : ICommand
{
    private readonly Func<object?, Task> _execute;
    private readonly Func<object?, bool>? _canExecute;
    private bool _isExecuting;

    public Action<Exception>? OnError { get; set; }

    public AsyncRelayCommand(Func<Task> execute, Func<bool>? canExecute = null)
        : this(_ => execute(), canExecute is null ? null : _ => canExecute())
    {
    }

    public AsyncRelayCommand(Func<object?, Task> execute, Func<object?, bool>? canExecute = null)
    {
        _execute = execute;
        _canExecute = canExecute;
    }

    public event EventHandler? CanExecuteChanged
    {
        add => CommandManager.RequerySuggested += value;
        remove => CommandManager.RequerySuggested -= value;
    }

    public bool CanExecute(object? parameter) => !_isExecuting && (_canExecute?.Invoke(parameter) ?? true);

    public async void Execute(object? parameter)
    {
        if (!CanExecute(parameter)) return;

        _isExecuting = true;
        RelayCommand.RaiseCanExecuteChangedForAll();
        try
        {
            await _execute(parameter);
        }
        catch (Exception ex)
        {
            OnError?.Invoke(ex);
        }
        finally
        {
            _isExecuting = false;
            RelayCommand.RaiseCanExecuteChangedForAll();
        }
    }
}
