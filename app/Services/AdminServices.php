<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Support\Clock;
use App\Support\Money;
use App\Support\Profit;
use App\Support\Status;

/**
 * Service catalogue management for the admin: validation, pricing history,
 * soft delete and the profit preview. Prices/costs are parsed from rupee strings
 * into integer paise — the browser never sends paise and the server never trusts
 * any calculation the browser made.
 */
final class AdminServices
{
    /** platform slug → allowed target kinds */
    private const KINDS = ['instagram' => ['profile', 'post'], 'youtube' => ['channel', 'video']];

    /** @return array<string,mixed> */
    public static function meta(): array
    {
        $est = Ads::estimatedPerOrder();
        return [
            'platforms' => array_map(static fn(array $p): array => ['id' => $p['id'], 'slug' => $p['slug'], 'name' => $p['name'], 'logo_url' => $p['logo_url'], 'is_active' => $p['is_active']], Catalog::platforms(false)),
            'providers' => array_map(static fn(array $p): array => ['id' => $p['id'], 'name' => $p['name'], 'is_active' => $p['is_active'], 'is_default' => $p['is_default']], SmmProviders::all()),
            'types' => array_map(static fn(string $t): array => ['value' => $t, 'label' => Status::serviceTypeLabel($t)], Status::SERVICE_TYPES),
            'target_kinds' => self::KINDS,
            'tags' => array_values(array_map(static fn(array $t): array => [
                'id' => $t['id'], 'name' => $t['name'], 'bg_color' => $t['bg_color'], 'text_color' => $t['text_color'],
                'is_active' => $t['is_active'], 'is_system' => $t['is_system'], 'is_auto' => $t['code'] === Tags::TRIAL_CODE,
            ], Tags::all())),
            'tag_rule' => Tags::meta()['trial'],
            'cpc_paise' => Ads::cpcPaise(),
            'est_ad_cost_paise' => $est['paise'],
            'est_ad_cost_basis' => $est['basis'],
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function list(): array
    {
        $est = Ads::estimatedPerOrder()['paise'];
        $rows = Db::all(
            'SELECT s.*, p.slug AS platform_slug, p.name AS platform_name, pr.name AS provider_name, '
            . "(SELECT COUNT(*) FROM orders o WHERE o.service_id = s.id AND o.paid_at IS NOT NULL) AS paid_orders FROM services s "
            . 'JOIN platforms p ON p.id = s.platform_id LEFT JOIN smm_providers pr ON pr.id = s.smm_provider_id '
            . 'WHERE s.deleted_at IS NULL ORDER BY p.sort_order, p.id, s.sort_order, s.id'
        );
        return array_map(static fn(array $r): array => self::view($r, $est), $rows);
    }

    public static function get(int $id): array
    {
        $r = Db::one(
            'SELECT s.*, p.slug AS platform_slug, p.name AS platform_name, pr.name AS provider_name, '
            . "(SELECT COUNT(*) FROM orders o WHERE o.service_id = s.id AND o.paid_at IS NOT NULL) AS paid_orders FROM services s "
            . 'JOIN platforms p ON p.id = s.platform_id LEFT JOIN smm_providers pr ON pr.id = s.smm_provider_id WHERE s.id = ? AND s.deleted_at IS NULL',
            [$id]
        ) ?? throw new HttpException(404, 'Service not found.');
        $v = self::view($r, Ads::estimatedPerOrder()['paise']);
        $v['pricing_history'] = array_map(static fn(array $h): array => [
            'price_paise' => (int) $h['price_paise'], 'cost_paise' => (int) $h['cost_paise'], 'by' => $h['username'] ?: 'system', 'note' => $h['note'], 'at' => Clock::iso($h['created_at']),
        ], Db::all('SELECT h.*, a.username FROM service_pricing h LEFT JOIN admins a ON a.id = h.changed_by WHERE h.service_id = ? ORDER BY h.id DESC LIMIT 10', [$id]));
        return $v;
    }

    /** @return array<string,mixed> */
    private static function view(array $r, int $estAdPaise): array
    {
        $price = (int) $r['price_paise'];
        $cost = (int) $r['smm_cost_paise'];
        $preview = Profit::servicePreview($price, $cost, (string) $r['mode'], $estAdPaise);
        $tagId = ($r['tag_id'] ?? null) !== null ? (int) $r['tag_id'] : null;
        return [
            'id' => (int) $r['id'], 'code' => $r['code'], 'platform_id' => (int) $r['platform_id'], 'platform' => $r['platform_slug'], 'platform_name' => $r['platform_name'],
            'type' => $r['type'], 'type_label' => Status::serviceTypeLabel((string) $r['type']), 'target_kind' => $r['target_kind'],
            'title' => $r['title'], 'subtitle' => (string) ($r['subtitle'] ?? ''), 'description' => (string) ($r['description'] ?? ''),
            'price_paise' => $price, 'price' => Money::plain($price), 'quantity' => (int) $r['quantity'], 'min_quantity' => (int) $r['min_quantity'], 'max_quantity' => (int) $r['max_quantity'],
            'mode' => $r['mode'], 'smm_provider_id' => $r['smm_provider_id'] !== null ? (int) $r['smm_provider_id'] : null, 'provider_name' => $r['provider_name'],
            'smm_service_id' => (string) ($r['smm_service_id'] ?? ''), 'smm_cost_paise' => $cost, 'smm_cost' => Money::plain($cost),
            'tag_id' => $tagId, 'effective_tag' => Tags::effective($price, $tagId), 'is_premium' => (bool) $r['is_premium'],
            'icon_url' => upload_url($r['icon_path'] ?? null), 'status' => $r['status'], 'sort_order' => (int) $r['sort_order'],
            'paid_orders' => (int) $r['paid_orders'], 'updated_at' => (string) $r['updated_at'], 'preview' => $preview,
        ];
    }

    /**
     * Create (id = null) or update a service from admin input.
     *
     * @param array<string,mixed> $in
     * @param array{id:int,username:string,email:string} $admin
     * @return array<string,mixed> the saved service
     */
    public static function save(?int $id, array $in, Request $req, array $admin): array
    {
        $existing = null;
        if ($id !== null) {
            $existing = Db::one('SELECT * FROM services WHERE id = ? AND deleted_at IS NULL', [$id]) ?? throw new HttpException(404, 'Service not found.');
            if (isset($in['expected_updated_at']) && (string) $in['expected_updated_at'] !== (string) $existing['updated_at']) {
                throw new HttpException(409, 'This service was changed by someone else while you were editing. Reload it and apply your changes again.', 'conflict');
            }
        }
        $val = static fn(string $k, mixed $default = null): mixed => array_key_exists($k, $in) ? $in[$k] : ($existing[$k] ?? $default);
        $errors = [];

        // ── platform / type / target ──
        $platform = Db::one('SELECT id, slug FROM platforms WHERE id = ?', [(int) $val('platform_id', 0)]);
        if ($platform === null) {
            $errors['platform_id'] = 'Choose a platform.';
        }
        $type = (string) $val('type', '');
        if (!in_array($type, Status::SERVICE_TYPES, true)) {
            $errors['type'] = 'Choose a service type.';
        }
        $kind = (string) $val('target_kind', '');
        if (!in_array($kind, Status::TARGET_KINDS, true) || ($platform && isset(self::KINDS[$platform['slug']]) && !in_array($kind, self::KINDS[$platform['slug']], true))) {
            $errors['target_kind'] = 'This target type does not exist on the chosen platform.';
        }

        // ── text ──
        $code = trim((string) $val('code', ''));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,62}$/D', $code)) {
            $errors['code'] = 'Use 2–63 lowercase letters, numbers, dashes or underscores (for example ig-followers-50).';
        } elseif ((int) Db::val('SELECT COUNT(*) FROM services WHERE code = ? AND id <> ?', [$code, $id ?? 0]) > 0) {
            $errors['code'] = 'That Service ID is already used.';
        }
        $title = trim((string) $val('title', ''));
        if ($title === '' || mb_strlen($title) > 120) {
            $errors['title'] = 'Enter a title (up to 120 characters).';
        }
        $subtitle = trim((string) ($val('subtitle', '') ?? ''));
        if (mb_strlen($subtitle) > 160) {
            $errors['subtitle'] = 'At most 160 characters.';
        }
        $description = trim((string) ($val('description', '') ?? ''));
        if (mb_strlen($description) > 2000) {
            $errors['description'] = 'At most 2000 characters.';
        }
        // ── tag (the badge on the card). TRIAL PACK is automatic at exactly ₹5 and cannot be assigned by hand; a disabled tag can stay but not be newly assigned ──
        $tagId = ($existing['tag_id'] ?? null) !== null ? (int) $existing['tag_id'] : null;
        if (array_key_exists('tag_id', $in)) {
            $raw = $in['tag_id'];
            if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
                $tagId = null;
            } elseif (!(is_int($raw) && $raw > 0) && !(is_string($raw) && preg_match('/^\d{1,10}$/D', $raw))) {
                $errors['tag_id'] = 'Choose a tag from the list.';
            } else {
                $want = (int) $raw;
                $tag = Tags::get($want);
                if ($tag === null) {
                    $errors['tag_id'] = 'That tag no longer exists. Reload the page.';
                } elseif ($tag['code'] === Tags::TRIAL_CODE) {
                    $errors['tag_id'] = $tag['name'] . ' is automatic: it appears on every service priced at exactly ' . Money::inr(Tags::TRIAL_PRICE_PAISE) . ', so it cannot be assigned by hand.';
                } elseif (!$tag['is_active'] && $want !== $tagId) {
                    $errors['tag_id'] = 'That tag is disabled. Enable it in Tags & Badges first.';
                } else {
                    $tagId = $want;
                }
            }
        }

