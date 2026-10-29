<?php
declare(strict_types=1);

use App\Support\Themes;

/** Pure data / CSS-builder tests for the four built-in themes (Priority 2) — no database. */

T::test('four themes exist with the exact names, and the default is Rose Glass', function () {
    T::same(['rose', 'aurora', 'obsidian', 'emerald'], Themes::NAMES);
    T::same('rose', Themes::DEFAULT);
    T::same(4, count(Themes::all()));
    foreach (Themes::all() as $t) {
        T::true(in_array($t['name'], Themes::NAMES, true));
        T::true($t['label'] !== '');
        T::true(in_array($t['scheme'], ['light', 'dark'], true));
        T::true(preg_match('/^#[0-9a-f]{6}$/i', $t['accent_from']) === 1, 'accent_from is a hex colour');
        T::true(preg_match('/^#[0-9a-f]{6}$/i', $t['accent_to']) === 1, 'accent_to is a hex colour');
    }
});

T::test('only Obsidian Neon is a dark scheme; the other three are light', function () {
    T::same('dark', Themes::colorScheme('obsidian'));
    foreach (['rose', 'aurora', 'emerald'] as $name) {
        T::same('light', Themes::colorScheme($name));
    }
});

T::test('isValidName / isValidDensity reject anything outside the fixed lists', function () {
    foreach (Themes::NAMES as $n) {
        T::true(Themes::isValidName($n));
    }
    foreach (['Rose', 'rose ', 'pink', '', 'obsidian2'] as $bad) {
        T::false(Themes::isValidName($bad));
    }
    foreach (Themes::DENSITIES as $d) {
        T::true(Themes::isValidDensity($d));
    }
    foreach (['Compact', 'cosy', ''] as $bad) {
        T::false(Themes::isValidDensity($bad));
    }
});

T::test('css() emits every semantic colour token, the chosen accent, and falls back to rose/standard for an invalid name', function () {
    $css = Themes::css('rose', 'standard', '#ff2d87', '#ff3b3b');
    T::contains('--color-canvas:#F8F6FA', $css);
    T::contains('--color-surface:#FFFFFF', $css);
    T::contains('--accent-from:#ff2d87', $css);
    T::contains('--accent-to:#ff3b3b', $css);
    T::contains('color-scheme:light', $css);

    // an unknown theme/density never crashes — it quietly falls back to the safe defaults
    $fallback = Themes::css('not-a-theme', 'not-a-density', '#ff2d87', '#ff3b3b');
    T::same($css, $fallback);
});

T::test('Obsidian Neon is the only theme that overrides the glass surface tokens (dark translucent glass, not white)', function () {
    $obsidian = Themes::css('obsidian', 'standard', '#a855f7', '#7e22ce');
    T::contains('--glass-bg:', $obsidian);
    T::contains('--glass-border:', $obsidian);
    T::contains('color-scheme:dark', $obsidian);
    T::contains('--color-ink:#F5F3FA', $obsidian);

    $rose = Themes::css('rose', 'standard', '#ff2d87', '#ff3b3b');
    T::false(str_contains($rose, '--glass-bg:'), 'the three light themes rely on the component defaults instead of overriding them');
});

T::test('density scales radius and glass blur within the documented bounds, and leaves colours untouched', function () {
    $compact = Themes::css('rose', 'compact', '#ff2d87', '#ff3b3b');
    $standard = Themes::css('rose', 'standard', '#ff2d87', '#ff3b3b');
    $premium = Themes::css('rose', 'premium', '#ff2d87', '#ff3b3b');
    T::contains('--glass-blur:12px', $compact);
    T::contains('--glass-blur:18px', $standard);
    T::contains('--glass-blur:22px', $premium);
    foreach ([$compact, $standard, $premium] as $css) {
        T::contains('--color-canvas:#F8F6FA', $css); // the colour palette never changes with density
    }
});

T::test('defaultAccent() returns each theme\'s own brand colours, used to pre-fill the editor when a new theme is picked', function () {
    foreach (Themes::NAMES as $name) {
        [$from, $to] = Themes::defaultAccent($name);
        T::true(preg_match('/^#[0-9a-f]{6}$/i', $from) === 1);
        T::true(preg_match('/^#[0-9a-f]{6}$/i', $to) === 1);
    }
    T::same(['#ff2d87', '#ff3b3b'], Themes::defaultAccent('rose'));
});
