<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Support\Clock;
use App\Support\Color;
use App\Support\Money;

/**
 * Service tags & badges: named, coloured pills an admin can put on any service card.
 *
 * What a card shows is decided here and only here (effective()):
 *   1. the automatic TRIAL PACK tag when the service's selling price is EXACTLY ₹5 (500 paise — integer money, never a float), provided that
 *      tag is enabled; it takes priority over whatever tag is assigned;
 *   2. otherwise the tag assigned to the service (services.tag_id), provided it is enabled;
 *   3. otherwise nothing.
 * So a card never shows two badges, a disabled tag disappears without losing its assignments, and when the price moves away from ₹5 the
 * assigned tag is back. The automatic rule is bound to the tag's stable code ('trial-pack'), not to its name.
 */
final class Tags
{
    public const TRIAL_CODE = 'trial-pack';
    public const TRIAL_PRICE_PAISE = 500;
    public const NAME_MIN = 2;
    public const NAME_MAX = 24;
    public const MAX_TAGS = 100;
    /** Below this contrast ratio the text is practically invisible: refused by the server. */
    public const MIN_CONTRAST = 2.0;
    /** WCAG AA for small text: the admin gets a warning below this, but may keep the colours. */
    public const GOOD_CONTRAST = 4.5;

    /**
     * The 10 predefined tags: [code, name, background, text]. Keep in sync with database/migrations/003_service_tags.sql (a test compares them).
     *
     * @var list<array{0:string,1:string,2:string,3:string}>
     */
    public const PREDEFINED = [
        ['trial-pack',    'TRIAL PACK',    '#DC2626', '#FFFFFF'],
        ['best-seller',   'BEST SELLER',   '#7C3AED', '#FFFFFF'],
        ['popular',       'POPULAR',       '#D97706', '#FFFFFF'],
        ['best-value',    'BEST VALUE',    '#15803D', '#FFFFFF'],
        ['premium',       'PREMIUM',       '#0369A1', '#FFFFFF'],
        ['hot-deal',      'HOT DEAL',      '#BE185D', '#FFFFFF'],
        ['non-drop',      'NON DROP',      '#0F766E', '#FFFFFF'],
        ['instant',       'INSTANT',       '#4338CA', '#FFFFFF'],
        ['limited-offer', 'LIMITED OFFER', '#475569', '#FFFFFF'],
        ['vip',           'VIP',           '#A16207', '#FFFFFF'],
    ];

    /** @var array<int,array<string,mixed>>|null every tag by id, in display order */
    private static ?array $all = null;

    public static function flush(): void
    {
        self::$all = null;
    }

