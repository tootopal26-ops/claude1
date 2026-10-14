<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Support\Clock;
use App\Support\Color;
use App\Support\Tz;

/**
 * Website / branding settings the owner edits in the admin panel.
 * Everything is validated here (the browser's checks are only a convenience).
 */
final class AdminSettings
{
    public const DEFAULT_ACCENT = ['#ff2d87', '#ff3b3b'];

    /** Short list shown in the time-zone picker. */
    public const ZONES = [
        'Asia/Kolkata', 'Asia/Dhaka', 'Asia/Colombo', 'Asia/Kathmandu', 'Asia/Karachi', 'Asia/Dubai', 'Asia/Singapore', 'Asia/Jakarta',
        'Europe/London', 'Europe/Berlin', 'America/New_York', 'America/Chicago', 'America/Los_Angeles', 'Australia/Sydney', 'UTC',
    ];

    /** @return array<string,mixed> */
    public static function website(): array
    {
        $a = Settings::all();
        $site = Settings::site();
        $locked = self::timezoneLocked();
        $zones = self::ZONES;
        if (!in_array(Tz::name(), $zones, true)) {
            array_unshift($zones, Tz::name());
        }
        return [
            'name'                 => (string) $a['site.name'],
            'tagline'              => (string) $a['site.tagline'],
            'footer_text'          => (string) $a['site.footer_text'],
            'support_link'         => (string) $a['site.support_link'],
            'payment_instructions' => (string) $a['site.payment_instructions'],
            'maintenance'          => (bool) $a['site.maintenance'],
            'maintenance_message'  => (string) $a['site.maintenance_message'],
            'seo_title'            => (string) $a['seo.title'],
            'seo_description'      => (string) $a['seo.description'],
            'accent_from'          => (string) $a['theme.accent_from'],
            'accent_to'            => (string) $a['theme.accent_to'],
            'default_accent'       => self::DEFAULT_ACCENT,
            'timezone'             => Tz::name(),
            'timezone_label'       => Tz::label(),
            'timezone_locked'      => $locked,
            'timezones'            => $zones,
            'logo_url'             => $site['logo_url'],
            'has_custom_logo'      => $site['has_custom_logo'],
            'favicon_url'          => $site['favicon_url'],
            'has_custom_favicon'   => !empty($a['site.favicon']),
            'platforms'            => array_map(static fn(array $p): array => $p + [
                'tagline_raw' => (string) Db::val('SELECT COALESCE(tagline, \'\') FROM platforms WHERE id = ?', [$p['id']]),
            ], Catalog::platforms(false)),
        ];
    }

    /** The business time zone can only change before any visitor or order exists (it would re-label history). */
    public static function timezoneLocked(): bool
    {
        return (int) Db::val('SELECT COUNT(*) FROM orders') > 0 || (int) Db::val('SELECT COUNT(*) FROM visitor_days') > 0;
    }

