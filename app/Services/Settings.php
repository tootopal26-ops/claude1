<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Support\Clock;

/**
 * Key/value store backed by the admin_settings table (values are JSON).
 * Everything an admin can change without touching code goes through here.
 */
final class Settings
{
    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'site.name'                 => 'SOCIAL MEDIA BOOSTER',
            'site.tagline'              => 'Grow your Instagram and YouTube — fast, safe and affordable.',
            'site.logo'                 => null,
            'site.favicon'              => null,
            'site.footer_text'          => '© {year} {name}. All rights reserved.',
            'site.support_link'         => '',
            'site.payment_instructions' => 'Pay with any UPI app. Keep this page open until your payment is confirmed.',
            'site.maintenance'          => false,
            'site.maintenance_message'  => 'We are upgrading our systems and will be back shortly.',
            'seo.title'                 => 'SOCIAL MEDIA BOOSTER — Instagram & YouTube Growth Services',
            'seo.description'           => 'Buy Instagram followers, likes, views and YouTube subscribers, likes, views and watch time. Secure UPI payment, fast start, transparent prices.',
            'theme.accent_from'         => '#ff2d87',
            'theme.accent_to'           => '#ff3b3b',
            'business.timezone'         => 'Asia/Kolkata',
            'cron.last_run'             => 0,
        ];
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        if (self::$cache === null) {
            $vals = self::defaults();
            foreach (Db::all('SELECT k, v FROM admin_settings') as $row) {
                $decoded = json_decode((string) $row['v'], true, 16);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $vals[$row['k']] = $decoded;
                }
            }
            self::$cache = $vals;
        }
        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function set(string $key, mixed $value, ?int $adminId = null): void
    {
        self::setMany([$key => $value], $adminId);
    }

    /** @param array<string,mixed> $values */
    public static function setMany(array $values, ?int $adminId = null): void
    {
        $now = Clock::sql();
        Db::tx(static function () use ($values, $adminId, $now): void {
            foreach ($values as $k => $v) {
                Db::exec(
                    'INSERT INTO admin_settings (k, v, updated_by, updated_at) VALUES (?, ?, ?, ?) '
                    . 'ON DUPLICATE KEY UPDATE v = VALUES(v), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
                    [$k, json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $adminId, $now]
                );
            }
        });
        self::flush();
    }

    public static function flush(): void
    {
        self::$cache = null;
        \App\Support\Tz::reset();
    }

    /** Website settings shaped for templates. */
    public static function site(): array
    {
        $a = self::all();
        $name = (string) $a['site.name'];
        $footer = str_replace(['{year}', '{name}'], [gmdate('Y'), $name], (string) $a['site.footer_text']);
        return [
            'name'                 => $name,
            'tagline'              => (string) $a['site.tagline'],
            'logo_url'             => upload_url($a['site.logo'] ?: null) ?? asset('img/logo-mark.webp'),
            'has_custom_logo'      => !empty($a['site.logo']),
            'favicon_url'          => upload_url($a['site.favicon'] ?: null) ?? asset('img/favicon-48.png'),
            'footer_text'          => $footer,
            'support_link'         => (string) $a['site.support_link'],
            'payment_instructions' => (string) $a['site.payment_instructions'],
            'maintenance'          => (bool) $a['site.maintenance'],
            'maintenance_message'  => (string) $a['site.maintenance_message'],
            'seo_title'            => (string) $a['seo.title'],
            'seo_description'      => (string) $a['seo.description'],
            'accent_from'          => (string) $a['theme.accent_from'],
            'accent_to'            => (string) $a['theme.accent_to'],
        ];
    }
}
