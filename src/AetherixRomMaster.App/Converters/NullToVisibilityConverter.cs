using System.Globalization;
using System.Windows;
using System.Windows.Data;

namespace AetherixRomMaster.App.Converters;

/// <summary>Null -> Visible, non-null -> Collapsed. Set Invert=true for the opposite.</summary>
public sealed class NullToVisibilityConverter : IValueConverter
{
    public bool Invert { get; set; }

    public object Convert(object? value, Type targetType, object? parameter, CultureInfo culture)
    {
        var isNull = value is null;
        if (Invert) isNull = !isNull;
        return isNull ? Visibility.Visible : Visibility.Collapsed;
    }

    public object ConvertBack(object? value, Type targetType, object? parameter, CultureInfo culture)
        => throw new NotSupportedException();
}