    /**
     * @param array<string,mixed> $in
     * @param array{id:int,username:string,email:string} $admin
     * @return array<string,mixed> the saved settings
     */
    public static function saveWebsite(array $in, Request $req, array $admin): array
    {
        $cur = Settings::all();
        $errors = [];
        $set = [];

        $text = static function (string $field, string $key, int $min, int $max, string $label) use ($in, &$errors, &$set, $cur): void {
            if (!array_key_exists($field, $in)) {
                return;
            }
            $v = self::clean($in[$field]);
            $len = mb_strlen($v);
            if ($len < $min || $len > $max) {
                $errors[$field] = $min > 0 ? "{$label} must be {$min}–{$max} characters." : "{$label} can be at most {$max} characters.";
                return;
            }
            if ($v !== (string) ($cur[$key] ?? '')) {
                $set[$key] = $v;
            }
        };
        $text('name', 'site.name', 2, 60, 'The website name');
        $text('tagline', 'site.tagline', 0, 160, 'The tagline');
        $text('footer_text', 'site.footer_text', 0, 200, 'The footer text');
        $text('payment_instructions', 'site.payment_instructions', 0, 600, 'The payment instructions');
        $text('maintenance_message', 'site.maintenance_message', 0, 300, 'The maintenance message');
        $text('seo_title', 'seo.title', 5, 70, 'The page title');
        $text('seo_description', 'seo.description', 20, 200, 'The meta description');

        if (array_key_exists('support_link', $in)) {
            $link = self::clean($in['support_link']);
            if ($link !== '' && !self::isSafeSupportLink($link)) {
                $errors['support_link'] = 'Use a full https:// link (WhatsApp, Telegram, a help page…) or a mailto: address.';
            } elseif ($link !== (string) $cur['site.support_link']) {
                $set['site.support_link'] = $link;
            }
        }
        if (array_key_exists('maintenance', $in)) {
            $m = filter_var($in['maintenance'], FILTER_VALIDATE_BOOLEAN);
            if ($m !== (bool) $cur['site.maintenance']) {
                $set['site.maintenance'] = $m;
            }
        }

        foreach (['accent_from' => 'theme.accent_from', 'accent_to' => 'theme.accent_to'] as $field => $key) {
            if (!array_key_exists($field, $in)) {
                continue;
            }
            $hex = strtolower(trim((string) $in[$field]));
            if (!Color::isHex($hex)) {
                $errors[$field] = 'Choose a colour as #RRGGBB.';
            } elseif (($ratio = Color::buttonContrast($hex)) < 4.5) {
                $errors[$field] = sprintf('This colour is too light for white button text (contrast %.1f:1, needs 4.5:1). Pick a deeper shade.', $ratio);
            } elseif ($hex !== strtolower((string) $cur[$key])) {
                $set[$key] = $hex;
            }
        }

        if (array_key_exists('timezone', $in) && (string) $in['timezone'] !== Tz::name()) {
            $tz = (string) $in['timezone'];
            if (!Tz::isValid($tz)) {
                $errors['timezone'] = 'Unknown time zone.';
            } elseif (self::timezoneLocked()) {
                $errors['timezone'] = 'The time zone is locked because orders or visitors already exist — changing it would re-label your reports.';
            } else {
                $set['business.timezone'] = $tz;
            }
        }

        if ($errors) {
            throw new HttpException(422, 'Please fix the highlighted fields.', 'invalid', ['fields' => $errors]);
        }
        if ($set) {
            Settings::setMany($set, $admin['id']);
            // Field NAMES only for text; the toggles and colours are harmless to record.
            $meta = ['changed' => array_keys($set)];
            foreach (['site.maintenance', 'theme.accent_from', 'theme.accent_to'] as $k) {
                if (array_key_exists($k, $set)) {
                    $meta[$k] = $set[$k];
                }
            }
            Audit::log('settings.website', 'settings', null, $meta, $req, $admin);
        }
        return self::website();
    }

    /** Only links a visitor could safely follow (no javascript:, data:, file: …). */
    public static function isSafeSupportLink(string $link): bool
    {
        if (strlen($link) > 300 || preg_match('/[\x00-\x20<>"\'`\\\\]/', $link)) {
            return false;
        }
        if (str_starts_with($link, 'mailto:')) {
            return (bool) filter_var(substr($link, 7), FILTER_VALIDATE_EMAIL);
        }
        $p = parse_url($link);
        return is_array($p) && ($p['scheme'] ?? '') === 'https' && !empty($p['host']) && !isset($p['user']) && !isset($p['pass']);
    }

