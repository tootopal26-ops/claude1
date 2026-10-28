<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Structural guards for the Admin/Staff design system (docs/design-system.md):
 * every page is theme-driven, every theme defines the full token set, and
 * every theme's text/surface/brand pairings meet WCAG AA.
 */
class AdminUiDesignSystemTest extends TestCase
{
    /**
     * Tokens every color theme must define (themes.css).
     */
    private const array REQUIRED_TOKENS = [
        '--mm-bg', '--mm-sidebar', '--mm-surface', '--mm-surface-2', '--mm-surface-3',
        '--mm-border', '--mm-border-strong',
        '--mm-fg', '--mm-fg-2', '--mm-fg-3',
        '--mm-brand', '--mm-brand-hover', '--mm-brand-pressed', '--mm-brand-soft', '--mm-brand-text',
        '--mm-on-brand', '--mm-ring',
    ];

    /**
     * @return list<string>
     */
    private function themedViewFiles(): array
    {
        $files = [];

        foreach ([
            resource_path('views/livewire/admin'),
            resource_path('views/components/ui'),
            resource_path('views/components/layouts'),
            resource_path('views/errors'),
        ] as $directory) {
            foreach (File::allFiles($directory) as $file) {
                $files[] = $file->getPathname();
            }
        }

        $files[] = resource_path('views/components/error-page.blade.php');

        return $files;
    }

    public function test_views_use_theme_tokens_instead_of_raw_palette_colors(): void
    {
        $palette = '(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)';
        $utilities = '(?:bg|text|border|ring|divide|outline|fill|stroke|accent|from|to|via|placeholder|decoration|shadow|caret)';

        $patterns = [
            "/\\b{$utilities}-{$palette}-\\d{2,3}\\b/" => 'a raw Tailwind palette color',
            "/\\b{$utilities}-(?:white|black)\\b(?!-)/" => 'raw white/black',
            '/#[0-9a-fA-F]{6}\\b/' => 'a hex color',
        ];

        $offenders = [];

        foreach ($this->themedViewFiles() as $path) {
            $contents = File::get($path);

            foreach ($patterns as $pattern => $label) {
                if (preg_match_all($pattern, $contents, $matches)) {
                    $offenders[] = str_replace(base_path().'/', '', $path).': '.$label.' ('.implode(', ', array_unique($matches[0])).')';
                }
            }
        }

        $this->assertSame([], $offenders, "Use design tokens (bg-surface, text-fg-2, border-line, bg-brand ...) so every theme works:\n".implode("\n", $offenders));
    }

    public function test_views_use_the_confirm_dialog_not_native_wire_confirm(): void
    {
        $offenders = [];

        foreach ($this->themedViewFiles() as $path) {
            if (str_contains(File::get($path), 'wire:confirm')) {
                $offenders[] = str_replace(base_path().'/', '', $path);
            }
        }

        $this->assertSame([], $offenders, 'Use <x-ui.button confirm="..."> instead of wire:confirm: '.implode(', ', $offenders));
    }

    public function test_pages_that_flash_a_status_or_are_redirected_to_after_one_render_the_flash(): void
    {
        $needsFlash = [];
        $files = collect(File::allFiles(app_path('Livewire/Admin')));
        $classOf = fn ($file) => 'App\\Livewire\\Admin\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        foreach ($files as $file) {
            $php = File::get($file->getPathname());

            if (! str_contains($php, "->flash('status'")) {
                continue;
            }

            // A shared trait that flashes: every component using it must render the flash.
            if (preg_match('/^trait (\\w+)/m', $php, $trait)) {
                foreach ($files as $user) {
                    if (preg_match('/^    use '.$trait[1].';$/m', File::get($user->getPathname()))) {
                        $needsFlash[$classOf($user)] = "uses {$trait[1]}";
                    }
                }

                continue;
            }

            // A form that flashes and then redirects shows the message on the redirect target;
            // one that stays on the page must render it itself.
            if (preg_match("/redirectRoute\\(Portal::routeName\\('([^']+)'\\)/", $php, $match) && ($route = Route::getRoutes()->getByName('admin.'.$match[1]))) {
                $needsFlash[ltrim($route->getActionName(), '\\')] = "redirect target of {$file->getBasename('.php')}";
            } else {
                $needsFlash[$classOf($file)] = 'itself';
            }
        }

        $missing = [];

