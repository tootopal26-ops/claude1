<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The four built-in design themes (master prompt, Priority 2) and the "density" knob
 * (radius / glass blur, within safe bounds). Pure data + a CSS builder — no database,
 * no admin logic (that lives in App\Services\AdminAppearance). Every component in
 * resources/css/*.css reads these as the SAME CSS custom properties regardless of which
 * theme is active (`--color-canvas`, `--color-ink`, `--glass-bg`, …), so switching a
 * theme never needs a single component rewritten — only the values change, via the
 * dynamically generated `/theme.css` (see App\Controllers\SiteController::themeCss).
 *
 * ok/bad/warn/info (success, destructive, warning, informational) are deliberately
 * NOT part of a theme: "keep green for positive primary actions, red for errors …
 * across all four themes" (master prompt, master design rules) — they stay constant.
 */
final class Themes
{
    public const DEFAULT = 'rose';
    public const NAMES = ['rose', 'aurora', 'obsidian', 'emerald'];
    public const DENSITIES = ['compact', 'standard', 'premium'];
    public const DEFAULT_DENSITY = 'standard';

    /** @var array<string,array{label:string,blurb:string,scheme:string,accent_from:string,accent_to:string,tokens:array<string,string>}> */
    private const THEMES = [
        'rose' => [
            'label' => 'Rose Glass', 'blurb' => 'White & pale rose, pink/red accents, green buy buttons — the recommended default.',
            'scheme' => 'light', 'accent_from' => '#ff2d87', 'accent_to' => '#ff3b3b',
            'tokens' => [
                'canvas' => '#F8F6FA', 'surface' => '#FFFFFF', 'ink' => '#171725', 'ink-soft' => '#2B2D44',
                'muted' => '#656579', 'faint' => '#8A8EA6', 'line' => '#E8E3ED', 'line-strong' => '#D9D3E0',
                'brand-50' => '#FFF1F7', 'brand-100' => '#FFE1EE', 'brand-200' => '#FFC2DA', 'brand-300' => '#FF95BD',
                'brand-400' => '#F2679F', 'brand-600' => '#D42A7D', 'brand-700' => '#B91F70', 'brand-red' => '#FB4D6B',
            ],
            'ambient' => ['rgb(255 45 135 / 0.13)', 'rgb(255 59 59 / 0.10)', 'rgb(168 85 247 / 0.08)'],
        ],
        'aurora' => [
            'label' => 'Aurora Prism', 'blurb' => 'White backgrounds, restrained purple/blue/cyan highlights, iridescent selected states.',
            'scheme' => 'light', 'accent_from' => '#7C3AED', 'accent_to' => '#5B21B6',
            'tokens' => [
                'canvas' => '#F6F6FD', 'surface' => '#FFFFFF', 'ink' => '#1B1B3A', 'ink-soft' => '#34345C',
                'muted' => '#5B5E85', 'faint' => '#8B8FB8', 'line' => '#E6E6F5', 'line-strong' => '#D3D3EC',
                'brand-50' => '#F5F0FF', 'brand-100' => '#E9DEFF', 'brand-200' => '#D4BFFF', 'brand-300' => '#B794FF',
                'brand-400' => '#9B6CF5', 'brand-600' => '#6D28D9', 'brand-700' => '#5B21B6', 'brand-red' => '#22D3EE',
            ],
            'ambient' => ['rgb(124 58 237 / 0.12)', 'rgb(34 211 238 / 0.09)', 'rgb(99 102 241 / 0.08)'],
        ],
        'obsidian' => [
            'label' => 'Obsidian Neon', 'blurb' => 'Deep charcoal, dark translucent glass, purple/magenta accents, high-contrast text.',
            'scheme' => 'dark', 'accent_from' => '#A855F7', 'accent_to' => '#7E22CE',
            'tokens' => [
                'canvas' => '#15131C', 'surface' => '#1E1B28', 'ink' => '#F5F3FA', 'ink-soft' => '#D8D4E8',
                'muted' => '#ABA6C6', 'faint' => '#7D7797', 'line' => 'rgb(255 255 255 / 0.10)', 'line-strong' => 'rgb(255 255 255 / 0.18)',
                'brand-50' => 'rgb(168 85 247 / 0.12)', 'brand-100' => 'rgb(168 85 247 / 0.2)', 'brand-200' => 'rgb(168 85 247 / 0.32)', 'brand-300' => '#C084FC',
                'brand-400' => '#B165F5', 'brand-600' => '#9333EA', 'brand-700' => '#7E22CE', 'brand-red' => '#E879F9',
                'glass-bg' => 'rgb(30 27 40 / 0.66)', 'glass-bg-strong' => 'rgb(30 27 40 / 0.86)',
                'glass-border' => 'rgb(255 255 255 / 0.10)', 'glass-border-strong' => 'rgb(255 255 255 / 0.16)',
                'glass-inset' => 'rgb(255 255 255 / 0.05)', 'glass-inset-strong' => 'rgb(255 255 255 / 0.07)',
            ],
            'ambient' => ['rgb(168 85 247 / 0.16)', 'rgb(232 121 249 / 0.10)', 'rgb(99 102 241 / 0.10)'],
        ],
        'emerald' => [
            'label' => 'Emerald Luxe', 'blurb' => 'Warm white & soft grey, emerald primary actions, muted gold accents — a financial-dashboard feel.',
            'scheme' => 'light', 'accent_from' => '#0F766E', 'accent_to' => '#115E59',
            'tokens' => [
                'canvas' => '#FAFAF8', 'surface' => '#FFFFFF', 'ink' => '#1C1F1D', 'ink-soft' => '#333B35',
                'muted' => '#5F6660', 'faint' => '#8B9289', 'line' => '#E6E8E3', 'line-strong' => '#D3D6CD',
                'brand-50' => '#ECFDF5', 'brand-100' => '#D1FAE9', 'brand-200' => '#A7F3D4', 'brand-300' => '#6EE7B6',
                'brand-400' => '#34D399', 'brand-600' => '#0D6660', 'brand-700' => '#115E59', 'brand-red' => '#B45309',
            ],
            'ambient' => ['rgb(15 118 110 / 0.10)', 'rgb(180 83 9 / 0.07)', 'rgb(52 211 153 / 0.08)'],
        ],
    ];