    private static function clean(mixed $v): string
    {
        $s = is_scalar($v) ? (string) $v : '';
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);   // control characters (keep tab/newline)
        return trim($s);
    }

    // ── branding images ────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $file one $_FILES entry
     * @return array<string,mixed>
     */
    public static function uploadBranding(string $kind, array $file, Request $req, array $admin): array
    {
        $map = ['site_logo' => 'site.logo', 'favicon' => 'site.favicon'];
        $key = $map[$kind] ?? throw new HttpException(404, 'Unknown image type.');
        $img = Assets::fromUpload($file, $kind);
        $old = Settings::get($key);
        Settings::set($key, $img['path'], $admin['id']);
        Assets::delete(is_string($old) ? $old : null);
        Audit::log('settings.branding', 'settings', $kind, ['action' => 'uploaded', 'bytes' => $img['bytes']], $req, $admin);
        return ['image' => $img, 'website' => self::website()];
    }

    /** @return array<string,mixed> */
    public static function resetBranding(string $kind, Request $req, array $admin): array
    {
        $map = ['site_logo' => 'site.logo', 'favicon' => 'site.favicon'];
        $key = $map[$kind] ?? throw new HttpException(404, 'Unknown image type.');
        $old = Settings::get($key);
        Settings::set($key, null, $admin['id']);
        Assets::delete(is_string($old) ? $old : null);
        Audit::log('settings.branding', 'settings', $kind, ['action' => 'reset'], $req, $admin);
        return ['website' => self::website()];
    }

    // ── platforms ─────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $in name, tagline, is_active, sort_order
     * @return array<string,mixed>
     */
    public static function savePlatform(int $id, array $in, Request $req, array $admin): array
    {
        $row = Db::one('SELECT * FROM platforms WHERE id = ?', [$id]) ?? throw new HttpException(404, 'Platform not found.');
        $errors = [];
        $data = [];
        if (array_key_exists('name', $in)) {
            $v = self::clean($in['name']);
            if (mb_strlen($v) < 2 || mb_strlen($v) > 40) {
                $errors['name'] = 'Enter a name (2–40 characters).';
            } else {
                $data['name'] = $v;
            }
        }
        if (array_key_exists('tagline', $in)) {
            $v = self::clean($in['tagline']);
            if (mb_strlen($v) > 120) {
                $errors['tagline'] = 'At most 120 characters.';
            } else {
                $data['tagline'] = $v !== '' ? $v : null;
            }
        }
        if (array_key_exists('is_active', $in)) {
            $data['is_active'] = filter_var($in['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            if ($data['is_active'] === 0 && (int) Db::val('SELECT COUNT(*) FROM platforms WHERE is_active = 1 AND id <> ?', [$id]) === 0) {
                $errors['is_active'] = 'At least one platform must stay active.';
            }
        }
        if (array_key_exists('sort_order', $in)) {
            if (!is_numeric($in['sort_order']) || (int) $in['sort_order'] < -1000 || (int) $in['sort_order'] > 1000) {
                $errors['sort_order'] = 'Enter a number between -1000 and 1000.';
            } else {
                $data['sort_order'] = (int) $in['sort_order'];
            }
        }
        if ($errors) {
            throw new HttpException(422, 'Please fix the highlighted fields.', 'invalid', ['fields' => $errors]);
        }
        $changed = array_keys(array_filter($data, static fn($v, $k): bool => (string) ($row[$k] ?? '') !== (string) ($v ?? ''), ARRAY_FILTER_USE_BOTH));
        if ($changed) {
            Db::update('platforms', $data + ['updated_at' => Clock::sql()], 'id = ?', [$id]);
            Audit::log('platform.updated', 'platform', $id, ['slug' => $row['slug'], 'changed' => $changed], $req, $admin);
        }
        return Catalog::platformView(Db::one('SELECT * FROM platforms WHERE id = ?', [$id]));
    }

    /** @param array<string,mixed> $file @return array<string,mixed> */
    public static function uploadPlatformLogo(int $id, array $file, Request $req, array $admin): array
    {
        $row = Db::one('SELECT * FROM platforms WHERE id = ?', [$id]) ?? throw new HttpException(404, 'Platform not found.');
        $img = Assets::fromUpload($file, 'platform_logo');
        Db::update('platforms', ['logo_path' => $img['path'], 'updated_at' => Clock::sql()], 'id = ?', [$id]);
        Assets::delete($row['logo_path']);
        Audit::log('platform.updated', 'platform', $id, ['slug' => $row['slug'], 'changed' => ['logo']], $req, $admin);
        return Catalog::platformView(Db::one('SELECT * FROM platforms WHERE id = ?', [$id]));
    }

    /** @return array<string,mixed> */
    public static function resetPlatformLogo(int $id, Request $req, array $admin): array
    {
        $row = Db::one('SELECT * FROM platforms WHERE id = ?', [$id]) ?? throw new HttpException(404, 'Platform not found.');
        Db::update('platforms', ['logo_path' => null, 'updated_at' => Clock::sql()], 'id = ?', [$id]);
        Assets::delete($row['logo_path']);
        Audit::log('platform.updated', 'platform', $id, ['slug' => $row['slug'], 'changed' => ['logo_reset']], $req, $admin);
        return Catalog::platformView(Db::one('SELECT * FROM platforms WHERE id = ?', [$id]));
    }
}