        // ── money & quantities (rupee strings → integer paise) ──
        $price = null;
        try {
            $price = Money::parseRupees(self::scalar($in['price'] ?? null, $existing !== null ? Money::plain((int) $existing['price_paise']) : null));
            if ($price < 100 || $price > 10_000_000) {
                $errors['price'] = 'The price must be between ₹1 and ₹1,00,000.';
            }
        } catch (\InvalidArgumentException) {
            $errors['price'] = 'Enter the price in rupees with at most two decimals.';
        }
        $quantity = self::intField($in, 'quantity', $existing['quantity'] ?? null, 1, 2_000_000_000, $errors, 'Enter a whole number of at least 1.');
        $min = self::intField($in, 'min_quantity', $existing['min_quantity'] ?? 1, 1, 2_000_000_000, $errors, 'Enter a whole number of at least 1.');
        $max = self::intField($in, 'max_quantity', $existing['max_quantity'] ?? 100_000_000, 1, 2_000_000_000, $errors, 'Enter a whole number of at least 1.');
        if ($quantity !== null && $min !== null && $max !== null) {
            if ($min > $max) {
                $errors['max_quantity'] = 'Maximum must not be below the minimum.';
            } elseif ($quantity < $min || $quantity > $max) {
                $errors['quantity'] = 'The pack quantity must be between the minimum and maximum.';
            }
        }

