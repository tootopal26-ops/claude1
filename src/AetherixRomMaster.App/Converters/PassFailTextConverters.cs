using System.Globalization;
using System.Windows.Data;
using System.Windows.Media;

namespace AetherixRomMaster.App.Converters;

public sealed class PassFailGlyphConverter : IValueConverter
{
    public object Convert(object? value, Type targetType, object? parameter, CultureInfo culture)
        => value is true ? "✓" : "✕"; // checkmark / cross

    public object ConvertBack(object? value, Type targetType, object? parameter, CultureInfo culture)
        => throw new NotSupportedException();
}

/// <summary>
/// A failed-but-non-blocking Pre-Flight item (currently only KernelSU) must never look
/// like a real, blocking failure -- a red cross next to "does not block Install" text
/// reads as a contradiction. Takes [Passed, IsBlocking]: Passed -> checkmark,
/// !Passed &amp;&amp; IsBlocking -> cross (a genuine problem), !Passed &amp;&amp; !IsBlocking ->
/// a neutral "not yet confirmed" marker instead.
/// </summary>
public sealed class PreFlightItemGlyphConverter : IMultiValueConverter
{
    public object Convert(object[] values, Type targetType, object? parameter, CultureInfo culture)
    {
        if (values is [true, _]) return "✓";
        if (values is [false, true]) return "✕";
        return "–";
    }

    public object[] ConvertBack(object value, Type[] targetTypes, object? parameter, CultureInfo culture)
        => throw new NotSupportedException();
}

public sealed class PreFlightItemBrushConverter : IMultiValueConverter
{
    private static readonly SolidColorBrush Ok = new(Color.FromRgb(0x1F, 0x8C, 0x49));
    private static readonly SolidColorBrush Bad = new(Color.FromRgb(0x8C, 0x26, 0x26));
    private static readonly SolidColorBrush Advisory = new(Color.FromRgb(0xB8, 0x86, 0x0B));

    public object Convert(object[] values, Type targetType, object? parameter, CultureInfo culture)
    {
        if (values is [true, _]) return Ok;
        if (values is [false, true]) return Bad;
        return Advisory;
    }

    public object[] ConvertBack(object value, Type[] targetTypes, object? parameter, CultureInfo culture)
        => throw new NotSupportedException();
}

public sealed class ReadyToFlashTextConverter : IValueConverter
{
    public object Convert(object? value, Type targetType, object? parameter, CultureInfo culture)
        => value is true ? "READY TO FLASH" : "NOT READY";

    public object ConvertBack(object? value, Type targetType, object? parameter, CultureInfo culture)
        => throw new NotSupportedException();
}

public sealed class VerifiedTextConverter : IValueConverter
{
    public object Convert(object? value, Type targetType, object? parameter, CultureInfo culture)
        => value is true ? "Verified" : "NOT VERIFIED";

    public object ConvertBack(object? value, Type targetType, object? parameter, CultureInfo culture)
        => throw new NotSupportedException();
}