    // ───────────────────────────── reading ─────────────────────────────

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        if (self::$all === null) {
            self::$all = [];
            foreach (Db::all('SELECT * FROM service_tags ORDER BY sort_order, id') as $r) {
                self::$all[(int) $r['id']] = self::row($r);
            }
        }
        return self::$all;
    }

    /** @return array<string,mixed>|null */
    public static function get(int $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    /** The automatic TRIAL PACK tag (null only if somebody removed the row by hand). @return array<string,mixed>|null */
    public static function trial(): ?array
    {
        foreach (self::all() as $t) {
            if ($t['code'] === self::TRIAL_CODE) {
                return $t;
            }
        }
        return null;
    }

    /** Exactly ₹5.00. The price is integer paise everywhere, so this is an exact comparison. */
    public static function isTrialPrice(int $pricePaise): bool
    {
        return $pricePaise === self::TRIAL_PRICE_PAISE;
    }

    /**
     * The badge a storefront card shows, or null for none.
     *
     * @return array{id:int,name:string,bg:string,fg:string,auto:bool}|null
     */
    public static function effective(int $pricePaise, ?int $assignedId): ?array
    {
        $trial = self::trial();
        if ($trial !== null && $trial['is_active'] && self::isTrialPrice($pricePaise)) {
            return self::pub($trial, true);
        }
        if ($assignedId === null) {
            return null;
        }
        $t = self::get($assignedId);
        // the automatic tag is never shown for a price other than ₹5, even if a row was assigned to it behind our back
        if ($t === null || !$t['is_active'] || $t['code'] === self::TRIAL_CODE) {
            return null;
        }
        return self::pub($t, false);
    }

    /** @return array{id:int,name:string,bg:string,fg:string,auto:bool} */
    private static function pub(array $t, bool $auto): array
    {
        return ['id' => $t['id'], 'name' => $t['name'], 'bg' => $t['bg_color'], 'fg' => $t['text_color'], 'auto' => $auto];
    }

    /** @return array<string,mixed> */
    private static function row(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'code' => $r['code'] !== null ? (string) $r['code'] : null, 'name' => (string) $r['name'],
            'bg_color' => strtoupper((string) $r['bg_color']), 'text_color' => strtoupper((string) $r['text_color']),
            'is_active' => (bool) $r['is_active'], 'is_system' => (bool) $r['is_system'], 'sort_order' => (int) $r['sort_order'],
            'created_at' => (string) $r['created_at'], 'updated_at' => (string) $r['updated_at'],
        ];
    }

    // ───────────────────────────── stylesheet (CSP: no inline styles, so the colours live in /tags.css) ─────────────────────────────

    /** One rule per ENABLED tag: `.tag-<id>{--tag-bg:#RRGGBB;--tag-fg:#RRGGBB;--tag-ts:…}`. Every value is a validated HEX colour. */
    public static function css(): string
    {
        $out = '';
        foreach (self::all() as $t) {
            if ($t['is_active'] && Color::isHex($t['bg_color']) && Color::isHex($t['text_color'])) {
                $out .= sprintf(".tag-%d{--tag-bg:%s;--tag-fg:%s;--tag-ts:%s}\n", $t['id'], $t['bg_color'], $t['text_color'], self::textShadow($t['text_color']));
            }
        }
        return $out;
    }

    /** Cache-buster for /tags.css: changes whenever the generated CSS does. */
    public static function cssVersion(): string
    {
        return substr(md5(self::css()), 0, 8);
    }

    /** A soft shadow that lifts the label off the pill: dark under light text, light under dark text. */
    public static function textShadow(string $fg): string
    {
        return Color::relativeLuminance($fg) > 0.5 ? 'rgb(0 0 0 / 0.28)' : 'rgb(255 255 255 / 0.35)';
    }

    // ───────────────────────────── admin ─────────────────────────────

    /** @return array<string,mixed> */
    public static function meta(): array
    {
        $defaults = [];
        foreach (self::PREDEFINED as [$code, $name, $bg, $fg]) {
            $defaults[$code] = ['name' => $name, 'bg_color' => $bg, 'text_color' => $fg];
        }
        $trial = self::trial();
        return [
            'trial' => ['code' => self::TRIAL_CODE, 'tag_id' => $trial['id'] ?? null, 'price_paise' => self::TRIAL_PRICE_PAISE, 'price' => Money::inr(self::TRIAL_PRICE_PAISE)],
            'defaults' => $defaults,
            'limits' => ['name_min' => self::NAME_MIN, 'name_max' => self::NAME_MAX, 'max_tags' => self::MAX_TAGS, 'min_contrast' => self::MIN_CONTRAST, 'good_contrast' => self::GOOD_CONTRAST],
        ];
    }

    /** @return list<array<string,mixed>> every tag with the number of live services that use it */
    public static function adminList(): array
    {
        $usage = [];
        foreach (Db::all('SELECT tag_id, COUNT(*) AS n FROM services WHERE tag_id IS NOT NULL AND deleted_at IS NULL GROUP BY tag_id') as $r) {
            $usage[(int) $r['tag_id']] = (int) $r['n'];
        }
        return array_values(array_map(static fn(array $t): array => self::adminView($t, $usage[$t['id']] ?? 0), self::all()));
    }

    /** @return array<string,mixed> */
    public static function adminGet(int $id): array
    {
        $t = self::get($id) ?? throw new HttpException(404, 'Tag not found.');
        return self::adminView($t, (int) Db::val('SELECT COUNT(*) FROM services WHERE tag_id = ? AND deleted_at IS NULL', [$id]));
    }

    /** @return array<string,mixed> */
    private static function adminView(array $t, int $usage): array
    {
        $default = null;
        foreach (self::PREDEFINED as [$code, $name, $bg, $fg]) {
            if ($code === $t['code']) {
                $default = ['name' => $name, 'bg_color' => $bg, 'text_color' => $fg];
            }
        }
        return $t + ['is_auto' => $t['code'] === self::TRIAL_CODE, 'usage' => $usage, 'default' => $default,
            'contrast' => round(Color::contrast($t['bg_color'], $t['text_color']), 2)];
    }

    /**
     * Create (id = null) or update a tag. Only the fields present in $in change on an update, so a toggle can send just `is_active`.
     *
     * @param array<string,mixed> $in
     * @param array{id:int,username:string,email:string} $admin
     * @return array<string,mixed> the saved tag
     */
    public static function save(?int $id, array $in, Request $req, array $admin): array
    {
        $existing = null;
        if ($id !== null) {
            $existing = Db::one('SELECT * FROM service_tags WHERE id = ?', [$id]) ?? throw new HttpException(404, 'Tag not found.');
        }
        $errors = [];

        // ── name ──
        $name = (string) ($existing['name'] ?? '');
        if ($existing === null || array_key_exists('name', $in)) {
            [$clean, $err] = self::cleanName($in['name'] ?? null);
            if ($err !== null) {
                $errors['name'] = $err;
            } else {
                $name = (string) $clean;
                if ((int) Db::val('SELECT COUNT(*) FROM service_tags WHERE name = ? AND id <> ?', [$name, $id ?? 0]) > 0) {
                    $errors['name'] = 'A tag with this name already exists.';
                }
            }
        }

        // ── colours ──
        $bg = (string) ($existing['bg_color'] ?? '');
        $fg = (string) ($existing['text_color'] ?? '#FFFFFF');
        if ($existing === null || array_key_exists('bg_color', $in)) {
            $bg = Color::normalizeHex($in['bg_color'] ?? null) ?? '';
            if ($bg === '') {
                $errors['bg_color'] = 'Enter a HEX colour such as #DC2626.';
            }
        }
        if (array_key_exists('text_color', $in)) {
            $fg = Color::normalizeHex($in['text_color'] ?? null) ?? '';
            if ($fg === '') {
                $errors['text_color'] = 'Enter a HEX colour such as #FFFFFF.';
            }
        }
        $colorsTouched = $existing === null || array_key_exists('bg_color', $in) || array_key_exists('text_color', $in);
        if ($colorsTouched && $bg !== '' && $fg !== '' && !isset($errors['bg_color']) && !isset($errors['text_color']) && Color::contrast($bg, $fg) < self::MIN_CONTRAST) {
            $errors['text_color'] = 'The text and background colours are too close to read. Pick colours with more contrast.';
        }

        // ── enabled ──
        $active = $existing !== null ? (int) $existing['is_active'] : 1;
        if (array_key_exists('is_active', $in)) {
            $v = $in['is_active'];
            if (!is_bool($v) && !in_array($v, [0, 1, '0', '1', 'true', 'false'], true)) {
                $errors['is_active'] = 'Choose enabled or disabled.';
            } else {
                $active = ($v === true || $v === 1 || $v === '1' || $v === 'true') ? 1 : 0;
            }
        }

        if ($existing === null && (int) Db::val('SELECT COUNT(*) FROM service_tags') >= self::MAX_TAGS) {
            $errors['name'] = 'You can have at most ' . self::MAX_TAGS . ' tags. Delete one you no longer use first.';
        }
        if ($errors) {
            throw new HttpException(422, 'Please fix the highlighted fields.', 'invalid', ['fields' => $errors]);
        }

        $now = Clock::sql();
        $data = ['name' => $name, 'bg_color' => $bg, 'text_color' => $fg, 'is_active' => $active, 'updated_at' => $now];
        try {
            if ($existing === null) {
                $sort = (int) Db::val('SELECT COALESCE(MAX(sort_order), 0) FROM service_tags') + 10;
                $newId = Db::insert('service_tags', $data + ['code' => null, 'is_system' => 0, 'sort_order' => $sort, 'created_at' => $now]);
                Audit::log('tag.created', 'tag', $newId, ['name' => $name, 'bg' => $bg, 'text' => $fg, 'enabled' => (bool) $active], $req, $admin);
                self::flush();
                return self::adminGet($newId);
            }
            $changed = [];
            foreach (['name' => 'name', 'bg_color' => 'bg_color', 'text_color' => 'text_color', 'is_active' => 'is_active'] as $k => $col) {
                if ((string) $existing[$col] !== (string) $data[$k]) {
                    $changed[] = $k;
                }
            }
            if ($changed) {
                Db::update('service_tags', $data, 'id = ?', [$id]);
                Audit::log('tag.updated', 'tag', (int) $id, ['name' => $name, 'changed' => $changed], $req, $admin);
            }
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {                // lost a race against another admin choosing the same name
                throw new HttpException(422, 'Please fix the highlighted fields.', 'invalid', ['fields' => ['name' => 'A tag with this name already exists.']]);
            }
            throw $e;
        }
        self::flush();
        return self::adminGet((int) $id);
    }

    /**
     * Delete a CUSTOM tag. Every service that used it simply has no tag afterwards (the foreign key does the same on its own).
     *
     * @param array{id:int,username:string,email:string} $admin
     * @return array{unassigned:int} services that lost the tag
     */
    public static function delete(int $id, Request $req, array $admin): array
    {
        $t = Db::one('SELECT * FROM service_tags WHERE id = ?', [$id]) ?? throw new HttpException(404, 'Tag not found.');
        if ((int) $t['is_system'] === 1) {
            throw new HttpException(422, 'The predefined tags cannot be deleted. Disable the tag instead.', 'protected');
        }
        $now = Clock::sql();
        $unassigned = Db::tx(static function () use ($id, $now): int {
            $n = Db::exec('UPDATE services SET tag_id = NULL, updated_at = ? WHERE tag_id = ?', [$now, $id]);
            Db::exec('DELETE FROM service_tags WHERE id = ? AND is_system = 0', [$id]);
            return $n;
        });
        Audit::log('tag.deleted', 'tag', $id, ['name' => $t['name'], 'unassigned_services' => $unassigned], $req, $admin);
        self::flush();
        return ['unassigned' => $unassigned];
    }

    // ───────────────────────────── validation helpers ─────────────────────────────

    /** @return array{0:?string,1:?string} [clean name, error] */
    public static function cleanName(mixed $raw): array
    {
        if (!is_string($raw) && !is_int($raw)) {
            return [null, 'Enter a name for the tag.'];
        }
        $s = preg_replace('/\s+/u', ' ', (string) $raw);
        if ($s === null) {
            return [null, 'The name must be plain text.'];
        }
        $s = trim($s);
        $len = mb_strlen($s);
        if ($len < self::NAME_MIN) {
            return [null, 'Enter a name of at least ' . self::NAME_MIN . ' characters.'];
        }
        if ($len > self::NAME_MAX) {
            return [null, 'Keep the name to ' . self::NAME_MAX . ' characters or fewer so the badge stays compact.'];
        }
        if (preg_match('/[<>\p{C}]/u', $s) || !preg_match('/[\p{L}\p{N}]/u', $s)) {
            return [null, 'Use letters, numbers and simple punctuation (no < > or control characters), with at least one letter or number.'];
        }
        return [$s, null];
    }

    // ───────────────────────────── seeding ─────────────────────────────

    /** Insert the predefined tags that are missing (never touches an existing row). @return int rows created */
    public static function seedPredefined(): int
    {
        $now = Clock::sql();
        $created = 0;
        foreach (self::PREDEFINED as $i => [$code, $name, $bg, $fg]) {
            $created += Db::exec(
                'INSERT IGNORE INTO service_tags (code, name, bg_color, text_color, is_active, is_system, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, 1, 1, ?, ?, ?)',
                [$code, $name, $bg, $fg, ($i + 1) * 10, $now, $now]
            );
        }
        self::flush();
        return $created;
    }
}