        // ── delivery ──
        $mode = (string) $val('mode', 'MANUAL');
        if (!in_array($mode, [Status::AUTO, Status::MANUAL], true)) {
            $errors['mode'] = 'Choose AUTO or MANUAL.';
        }
        $smmId = trim((string) ($val('smm_service_id', '') ?? ''));
        $providerId = $val('smm_provider_id');
        $providerId = ($providerId === '' || $providerId === null || $providerId === 0 || $providerId === '0') ? null : (int) $providerId;
        $cost = 0;
        try {
            $cost = Money::parseRupees(self::scalar($in['smm_cost'] ?? null, $existing !== null ? Money::plain((int) $existing['smm_cost_paise']) : '0'));
        } catch (\InvalidArgumentException) {
            $errors['smm_cost'] = 'Enter the provider cost in rupees with at most two decimals.';
        }
        if ($providerId !== null && Db::val('SELECT id FROM smm_providers WHERE id = ?', [$providerId]) === null) {
            $errors['smm_provider_id'] = 'That provider does not exist.';
        }
        if ($mode === Status::AUTO) {
            if ($smmId === '' || !preg_match('/^[A-Za-z0-9_.\-]{1,64}$/D', $smmId)) {
                $errors['smm_service_id'] = 'AUTO services need the SMM service ID from your provider (letters, numbers, - _ .).';
            }
        } elseif ($smmId !== '' && !preg_match('/^[A-Za-z0-9_.\-]{1,64}$/D', $smmId)) {
            $errors['smm_service_id'] = 'Use letters, numbers, - _ . only.';
        }

        // ── display ──
        $status = (string) $val('status', 'active');
        if (!in_array($status, ['active', 'inactive'], true)) {
            $errors['status'] = 'Choose active or inactive.';
        }
        $sort = $val('sort_order', 0);
        if (!is_numeric($sort) || (int) $sort < -100000 || (int) $sort > 100000) {
            $errors['sort_order'] = 'Enter a whole number between -100000 and 100000.';
        }
        $iconPath = array_key_exists('icon_path', $in) ? ($in['icon_path'] === null || $in['icon_path'] === '' ? null : (string) $in['icon_path']) : ($existing['icon_path'] ?? null);
        if ($iconPath !== null && (!Assets::isOwnPath($iconPath) || !is_file(SMB_PUBLIC . '/uploads/' . $iconPath))) {
            $errors['icon_path'] = 'Upload the icon again.';
        }

        if ($errors) {
            throw new HttpException(422, 'Please fix the highlighted fields.', 'invalid', ['fields' => $errors]);
        }

