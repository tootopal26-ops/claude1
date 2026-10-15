<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Support\Money;
use App\Support\Status;
use App\Support\Targets;

/**
 * Read model for the public storefront. Nothing returned from here ever
 * contains provider cost, provider IDs or any other internal field.
 */
final class Catalog
{
    /** Section order on the page. */
    private const TYPE_ORDER = ['followers', 'subscribers', 'likes', 'views', 'verification', 'watch_time'];

    /**
     * Every service sold at EXACTLY this price (₹5) carries the red TRIAL PACK badge. The badge is derived from the configured selling price on
     * every render — it is not stored per service — so it appears on any service priced ₹5 and disappears the moment the price changes.
     */
    public const TRIAL_PRICE_PAISE = 500;
    public const TRIAL_BADGE = 'TRIAL PACK';

    public static function isTrial(int $pricePaise): bool
    {
        return $pricePaise === self::TRIAL_PRICE_PAISE;
    }

    /** Is this badge text the reserved (price-driven) one? Case, spacing and surrounding blanks do not matter. */
    public static function isTrialText(string $text): bool
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', ' ', trim($text))) === self::TRIAL_BADGE;
    }

    /** The admin-typed badge to show next to the automatic one: null when empty, and never the reserved TRIAL PACK text (no duplicates). */
    public static function customBadge(?string $text): ?string
    {
        $text = trim((string) $text);
        return $text === '' || self::isTrialText($text) ? null : $text;
    }

    /**
     * Never sell what cannot be routed: an AUTO service needs its SMM service id AND an active provider (its own, or the default one).
     * MANUAL services are always sellable. (Alias `s` = services.)
     */
    private const ROUTABLE_SQL = "(s.mode <> 'AUTO' OR (s.smm_service_id IS NOT NULL AND s.smm_service_id <> '' AND EXISTS ("
        . "SELECT 1 FROM smm_providers pr WHERE pr.is_active = 1 AND (pr.id = s.smm_provider_id OR (s.smm_provider_id IS NULL AND pr.is_default = 1)))))";

    /** @return list<array<string,mixed>> */
    public static function platforms(bool $onlyActive = true): array
    {
        $rows = Db::all('SELECT * FROM platforms ' . ($onlyActive ? 'WHERE is_active = 1 ' : '') . 'ORDER BY sort_order, id');
        return array_map(static fn(array $r): array => self::platformView($r), $rows);
    }

    /** @return array<string,mixed> */
    public static function platformView(array $r): array
    {
        $slug = (string) $r['slug'];
        $custom = upload_url($r['logo_path'] ?? null);
        $builtin = in_array($slug, ['instagram', 'youtube'], true) ? asset('img/platforms/' . $slug . '.webp') : asset('img/platforms/generic.svg');
        return [
            'id'         => (int) $r['id'],
            'slug'       => $slug,
            'name'       => (string) $r['name'],
            'tagline'    => (string) ($r['tagline'] ?? ''),
            'logo_url'   => $custom ?? $builtin,
            'has_custom_logo' => $custom !== null,
            'is_active'  => (bool) $r['is_active'],
            'sort_order' => (int) $r['sort_order'],
        ];
    }

    /**
     * Everything the home page needs: platforms → sections → service cards.
     *
     * @return list<array<string,mixed>>
     */
    public static function storefront(): array
    {
        $platforms = self::platforms(true);
        $byId = [];
        foreach ($platforms as $i => $p) {
            $platforms[$i]['sections'] = [];
            $byId[$p['id']] = $i;
        }
        $rows = Db::all(
            "SELECT s.* FROM services s JOIN platforms p ON p.id = s.platform_id "
            . "WHERE s.status = 'active' AND s.deleted_at IS NULL AND p.is_active = 1 AND " . self::ROUTABLE_SQL . " ORDER BY s.sort_order, s.id"
        );
        $sections = [];
        foreach ($rows as $r) {
            $pid = (int) $r['platform_id'];
            if (!isset($byId[$pid])) {
                continue;
            }
            $slug = $platforms[$byId[$pid]]['slug'];
            $sections[$pid][(string) $r['type']][] = self::card($r, $slug);
        }
        foreach ($platforms as $i => $p) {
            $secs = [];
            $types = array_keys($sections[$p['id']] ?? []);
            usort($types, static fn(string $a, string $b): int => self::typeRank($a) <=> self::typeRank($b));
            foreach ($types as $type) {
                $secs[] = ['type' => $type, 'label' => Status::serviceTypeLabel($type), 'services' => $sections[$p['id']][$type]];
            }
            $platforms[$i]['sections'] = $secs;
        }
        return $platforms;
    }

    private static function typeRank(string $t): int
    {
        $i = array_search($t, self::TYPE_ORDER, true);
        return $i === false ? 99 : $i;
    }

    /** @return array<string,mixed> one storefront card (public fields only) */
    public static function card(array $r, string $platformSlug): array
    {
        $custom = self::customBadge($r['badge'] ?? null);
        return [
            'id'          => (int) $r['id'],
            'type'        => (string) $r['type'],
            'target_kind' => (string) $r['target_kind'],
            'title'       => (string) $r['title'],
            'subtitle'    => (string) ($r['subtitle'] ?? ''),
            'description' => (string) ($r['description'] ?? ''),
            'price_paise' => (int) $r['price_paise'],
            'price'       => Money::inr((int) $r['price_paise']),
            'quantity'    => (int) $r['quantity'],
            'trial'       => self::isTrial((int) $r['price_paise']),
            'badge'       => $custom,
            'badge_color' => (string) ($r['badge_color'] ?: 'red'),
            'is_premium'  => (bool) $r['is_premium'],
            'icon_url'    => upload_url($r['icon_path'] ?? null),
            'placeholder' => Targets::placeholder($platformSlug, (string) $r['target_kind']),
            'hint'        => Targets::hint($platformSlug, (string) $r['target_kind']),
            'platform'    => $platformSlug,
        ];
    }

    /**
     * Service that can be ordered RIGHT NOW (active, not deleted, platform active,
     * sane price/quantity). Joined with its platform slug.
     *
     * @return array<string,mixed>|null
     */
    public static function orderable(int $serviceId): ?array
    {
        $r = Db::one(
            "SELECT s.*, p.slug AS platform_slug, p.name AS platform_name FROM services s JOIN platforms p ON p.id = s.platform_id "
            . "WHERE s.id = ? AND s.status = 'active' AND s.deleted_at IS NULL AND p.is_active = 1 AND " . self::ROUTABLE_SQL,
            [$serviceId]
        );
        if ($r === null) {
            return null;
        }
        $price = (int) $r['price_paise'];
        $qty = (int) $r['quantity'];
        if ($price < 100 || $qty < 1 || $qty < (int) $r['min_quantity'] || $qty > (int) $r['max_quantity']) {
            return null;   // misconfigured → never sellable (also guards against absurd values)
        }
        return $r;
    }

    public static function version(): string
    {
        return (string) Db::val("SELECT CONCAT(COALESCE(MAX(updated_at),''), '|', COUNT(*)) FROM services");
    }
}
