using System.Globalization;
using System.Windows.Data;
using System.Windows.Media;
using AetherixRomMaster.Core.Logging;

namespace AetherixRomMaster.App.Converters;

public sealed class LogLevelToBrushConverter : IValueConverter
{
    private static readonly SolidColorBrush Error = new(Color.FromRgb(0xFF, 0x5C, 0x5C));
    private static readonly SolidColorBrush Warning = new(Color.FromRgb(0xFF, 0xB8, 0x4D));
    private static readonly SolidColorBrush Info = new(Color.FromRgb(0xC9, 0xD2, 0xD6));
    private static readonly SolidColorBrush Debug = new(Color.FromRgb(0x7A, 0x82, 0x87));

    public object Convert(object? value, Type targetType, object? parameter, CultureInfo culture) => value switch
    {
        LogLevel.Critical or LogLevel.Error => Error,
        LogLevel.Warning => Warning,
        LogLevel.Debug => Debug,
        _ => Info,
    };

    public object ConvertBack(object? value, Type targetType, object? parameter, CultureInfo culture)
        => throw new NotSupportedException();
}