        $now = Clock::sql();
        $data = [
            'code' => $code, 'platform_id' => (int) $platform['id'], 'type' => $type, 'target_kind' => $kind, 'title' => $title,
            'subtitle' => $subtitle !== '' ? $subtitle : null, 'description' => $description !== '' ? $description : null,
            'price_paise' => $price, 'quantity' => $quantity, 'min_quantity' => $min, 'max_quantity' => $max,
            'mode' => $mode, 'smm_provider_id' => $providerId, 'smm_service_id' => $smmId !== '' ? $smmId : null, 'smm_cost_paise' => $cost,
            'tag_id' => $tagId, 'icon_path' => $iconPath,
            'is_premium' => (int) (bool) $val('is_premium', false), 'status' => $status, 'sort_order' => (int) $sort, 'updated_at' => $now,
        ];
        if (!Tags::available()) {
            unset($data['tag_id']);                       // database update 003 is still pending: everything else can be saved meanwhile
        }

        $changed = [];
        if ($existing !== null) {
            foreach ($data as $k => $v) {
                if ($k !== 'updated_at' && (string) ($existing[$k] ?? '') !== (string) ($v ?? '')) {
                    $changed[] = $k;
                }
            }
            if (!$changed) {
                return self::get((int) $id);              // nothing changed: no history noise, no audit row
            }
        }

        $savedId = Db::tx(static function () use ($id, $existing, $data, $changed, $admin, $now): int {
            if ($existing === null) {
                $newId = Db::insert('services', $data + ['created_at' => $now]);
                Db::insert('service_pricing', ['service_id' => $newId, 'price_paise' => $data['price_paise'], 'cost_paise' => $data['smm_cost_paise'], 'changed_by' => $admin['id'], 'note' => 'Created', 'created_at' => $now]);
                return $newId;
            }
            Db::update('services', $data, 'id = ?', [$id]);
            if (in_array('price_paise', $changed, true) || in_array('smm_cost_paise', $changed, true)) {
                Db::insert('service_pricing', ['service_id' => $id, 'price_paise' => $data['price_paise'], 'cost_paise' => $data['smm_cost_paise'], 'changed_by' => $admin['id'], 'note' => 'Price/cost updated', 'created_at' => $now]);
            }
            return (int) $id;
        });

        if ($existing === null) {
            Audit::log('service.created', 'service', $savedId, ['code' => $code, 'price' => Money::plain((int) $price), 'mode' => $mode], $req, $admin);
        } else {
            if (in_array('price_paise', $changed, true)) {
                Audit::log('service.price_changed', 'service', $savedId, ['code' => $code, 'from' => Money::plain((int) $existing['price_paise']), 'to' => Money::plain((int) $price)], $req, $admin);
            }
            Audit::log('service.updated', 'service', $savedId, ['code' => $code, 'changed' => $changed], $req, $admin);
            // an icon that was replaced or removed is no longer needed
            if (in_array('icon_path', $changed, true)) {
                Assets::delete($existing['icon_path']);
            }
        }
        return self::get($savedId);
    }

    /** Soft delete: the row stays (orders reference it) but the service disappears and its code is freed. */
    public static function delete(int $id, Request $req, array $admin): void
    {
        $r = Db::one('SELECT * FROM services WHERE id = ? AND deleted_at IS NULL', [$id]) ?? throw new HttpException(404, 'Service not found.');
        $now = Clock::sql();
        Db::update('services', [
            'deleted_at' => $now, 'status' => 'inactive', 'code' => mb_substr($r['code'], 0, 40) . '~del' . $id, 'updated_at' => $now,
        ], 'id = ?', [$id]);
        Assets::delete($r['icon_path']);
        Audit::log('service.deleted', 'service', $id, ['code' => $r['code'], 'title' => $r['title']], $req, $admin);
    }

    // ── helpers ──

    private static function scalar(mixed $v, ?string $fallback): string|int|float|null
    {
        if ($v === null || $v === '') {
            return $fallback;
        }
        return is_scalar($v) ? $v : null;
    }

    /** @param array<string,mixed> $in @param array<string,string> $errors */
    private static function intField(array $in, string $key, mixed $fallback, int $min, int $max, array &$errors, string $msg): ?int
    {
        $v = array_key_exists($key, $in) ? $in[$key] : $fallback;
        if (!is_scalar($v) || !preg_match('/^\d{1,10}$/D', (string) $v) || (int) $v < $min || (int) $v > $max) {
            $errors[$key] = $msg;
            return null;
        }
        return (int) $v;
    }
}
