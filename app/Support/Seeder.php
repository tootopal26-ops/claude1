<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Db;
use App\Services\Password;

/**
 * Idempotent data seeding: safe to run on every deploy. It only INSERTS what is
 * missing, so it never overwrites anything an admin has edited.
 */
final class Seeder
{
    /** @return array{platforms:int,services:int} rows created */
    public static function catalog(): array
    {
        $cat = require SMB_ROOT . '/database/seeds/catalog.php';
        $now = Clock::sql();
        $created = ['platforms' => 0, 'services' => 0];

        foreach ($cat['platforms'] as $p) {
            $created['platforms'] += Db::exec(
                'INSERT IGNORE INTO platforms (slug, name, tagline, is_active, sort_order, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?, ?)',
                [$p['slug'], $p['name'], $p['tagline'], $p['sort_order'], $now, $now]
            );
        }
        $platformIds = [];
        foreach (Db::all('SELECT id, slug FROM platforms') as $r) {
            $platformIds[$r['slug']] = (int) $r['id'];
        }

        foreach ($cat['services'] as [$code, $platform, $type, $kind, $title, $subtitle, $price, $qty, $badge, $badgeColor, $premium, $sort]) {
            $n = Db::exec(
                'INSERT IGNORE INTO services (code, platform_id, type, target_kind, title, subtitle, price_paise, quantity, min_quantity, max_quantity, '
                . "mode, smm_cost_paise, badge, badge_color, is_premium, status, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'MANUAL', 0, ?, ?, ?, 'active', ?, ?, ?)",
                [$code, $platformIds[$platform], $type, $kind, $title, $subtitle, $price, $qty, $qty, $qty, $badge, $badgeColor, $premium, $sort, $now, $now]
            );
            if ($n === 1) {
                $created['services']++;
                Db::insert('service_pricing', [
                    'service_id' => Db::lastId(), 'price_paise' => $price, 'cost_paise' => 0,
                    'changed_by' => null, 'note' => 'Initial price', 'created_at' => $now,
                ]);
            }
        }
        return $created;
    }

    /** Default CPC (INR 1.08) and the 3-second qualification window. */
    public static function advertisement(): bool
    {
        if ((int) Db::val('SELECT COUNT(*) FROM advertisement_settings') > 0) {
            return false;
        }
        Db::insert('advertisement_settings', [
            'cpc_paise' => 108, 'qualify_seconds' => 3, 'assumed_conversion_bps' => 400,
            'set_by' => null, 'created_at' => Clock::sql(),
        ]);
        return true;
    }

    /** A disabled placeholder row so Admin → Payment Gateway has something to edit. */
    public static function gateway(): bool
    {
        if ((int) Db::val('SELECT COUNT(*) FROM payment_gateways') > 0) {
            return false;
        }
        $now = Clock::sql();
        Db::insert('payment_gateways', [
            'provider' => 'alpha_upi', 'name' => 'Alpha UPI', 'is_active' => 0, 'environment' => 'test',
            'config_json' => json_encode(['base_url' => '', 'provider_pref' => 'auto']),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        return true;
    }

    /**
     * Create the first admin if there is none yet.
     *
     * @return bool true when an account was created
     */
    public static function admin(string $username, string $email, string $password, bool $mustChange = false): bool
    {
        if ((int) Db::val('SELECT COUNT(*) FROM admins') > 0) {
            return false;
        }
        $problems = Password::problems($password, ['username' => $username, 'email' => $email]);
        if ($problems) {
            throw new \InvalidArgumentException('Admin password is too weak: ' . implode(' ', $problems));
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Admin email is not valid.');
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
            throw new \InvalidArgumentException('Admin username must be 3–64 letters, numbers, dots, dashes or underscores.');
        }
        $now = Clock::sql();
        Db::insert('admins', [
            'username' => $username, 'email' => strtolower($email), 'password_hash' => Password::hash($password),
            'role' => 'owner', 'is_active' => 1, 'must_change_password' => $mustChange ? 1 : 0,
            'password_changed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return true;
    }

    /** Everything except the admin account. */
    public static function all(): array
    {
        return [
            'catalog'       => self::catalog(),
            'advertisement' => self::advertisement(),
            'gateway'       => self::gateway(),
        ];
    }
}
