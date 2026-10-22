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