        foreach ($needsFlash as $class => $why) {
            // App\Livewire\Admin\Ads\AdUnits -> views/livewire/admin/ads/ad-units.blade.php
            $relative = collect(explode('\\', Str::after($class, 'App\\Livewire\\Admin\\')))->map(fn (string $part) => Str::kebab($part))->implode('/');
            $view = resource_path('views/livewire/admin/'.$relative.'.blade.php');

            if (! File::exists($view) || ! str_contains(File::get($view), '<x-ui.flash')) {
                $missing[] = class_basename($class)." ({$why}) must render <x-ui.flash />";
            }
        }

        $this->assertNotEmpty($needsFlash);
        $this->assertSame([], $missing, implode("\n", $missing));
    }

    public function test_every_configured_theme_defines_the_full_token_set(): void
    {
        $themes = $this->parseThemes();

        foreach (array_keys(config('minimax.theme_colors')) as $key) {
            $this->assertArrayHasKey($key, $themes, "Theme \"{$key}\" has no block in themes.css");

            foreach (self::REQUIRED_TOKENS as $token) {
                $this->assertArrayHasKey($token, $themes[$key], "Theme \"{$key}\" is missing {$token}");
            }
        }
    }

    public function test_every_theme_meets_wcag_aa_contrast_for_its_core_pairings(): void
    {
        $failures = [];

        foreach ($this->resolvedThemes() as $key => $tokens) {
            $pairs = [
                ['--mm-fg', '--mm-bg'], ['--mm-fg', '--mm-surface'],
                ['--mm-fg-2', '--mm-surface'], ['--mm-fg-2', '--mm-surface-2'],
                ['--mm-fg-3', '--mm-surface'], ['--mm-fg-3', '--mm-surface-2'], ['--mm-fg-3', '--mm-surface-3'], ['--mm-fg-3', '--mm-bg'],
                ['--mm-fg-2', '--mm-surface-3'], ['--mm-brand-text', '--mm-surface-3'], ['--mm-fg-3', '--mm-sidebar'],
                ['--mm-brand-text', '--mm-surface'], ['--mm-brand-text', '--mm-surface-2'], ['--mm-brand-text', '--mm-brand-soft'],
                // Selected rows, nav items and option cards keep their hint text readable.
                ['--mm-fg', '--mm-brand-soft'], ['--mm-fg-2', '--mm-brand-soft'], ['--mm-fg-3', '--mm-brand-soft'],
                ['--mm-on-brand', '--mm-brand'], ['--mm-on-brand', '--mm-brand-pressed'],
            ];

            foreach ($pairs as [$foreground, $background]) {
                $ratio = $this->contrast($tokens[$foreground], $tokens[$background]);

                if ($ratio < 4.5) {
                    $failures[] = sprintf('%s: %s on %s is %.2f:1 (needs 4.5:1)', $key, $foreground, $background, $ratio);
                }
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function test_glass_surfaces_keep_aa_contrast_in_the_worst_case_behind_them_for_every_theme(): void
    {
        $failures = [];

        foreach ($this->resolvedThemes() as $key => $t) {
            $percent = fn (string $token): float => (float) rtrim($t[$token], '%') / 100;
            $panel = $percent('--mm-glass-opacity');
            $float = $percent('--mm-glass-float-opacity');
            $glow = $percent('--mm-glow-strength') + $percent('--mm-glow-strength-2');

            // Static panels sit on the page background plus, at worst, both glows stacked.
            $behindPanel = $this->blend($t['--mm-brand'], $t['--mm-bg'], $glow);
            $panelBackground = $this->blend($t['--mm-surface'], $behindPanel, $panel);

            // Layers that overlap content can have anything behind them. The blur averages it out, so the
            // brightest large area the UI ever paints — the solid brand colour of a primary button — is
            // the worst case; glyphs and badges are small and disappear into the average.
            $menuBackground = $this->blend($t['--mm-float'], $t['--mm-brand'], $float);
            $barBackground = $this->blend($t['--mm-header'], $t['--mm-brand'], $float);
            $sidebarBackground = $this->blend($t['--mm-sidebar'], $t['--mm-brand'], $float);

            $checks = [
                'panel' => [$panelBackground, ['--mm-fg', '--mm-fg-2', '--mm-fg-3', '--mm-brand-text']],
                'menu' => [$menuBackground, ['--mm-fg', '--mm-fg-2', '--mm-fg-3', '--mm-brand-text']],
                'header/action bar' => [$barBackground, ['--mm-fg', '--mm-fg-2', '--mm-fg-3', '--mm-brand-text']],
                'sidebar' => [$sidebarBackground, ['--mm-fg', '--mm-fg-2', '--mm-fg-3', '--mm-brand-text']],
            ];

            foreach ($checks as $surface => [$background, $foregrounds]) {
                foreach ($foregrounds as $foreground) {
                    $ratio = $this->contrast($t[$foreground], $background);

                    if ($ratio < 4.5) {
                        $failures[] = sprintf('%s: %s on the glass %s is %.2f:1 (needs 4.5:1)', $key, $foreground, $surface, $ratio);
                    }
                }
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function test_glass_has_solid_fallbacks_and_views_do_not_roll_their_own_blur(): void
    {
        $css = File::get(resource_path('css/app.css'));

        $this->assertStringContainsString('@supports not ((backdrop-filter: blur(1px))', $css, 'Glass needs a no-backdrop-filter fallback');
        $this->assertStringContainsString('prefers-reduced-transparency: reduce', $css);
        $this->assertStringContainsString('prefers-contrast: more', $css);
        $this->assertStringContainsString('forced-colors: active', $css);
        $this->assertStringContainsString('@supports (color: color-mix(', $css, 'The ambient glow must be skipped where color-mix() is unsupported');

        $offenders = [];

        foreach ($this->themedViewFiles() as $path) {
            if (preg_match('/\bbackdrop-blur|\bbackdrop-filter\b/', File::get($path))) {
                $offenders[] = str_replace(base_path().'/', '', $path);
            }
        }

        $this->assertSame([], $offenders, 'Use .mm-float / .mm-glass-bar instead of ad-hoc blur: '.implode(', ', $offenders));
    }

    public function test_android_glass_constants_match_the_web_glass_tokens_and_no_screen_hand_rolls_alpha(): void
    {
        $css = preg_replace('#/\*.*?\*/#s', '', File::get(resource_path('css/themes.css')));
        preg_match('/:root\s*\{([^{}]*)\}/', $css, $root);
        $glass = File::get(base_path('../mobile/src/theme/glass.ts'));

        foreach ([
            '--mm-glass-opacity' => 'panel',
            '--mm-glow-strength' => 'glow',
            '--mm-glow-strength-2' => 'glowSoft',
        ] as $token => $property) {
            $this->assertSame(1, preg_match('/'.preg_quote($token, '/').'\s*:\s*(\d+(?:\.\d+)?)%/', $root[1], $web), "themes.css must define {$token}");
            $this->assertSame(1, preg_match('/\b'.$property.':\s*(\d*\.?\d+)/', $glass, $android), "glass.ts must define {$property}");
            $this->assertEqualsWithDelta((float) $web[1] / 100, (float) $android[1], 0.0001, "{$property} in glass.ts drifted from {$token}");
        }

        // `${color}e6`-style hex-alpha suffixes bypass the shared recipe; use glassPanel()/glassScrim().
        $offenders = [];

        foreach ([base_path('../mobile/app'), base_path('../mobile/src')] as $directory) {
            foreach (File::allFiles($directory) as $file) {
                if (preg_match('/\$\{[^}]+\}[0-9a-fA-F]{2}[`\'"]/', File::get($file->getPathname()))) {
                    $offenders[] = str_replace(base_path('../').'', '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $offenders, 'Use theme/glass.ts instead of hex-alpha suffixes: '.implode(', ', $offenders));
    }

    public function test_solid_button_hover_states_darken_so_text_stays_readable(): void
    {
        $root = preg_replace('#/\*.*?\*/#s', '', File::get(resource_path('css/themes.css')));
        preg_match('/:root\s*\{([^{}]*)\}/', $root, $block);
        preg_match_all('/(--mm-[\w-]+)\s*:\s*(#[0-9a-fA-F]{6})\s*;/', $block[1], $matches, PREG_SET_ORDER);
        $semantic = array_column($matches, 2, 1);

        foreach (['--mm-danger-solid', '--mm-danger-solid-hover'] as $token) {
            $ratio = $this->contrast($semantic['--mm-on-danger'], $semantic[$token]);
            $this->assertGreaterThanOrEqual(4.5, $ratio, sprintf('--mm-on-danger on %s is %.2f:1', $token, $ratio));
        }

        // A lighter hover colour drops white text under 4.5:1 in the default theme, so solid
        // brand surfaces hover to the darker pressed shade (already covered by the pairs above).
        $offenders = [];

        foreach ($this->themedViewFiles() as $path) {
            if (str_contains(File::get($path), 'hover:bg-brand-hover')) {
                $offenders[] = str_replace(base_path().'/', '', $path);
            }
        }

        $this->assertSame([], $offenders, 'Use hover:bg-brand-pressed on solid brand backgrounds: '.implode(', ', $offenders));
    }

    public function test_semantic_text_meets_wcag_aa_on_surfaces_and_its_own_tint_in_both_modes(): void
    {
        $failures = [];

        foreach ($this->resolvedThemes() as $key => $t) {
            foreach (['success', 'warning', 'danger', 'info'] as $tone) {
                [$tintHex, $tintAlpha] = $this->rgbaToken($t["--mm-{$tone}-soft"]);

                foreach (['--mm-surface', '--mm-surface-2', '--mm-surface-3'] as $surface) {
                    // Badges and alerts: the tint over the surface they sit on.
                    $backgrounds = [$surface => $t[$surface], "{$tone}-soft on {$surface}" => $this->blend($tintHex, $t[$surface], $tintAlpha)];

                    foreach ($backgrounds as $label => $background) {
                        $ratio = $this->contrast($t["--mm-{$tone}"], $background);

                        if ($ratio < 4.5) {
                            $failures[] = sprintf('%s: --mm-%s on %s is %.2f:1 (needs 4.5:1)', $key, $tone, $label, $ratio);
                        }
                    }
                }
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function test_every_theme_has_a_day_and_a_night_palette_and_the_modes_differ(): void
    {
        $resolved = $this->resolvedThemes();

        foreach (array_keys(config('minimax.theme_colors')) as $key) {
            foreach (['dark', 'light'] as $mode) {
                $this->assertArrayHasKey("{$mode}/{$key}", $resolved);

                foreach ([...self::REQUIRED_TOKENS, '--mm-float', '--mm-header'] as $token) {
                    $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $resolved["{$mode}/{$key}"][$token] ?? '', "{$mode}/{$key} {$token} must resolve to a colour");
                }
            }

            // Day mode must really be light: dark text on a light canvas.
            $this->assertGreaterThan(0.8, $this->luminance($resolved["light/{$key}"]['--mm-bg']), "light/{$key} canvas");
            $this->assertLessThan(0.1, $this->luminance($resolved["light/{$key}"]['--mm-fg']), "light/{$key} text");
            $this->assertLessThan(0.05, $this->luminance($resolved["dark/{$key}"]['--mm-bg']), "dark/{$key} canvas");
        }

        // The default theme's night palette is Mini Max's (#17111F canvas, #6D28D9 accent).
        $this->assertSame('#17111f', strtolower($resolved['dark/purple-glass']['--mm-bg']));
        $this->assertSame('#251d32', strtolower($resolved['dark/purple-glass']['--mm-surface']));
        $this->assertSame('#6d28d9', strtolower($resolved['dark/purple-glass']['--mm-brand']));
        $this->assertSame('#f8fafc', strtolower($resolved['light/purple-glass']['--mm-bg']));
        $this->assertSame('#6d28d9', strtolower($resolved['light/purple-glass']['--mm-brand']));
    }

    public function test_android_theme_tokens_match_the_web_tokens_exactly(): void
    {
        $android = File::get(base_path('../mobile/src/theme/tokens.ts'));

        // CSS token => Android property
        $map = [
            '--mm-bg' => 'bg', '--mm-sidebar' => 'sidebar', '--mm-surface' => 'surface', '--mm-surface-2' => 'surface2',
            '--mm-surface-3' => 'surface3', '--mm-border' => 'border', '--mm-border-strong' => 'borderStrong',
            '--mm-fg' => 'fg', '--mm-fg-2' => 'fg2', '--mm-fg-3' => 'fg3',
            '--mm-brand' => 'brand', '--mm-brand-hover' => 'brandHover', '--mm-brand-pressed' => 'brandPressed',
            '--mm-brand-soft' => 'brandSoft', '--mm-brand-text' => 'brandText', '--mm-on-brand' => 'onBrand', '--mm-ring' => 'ring',
        ];

        $mismatches = [];

        foreach ($this->parseThemes() as $key => $tokens) {
            $start = strpos($android, "'{$key}': {");
            $this->assertNotFalse($start, "Android tokens.ts has no \"{$key}\" theme");
            $block = substr($android, $start, strpos($android, '},', $start) - $start);

            foreach ($map as $cssToken => $property) {
                $matched = preg_match('/\b'.$property.":\s*'(#[0-9a-fA-F]{3,8})'/", $block, $m);

                if (! $matched || strtolower($m[1]) !== strtolower($tokens[$cssToken])) {
                    $mismatches[] = "{$key}.{$property}: web {$tokens[$cssToken]} vs android ".($m[1] ?? 'missing');
                }
            }
        }

        $this->assertSame([], $mismatches, "Android tokens drifted from themes.css:\n".implode("\n", $mismatches));
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function parseThemes(): array
    {
        $css = preg_replace('#/\*.*?\*/#s', '', File::get(resource_path('css/themes.css')));
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $blocks, PREG_SET_ORDER);

        $themes = [];

        foreach ($blocks as [, $selector, $body]) {
            // The Day/Night layer is resolved separately (resolvedThemes); these are the shared, Android-mirrored blocks.
            if (str_contains($selector, 'data-mode') || ! preg_match_all("/\\[data-theme='([a-z-]+)'\\]/", $selector, $keys)) {
                continue;
            }

            preg_match_all('/(--mm-[\w-]+)\s*:\s*([^;]+);/', $body, $declarations, PREG_SET_ORDER);

            foreach ($keys[1] as $key) {
                foreach ($declarations as [, $name, $value]) {
                    $themes[$key][$name] = trim($value);
                }
            }
        }

        return $themes;
    }

    /**
     * Every color theme as the panels paint it in each mode ("dark/purple-glass", "light/cyber-blue" ...): the
     * :root tokens, then the theme block, then the mode's general overrides, then its theme-specific ones —
     * the same order the cascade applies them to <html>. var() references are resolved.
     *
     * @return array<string, array<string, string>>
     */
    private function resolvedThemes(): array
    {
        $css = preg_replace('#/\*.*?\*/#s', '', File::get(resource_path('css/themes.css')));
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $blocks, PREG_SET_ORDER);

        $declarations = function (string $body): array {
            preg_match_all('/(--mm-[\w-]+)\s*:\s*([^;]+);/', $body, $matches, PREG_SET_ORDER);

            return array_column(array_map(fn (array $m): array => [$m[1], trim($m[2])], $matches), 1, 0);
        };

        $root = [];
        $general = ['dark' => [], 'light' => []];
        $specific = ['dark' => [], 'light' => []];

        foreach ($blocks as [, $selector, $body]) {
            $selector = trim($selector);

            if ($selector === ':root') {
                $root = [...$root, ...$declarations($body)];

                continue;
            }

            foreach (explode(',', $selector) as $part) {
                if (! preg_match("/\\[data-mode='(dark|light)'\\]/", $part, $mode)) {
                    continue;
                }

                if (preg_match("/\\[data-theme='([a-z-]+)'\\]/", $part, $theme)) {
                    $specific[$mode[1]][$theme[1]] = [...($specific[$mode[1]][$theme[1]] ?? []), ...$declarations($body)];
                } elseif (! str_contains($part, ':not([data-theme])')) {
                    $general[$mode[1]] = [...$general[$mode[1]], ...$declarations($body)];
                }
            }
        }

        $resolved = [];

        foreach ($this->parseThemes() as $key => $theme) {
            foreach (['dark', 'light'] as $mode) {
                $tokens = [...$root, ...$theme, ...$general[$mode], ...($specific[$mode][$key] ?? [])];

                foreach ($tokens as $name => $value) {
                    if (preg_match('/^var\((--mm-[\w-]+)\)$/', $value, $reference)) {
                        $tokens[$name] = $tokens[$reference[1]];
                    }
                }

                $resolved["{$mode}/{$key}"] = $tokens;
            }
        }

        return $resolved;
    }

    /**
     * "rgb(32 213 138 / 0.16)" => ['#20d58a', 0.16]
     *
     * @return array{0: string, 1: float}
     */
    private function rgbaToken(string $value): array
    {
        $this->assertSame(1, preg_match('/rgb\(\s*(\d+)\s+(\d+)\s+(\d+)\s*\/\s*([\d.]+)\s*\)/', $value, $m), "Expected an rgb(r g b / a) tint, got {$value}");

        return [sprintf('#%02x%02x%02x', $m[1], $m[2], $m[3]), (float) $m[4]];
    }

    private function contrast(string $foregroundHex, string $backgroundHex): float
    {
        $a = $this->luminance($foregroundHex);
        $b = $this->luminance($backgroundHex);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    private function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = preg_replace('/(.)/', '$1$1', $hex);
        }

        [$r, $g, $b] = array_map(function (string $channel): float {
            $value = hexdec($channel) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split($hex, 2));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * Source-over blend of a colour at the given opacity over another, in sRGB like a browser.
     */
    private function blend(string $foregroundHex, string $backgroundHex, float $opacity): string
    {
        $channels = fn (string $hex): array => array_map('hexdec', str_split(ltrim($hex, '#'), 2));
        [$f, $b] = [$channels($foregroundHex), $channels($backgroundHex)];

        return '#'.implode('', array_map(
            fn (int $front, int $back): string => str_pad(dechex((int) round($front * $opacity + $back * (1 - $opacity))), 2, '0', STR_PAD_LEFT),
            $f,
            $b,
        ));
    }
}