    /** radius-lg/xl/2xl and the glass blur amounts, scaled within the spec's stated safe ranges (glass blur 12–18px+). */
    private const DENSITY = [
        'compact'  => ['radius-lg' => '14px', 'radius-xl' => '20px', 'radius-2xl' => '26px', 'blur' => '12px', 'blur-strong' => '18px'],
        'standard' => ['radius-lg' => '18px', 'radius-xl' => '24px', 'radius-2xl' => '32px', 'blur' => '18px', 'blur-strong' => '24px'],
        'premium'  => ['radius-lg' => '22px', 'radius-xl' => '28px', 'radius-2xl' => '36px', 'blur' => '22px', 'blur-strong' => '30px'],
    ];

    public static function isValidName(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }

    public static function isValidDensity(string $density): bool
    {
        return in_array($density, self::DENSITIES, true);
    }

    /**
     * @return array<string,mixed> label/blurb/scheme/default accent + a small preview swatch (canvas/surface/ink —
     *         already public anyway, since /theme.css serves the full palette to every visitor, signed in or not)
     */
    public static function meta(string $name): array
    {
        $t = self::THEMES[$name] ?? self::THEMES[self::DEFAULT];
        return [
            'name' => $name, 'label' => $t['label'], 'blurb' => $t['blurb'], 'scheme' => $t['scheme'],
            'accent_from' => $t['accent_from'], 'accent_to' => $t['accent_to'],
            'swatch' => [
                'canvas' => $t['tokens']['canvas'], 'surface' => $t['tokens']['surface'], 'ink' => $t['tokens']['ink'],
                'muted' => $t['tokens']['muted'], 'line' => $t['tokens']['line'],
                'glass-bg' => $t['tokens']['glass-bg'] ?? null, 'glass-border' => $t['tokens']['glass-border'] ?? null,
            ],
        ];
    }

    /** @return list<array<string,mixed>> every theme's meta, in display order */
    public static function all(): array
    {
        return array_map(self::meta(...), self::NAMES);
    }

    public static function defaultAccent(string $name): array
    {
        $t = self::THEMES[$name] ?? self::THEMES[self::DEFAULT];
        return [$t['accent_from'], $t['accent_to']];
    }

    public static function colorScheme(string $name): string
    {
        return (self::THEMES[$name] ?? self::THEMES[self::DEFAULT])['scheme'];
    }

    /**
     * The full `:root{…}` override block for `/theme.css` — every semantic token the
     * theme defines, the density's radius/blur knobs, and the admin's own accent colours
     * (which may differ from the theme's own default — the existing Website accent picker
     * keeps working exactly as before, now layered on top of whichever theme is active).
     */
    public static function css(string $name, string $density, string $accentFrom, string $accentTo): string
    {
        $t = self::THEMES[self::isValidName($name) ? $name : self::DEFAULT];
        $d = self::DENSITY[self::isValidDensity($density) ? $density : self::DEFAULT_DENSITY];
        $vars = [];
        foreach ($t['tokens'] as $k => $v) {
            // glass-* tokens are their own custom properties (--glass-bg, …), read directly by .glass/.glass-strong;
            // everything else is a semantic colour (--color-canvas, --color-ink, …).
            $vars[] = (str_starts_with($k, 'glass-') ? "--{$k}" : "--color-{$k}") . ":{$v}";
        }
        $vars[] = '--accent-from:' . $accentFrom;
        $vars[] = '--accent-to:' . $accentTo;
        foreach ($t['ambient'] as $i => $v) {
            $vars[] = '--ambient-' . ($i + 1) . ':' . $v;
        }
        foreach (['radius-lg', 'radius-xl', 'radius-2xl'] as $k) {
            $vars[] = "--{$k}:{$d[$k]}";
        }
        $vars[] = '--glass-blur:' . $d['blur'];
        $vars[] = '--glass-blur-strong:' . $d['blur-strong'];
        return ':root{color-scheme:' . $t['scheme'] . ';' . implode(';', $vars) . "}\n";
    }
}
