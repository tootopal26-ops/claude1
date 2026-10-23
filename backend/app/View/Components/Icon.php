<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Renders one of the shared 24x24 stroke icons used across the Admin
 * panel. The icon set mirrors mobile/src/theme/icons.tsx by name and
 * shape (SVG on this side, react-native-svg on the mobile side) — keep
 * the two in sync by hand if either list changes.
 *
 * Every icon is drawn with fill="none" and stroke="currentColor", so it
 * inherits whatever text color the surrounding element has (including
 * the active theme's --mm-color-* tokens) rather than being hardcoded.
 */
class Icon extends Component
{
    /**
     * @var array<string, list<array{0: string, 1: array<string, string|float>}>>
     */
    private const array PATHS = [
        'tournaments' => [
            ['path', ['d' => 'M4 3v18']],
            ['path', ['d' => 'M4 4h12l-3 4 3 4H4']],
        ],
        'wallet' => [
            ['rect', ['x' => 2, 'y' => 6, 'width' => 20, 'height' => 12, 'rx' => 2]],
            ['path', ['d' => 'M16 14h2']],
        ],
        'withdrawal' => [
            ['path', ['d' => 'M12 3v12']],
            ['polyline', ['points' => '7 10 12 15 17 10']],
            ['path', ['d' => 'M4 20h16']],
        ],
        'deposit' => [
            ['path', ['d' => 'M12 21V9']],
            ['polyline', ['points' => '7 14 12 9 17 14']],
            ['path', ['d' => 'M4 4h16']],
        ],
        'leaderboard' => [
            ['rect', ['x' => 4, 'y' => 14, 'width' => 4, 'height' => 6]],
            ['rect', ['x' => 10, 'y' => 9, 'width' => 4, 'height' => 11]],
            ['rect', ['x' => 16, 'y' => 4, 'width' => 4, 'height' => 16]],
        ],
        'salary' => [
            ['rect', ['x' => 2, 'y' => 6, 'width' => 20, 'height' => 12, 'rx' => 2]],
            ['circle', ['cx' => 12, 'cy' => 12, 'r' => 3]],
        ],
        'storage' => [
            ['path', ['d' => 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z']],
        ],
        'user' => [
            ['circle', ['cx' => 12, 'cy' => 8, 'r' => 4]],
            ['path', ['d' => 'M4 20c0-4 3.5-7 8-7s8 3 8 7']],
        ],
        'presets' => [
            ['line', ['x1' => 4, 'y1' => 6, 'x2' => 20, 'y2' => 6]],
            ['circle', ['cx' => 9, 'cy' => 6, 'r' => 2]],
            ['line', ['x1' => 4, 'y1' => 12, 'x2' => 20, 'y2' => 12]],
            ['circle', ['cx' => 15, 'cy' => 12, 'r' => 2]],
            ['line', ['x1' => 4, 'y1' => 18, 'x2' => 20, 'y2' => 18]],
            ['circle', ['cx' => 7, 'cy' => 18, 'r' => 2]],
        ],
        'audit' => [
            ['rect', ['x' => 5, 'y' => 4, 'width' => 14, 'height' => 17, 'rx' => 2]],
            ['rect', ['x' => 9, 'y' => 2, 'width' => 6, 'height' => 4, 'rx' => 1]],
            ['line', ['x1' => 8, 'y1' => 11, 'x2' => 16, 'y2' => 11]],
            ['line', ['x1' => 8, 'y1' => 15, 'x2' => 16, 'y2' => 15]],
        ],
        'notifications' => [
            ['path', ['d' => 'M6 10a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6Z']],
            ['path', ['d' => 'M10 20a2 2 0 0 0 4 0']],
        ],
        'logout' => [
            ['path', ['d' => 'M9 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4']],
            ['polyline', ['points' => '15 16 20 12 15 8']],
            ['line', ['x1' => 20, 'y1' => 12, 'x2' => 9, 'y2' => 12]],
        ],
        'check' => [
            ['polyline', ['points' => '5 12 10 17 20 7']],
        ],
        'close' => [
            ['line', ['x1' => 5, 'y1' => 5, 'x2' => 19, 'y2' => 19]],
            ['line', ['x1' => 19, 'y1' => 5, 'x2' => 5, 'y2' => 19]],
        ],
        'chevron-right' => [
            ['polyline', ['points' => '9 5 16 12 9 19']],
        ],
        'plus' => [
            ['line', ['x1' => 12, 'y1' => 5, 'x2' => 12, 'y2' => 19]],
            ['line', ['x1' => 5, 'y1' => 12, 'x2' => 19, 'y2' => 12]],
        ],
        'trash' => [
            ['path', ['d' => 'M4 7h16']],
            ['path', ['d' => 'M6 7V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2']],
            ['path', ['d' => 'M19 7l-1 13a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 7']],
            ['line', ['x1' => 10, 'y1' => 11, 'x2' => 10, 'y2' => 17]],
            ['line', ['x1' => 14, 'y1' => 11, 'x2' => 14, 'y2' => 17]],
        ],
        'upload' => [
            ['polyline', ['points' => '7 9 12 4 17 9']],
            ['line', ['x1' => 12, 'y1' => 4, 'x2' => 12, 'y2' => 16]],
            ['path', ['d' => 'M5 20h14']],
        ],
        'download' => [
            ['polyline', ['points' => '7 11 12 16 17 11']],
            ['line', ['x1' => 12, 'y1' => 4, 'x2' => 12, 'y2' => 16]],
            ['path', ['d' => 'M5 20h14']],
        ],
        'home' => [
            ['path', ['d' => 'M4 11l8-7 8 7']],
            ['path', ['d' => 'M6 10v10h12V10']],
        ],
        // Admin-shell icons (web only; the mobile set does not need them).
        'dashboard' => [
            ['rect', ['x' => 3, 'y' => 3, 'width' => 7, 'height' => 9, 'rx' => 1.5]],
            ['rect', ['x' => 14, 'y' => 3, 'width' => 7, 'height' => 5, 'rx' => 1.5]],
            ['rect', ['x' => 14, 'y' => 12, 'width' => 7, 'height' => 9, 'rx' => 1.5]],
            ['rect', ['x' => 3, 'y' => 16, 'width' => 7, 'height' => 5, 'rx' => 1.5]],
        ],
        'users' => [
            ['circle', ['cx' => 9, 'cy' => 8, 'r' => 3.5]],
            ['path', ['d' => 'M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6']],
            ['path', ['d' => 'M16 4.8a3.5 3.5 0 0 1 0 6.4']],
            ['path', ['d' => 'M18 14.3c2.2.7 3.5 2.6 3.5 5.7']],
        ],
        'calendar' => [
            ['rect', ['x' => 3, 'y' => 5, 'width' => 18, 'height' => 16, 'rx' => 2]],
            ['line', ['x1' => 3, 'y1' => 10, 'x2' => 21, 'y2' => 10]],
            ['line', ['x1' => 8, 'y1' => 3, 'x2' => 8, 'y2' => 7]],
            ['line', ['x1' => 16, 'y1' => 3, 'x2' => 16, 'y2' => 7]],
        ],
        'shield' => [
            ['path', ['d' => 'M12 3l7 3v5c0 5-3.5 8-7 10-3.5-2-7-5-7-10V6l7-3Z']],
            ['polyline', ['points' => '9 12 11 14 15 10']],
        ],
        'alert' => [
            ['path', ['d' => 'M12 4l9.5 16.5h-19L12 4Z']],
            ['line', ['x1' => 12, 'y1' => 10, 'x2' => 12, 'y2' => 14]],
            ['line', ['x1' => 12, 'y1' => 17, 'x2' => 12.01, 'y2' => 17]],
        ],
        'settings' => [
            ['circle', ['cx' => 12, 'cy' => 12, 'r' => 3]],
            ['line', ['x1' => 12, 'y1' => 2.5, 'x2' => 12, 'y2' => 5.5]],
            ['line', ['x1' => 12, 'y1' => 18.5, 'x2' => 12, 'y2' => 21.5]],
            ['line', ['x1' => 2.5, 'y1' => 12, 'x2' => 5.5, 'y2' => 12]],
            ['line', ['x1' => 18.5, 'y1' => 12, 'x2' => 21.5, 'y2' => 12]],
            ['line', ['x1' => 5.3, 'y1' => 5.3, 'x2' => 7.4, 'y2' => 7.4]],
            ['line', ['x1' => 16.6, 'y1' => 16.6, 'x2' => 18.7, 'y2' => 18.7]],
            ['line', ['x1' => 5.3, 'y1' => 18.7, 'x2' => 7.4, 'y2' => 16.6]],
            ['line', ['x1' => 16.6, 'y1' => 7.4, 'x2' => 18.7, 'y2' => 5.3]],
        ],
        'search' => [
            ['circle', ['cx' => 11, 'cy' => 11, 'r' => 7]],
            ['line', ['x1' => 16.5, 'y1' => 16.5, 'x2' => 21, 'y2' => 21]],
        ],
        'menu' => [
            ['line', ['x1' => 4, 'y1' => 7, 'x2' => 20, 'y2' => 7]],
            ['line', ['x1' => 4, 'y1' => 12, 'x2' => 20, 'y2' => 12]],
            ['line', ['x1' => 4, 'y1' => 17, 'x2' => 20, 'y2' => 17]],
        ],
        'chevron-down' => [
            ['polyline', ['points' => '5 9 12 16 19 9']],
        ],
        'chevron-left' => [
            ['polyline', ['points' => '15 5 8 12 15 19']],
        ],
        'chevron-up' => [
            ['polyline', ['points' => '5 15 12 8 19 15']],
        ],
        'arrow-left' => [
            ['line', ['x1' => 19, 'y1' => 12, 'x2' => 5, 'y2' => 12]],
            ['polyline', ['points' => '11 6 5 12 11 18']],
        ],
        'panel-left' => [
            ['rect', ['x' => 3, 'y' => 4, 'width' => 18, 'height' => 16, 'rx' => 2]],
            ['line', ['x1' => 9, 'y1' => 4, 'x2' => 9, 'y2' => 20]],
        ],
        'appearance' => [
            ['circle', ['cx' => 12, 'cy' => 12, 'r' => 9]],
            ['path', ['d' => 'M12 3a9 9 0 0 0 0 18Z']],
        ],
        'eye' => [
            ['path', ['d' => 'M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z']],
            ['circle', ['cx' => 12, 'cy' => 12, 'r' => 3]],
        ],
        'eye-off' => [
            ['path', ['d' => 'M3 3l18 18']],
            ['path', ['d' => 'M10.6 5.1A9.9 9.9 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4']],
            ['path', ['d' => 'M6.5 6.6C3.7 8.4 2 12 2 12s3.6 7 10 7a9.7 9.7 0 0 0 4.2-1']],
            ['path', ['d' => 'M9.9 9.9a3 3 0 0 0 4.2 4.2']],
        ],
        'edit' => [
            ['path', ['d' => 'M4 20h4L19.5 8.5a2.1 2.1 0 0 0-3-3L5 17v3Z']],
            ['line', ['x1' => 14, 'y1' => 7, 'x2' => 17, 'y2' => 10]],
        ],
        'info' => [
            ['circle', ['cx' => 12, 'cy' => 12, 'r' => 9]],
            ['line', ['x1' => 12, 'y1' => 11, 'x2' => 12, 'y2' => 16]],
            ['line', ['x1' => 12, 'y1' => 8, 'x2' => 12.01, 'y2' => 8]],
        ],
        'check-circle' => [
            ['circle', ['cx' => 12, 'cy' => 12, 'r' => 9]],
            ['polyline', ['points' => '8 12 11 15 16 9']],
        ],
        'x-circle' => [
            ['circle', ['cx' => 12, 'cy' => 12, 'r' => 9]],
            ['line', ['x1' => 9, 'y1' => 9, 'x2' => 15, 'y2' => 15]],
            ['line', ['x1' => 15, 'y1' => 9, 'x2' => 9, 'y2' => 15]],
        ],
        'clock' => [
            ['circle', ['cx' => 12, 'cy' => 12, 'r' => 9]],
            ['polyline', ['points' => '12 7 12 12 15.5 14']],
        ],
        'more' => [
            ['circle', ['cx' => 5, 'cy' => 12, 'r' => 1]],
            ['circle', ['cx' => 12, 'cy' => 12, 'r' => 1]],
            ['circle', ['cx' => 19, 'cy' => 12, 'r' => 1]],
        ],
        'filter' => [
            ['path', ['d' => 'M4 5h16l-6 8v6l-4-2v-4L4 5Z']],
        ],
        'image' => [
            ['rect', ['x' => 3, 'y' => 4, 'width' => 18, 'height' => 16, 'rx' => 2]],
            ['circle', ['cx' => 9, 'cy' => 10, 'r' => 1.5]],
            ['path', ['d' => 'M21 16l-5-5L7 20']],
        ],
        'copy' => [
            ['rect', ['x' => 9, 'y' => 9, 'width' => 11, 'height' => 11, 'rx' => 2]],
            ['path', ['d' => 'M5 15V6a2 2 0 0 1 2-2h9']],
        ],
        'ticket' => [
            ['path', ['d' => 'M3 8a1 1 0 0 1 1-1h16a1 1 0 0 1 1 1v2a2 2 0 0 0 0 4v2a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-2a2 2 0 0 0 0-4V8Z']],
            ['path', ['d' => 'M9 9.5v5']],
        ],
        'lock' => [
            ['rect', ['x' => 4, 'y' => 11, 'width' => 16, 'height' => 10, 'rx' => 2.5]],
            ['path', ['d' => 'M8 11V8a4 4 0 0 1 8 0v3']],
            ['path', ['d' => 'M12 15.5v2']],
        ],
        'unlock' => [
            ['rect', ['x' => 4, 'y' => 11, 'width' => 16, 'height' => 10, 'rx' => 2.5]],
            ['path', ['d' => 'M8 11V8a4 4 0 0 1 7.5-2']],
            ['path', ['d' => 'M12 15.5v2']],
        ],
        'external' => [
            ['path', ['d' => 'M14 4h6v6']],
            ['line', ['x1' => 20, 'y1' => 4, 'x2' => 10, 'y2' => 14]],
            ['path', ['d' => 'M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5']],
        ],
    ];

    /**
     * @var list<array{0: string, 1: array<string, string|float>}>
     */
    public array $elements;

    public function __construct(public string $name, public int $size = 24)
    {
        if (! isset(self::PATHS[$name])) {
            throw new InvalidArgumentException("Unknown icon \"{$name}\". Known icons: ".implode(', ', array_keys(self::PATHS)));
        }

        $this->elements = self::PATHS[$name];
    }

    public function render(): View
    {
        return view('components.icon');
    }
}
