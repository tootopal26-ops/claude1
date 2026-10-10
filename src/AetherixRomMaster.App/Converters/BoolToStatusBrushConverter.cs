using System.Globalization;
using System.Windows.Data;
using System.Windows.Media;

namespace AetherixRomMaster.App.Converters;

public sealed class BoolToStatusBrushConverter : IValueConverter
{
    private static readonly SolidColorBrush Ok = new(Color.FromRgb(0x1F, 0x8C, 0x49));
    private static readonly SolidColorBrush Bad = new(Color.FromRgb(0x8C, 0x26, 0x26));

    public object Convert(object? value, Type targetType, object? parameter, CultureInfo culture)
        => value is true ? Ok : Bad;

    public object ConvertBack(object? value, Type targetType, object? parameter, CultureInfo culture)
        => throw new NotSupportedException();
}
