<?php

namespace App\Domain\Settings;

use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\StaffUser;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only way platform_settings rows are written, and the canonical
 * list of which settings are Admin-editable at all — deliberately not a
 * general-purpose key/value store, only this fixed catalog.
 *
 * Every key here corresponds to a config('minimax.*') value that every
 * existing call site (MeetsMinimumAge, WalletService's risk-alert check,
 * ...) already reads. AppServiceProvider::boot() overrides those config
 * values from the platform_settings table on every request, so get()
 * just reads config() — by the time anything calls it, the override has
 * already run — and nothing else in the app needs to know these
 * settings are DB-backed at all.
 *
 * Each setting belongs to a group. The generic Platform settings page edits
 * the "platform" group; Customer Support and the Ads & Monetization pages
 * edit their own groups through the same service, so every write is
 * validated here (server side) and audit-logged the same way.
 */
class PlatformSettingsService
{
    /**
     * @var array<string, array{label: string, type: 'int'|'decimal'|'percent'|'bool'|'url'|'string'|'text'|'time'|'date'|'timezone'|'string_list', group?: string, min?: int|float, max?: int|float, nullable?: bool}>
     */
    public const array CATALOG = [
        'min_age' => ['label' => 'Minimum registration age (years)', 'type' => 'int'],
        'dob_exclusion_window_days' => ['label' => 'DOB exclusion window (days)', 'type' => 'int'],
        'wallet_risk_alert_threshold' => ['label' => 'Wallet risk-alert threshold (coins)', 'type' => 'decimal'],
        'blocked_states' => ['label' => 'Blocked states/regions — one per line. Empty means none are blocked; this is a compliance call for the operator to make, not a default this platform ships with.', 'type' => 'string_list'],

        'support_url' => ['label' => 'Customer support link', 'type' => 'url', 'group' => 'support', 'nullable' => true],

        'ads_master_enabled' => ['label' => 'All ads', 'type' => 'bool', 'group' => 'ads'],
        'ads_rewarded_enabled' => ['label' => 'Rewarded ads', 'type' => 'bool', 'group' => 'ads'],
        'ads_test_mode' => ['label' => 'Test mode (official test ads only)', 'type' => 'bool', 'group' => 'ads'],
        'levelplay_app_key' => ['label' => 'LevelPlay app key', 'type' => 'string', 'group' => 'ads', 'max' => 64, 'nullable' => true],
        'ads_app_open_cooldown_minutes' => ['label' => 'App-open ad cooldown (minutes)', 'type' => 'int', 'group' => 'ads', 'min' => 1, 'max' => 1440],
        'ads_app_open_session_cap' => ['label' => 'App-open ads per session', 'type' => 'int', 'group' => 'ads', 'min' => 0, 'max' => 5],

        'ads_earning_enabled' => ['label' => 'Ads Earning', 'type' => 'bool', 'group' => 'ads_earning'],
        'ads_earning_paused' => ['label' => 'Pause (maintenance)', 'type' => 'bool', 'group' => 'ads_earning'],
        'ads_earning_user_toggle_allowed' => ['label' => 'Players can switch Ads Earning on and off', 'type' => 'bool', 'group' => 'ads_earning'],
        'ads_earning_min_deposit' => ['label' => 'Minimum successful deposits to be eligible (amount deposited)', 'type' => 'decimal', 'group' => 'ads_earning', 'min' => 0, 'max' => 100000],
        'ads_earning_business_share' => ['label' => 'Business share (%)', 'type' => 'percent', 'group' => 'ads_earning'],
        'ads_earning_user_share' => ['label' => 'Eligible players\' share (%)', 'type' => 'percent', 'group' => 'ads_earning'],
        'ads_earning_coins_per_inr' => ['label' => 'Coins per INR 1 of player reward value', 'type' => 'decimal', 'group' => 'ads_earning', 'min' => 0.01, 'max' => 1000],
        'ads_earning_daily_view_limit' => ['label' => 'Qualifying rewarded views per player per day', 'type' => 'int', 'group' => 'ads_earning', 'min' => 1, 'max' => 100],
        'ads_earning_min_payout_coins' => ['label' => 'Minimum allocation credited (coins)', 'type' => 'decimal', 'group' => 'ads_earning', 'min' => 0, 'max' => 10000],
        'ads_earning_settlement_day' => ['label' => 'Weekly settlement day (1 = Monday … 7 = Sunday)', 'type' => 'int', 'group' => 'ads_earning', 'min' => 1, 'max' => 7],
        'ads_earning_settlement_time' => ['label' => 'Settlement cutoff time', 'type' => 'time', 'group' => 'ads_earning'],
        'ads_earning_timezone' => ['label' => 'Settlement timezone', 'type' => 'timezone', 'group' => 'ads_earning'],
        'ads_earning_explanation' => ['label' => 'Explanation shown to players', 'type' => 'text', 'group' => 'ads_earning'],
        'ads_earning_admin_notes' => ['label' => 'Admin notes', 'type' => 'text', 'group' => 'ads_earning', 'nullable' => true],

        'daily_reward_enabled' => ['label' => 'Daily login reward', 'type' => 'bool', 'group' => 'daily_reward'],
        'daily_reward_requires_ad' => ['label' => 'Requires a completed rewarded ad', 'type' => 'bool', 'group' => 'daily_reward'],
        'daily_reward_min_deposit' => ['label' => 'Minimum successful deposits to be eligible (amount deposited)', 'type' => 'decimal', 'group' => 'daily_reward', 'min' => 0, 'max' => 100000],
        'daily_reward_min_coins' => ['label' => 'Minimum reward (coins)', 'type' => 'decimal', 'group' => 'daily_reward', 'min' => 0.01, 'max' => 10000],
        'daily_reward_max_coins' => ['label' => 'Maximum reward (coins)', 'type' => 'decimal', 'group' => 'daily_reward', 'min' => 0.01, 'max' => 10000],
        'daily_reward_title' => ['label' => 'Popup title', 'type' => 'string', 'group' => 'daily_reward', 'max' => 60],
        'daily_reward_description' => ['label' => 'Popup description', 'type' => 'text', 'group' => 'daily_reward'],
        'daily_reward_starts_on' => ['label' => 'Campaign starts on', 'type' => 'date', 'group' => 'daily_reward', 'nullable' => true],
        'daily_reward_ends_on' => ['label' => 'Campaign ends on', 'type' => 'date', 'group' => 'daily_reward', 'nullable' => true],
        'daily_reward_timezone' => ['label' => 'Daily reset timezone', 'type' => 'timezone', 'group' => 'daily_reward'],

        'tickets_default_free' => ['label' => 'Default rewarded-ad tickets for a new free tournament', 'type' => 'int', 'group' => 'tickets', 'min' => 0, 'max' => 10],
        'tickets_max_per_tournament' => ['label' => 'Most tickets a tournament may require', 'type' => 'int', 'group' => 'tickets', 'min' => 1, 'max' => 10],
    ];

    public function get(string $key): mixed
    {
        $this->assertKnownKey($key);

        return config("minimax.{$key}");
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return collect(array_keys(self::CATALOG))
            ->mapWithKeys(fn (string $key) => [$key => $this->get($key)])
            ->all();
    }

    /**
     * @return array<string, array{label: string, type: string, group?: string, min?: int|float, max?: int|float, nullable?: bool}>
     */
    public static function catalogFor(string $group): array
    {
        return array_filter(self::CATALOG, fn (array $spec) => ($spec['group'] ?? 'platform') === $group);
    }

    /**
     * @return array<string, mixed>
     */
    public function group(string $group): array
    {
        return collect(array_keys(self::catalogFor($group)))
            ->mapWithKeys(fn (string $key) => [$key => $this->get($key)])
            ->all();
    }

    /**
     * @throws ValidationException
     */
    public function set(string $key, mixed $value, StaffUser $actor): PlatformSetting
    {
        $this->assertKnownKey($key);

        $value = $this->normalize($key, $value);
        $previousValue = $this->get($key);

        $setting = PlatformSetting::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by_staff_id' => $actor->id],
        );

        config(["minimax.{$key}" => $value]);

        AuditLog::record('platform_settings.updated', $actor, $setting, [
            'key' => $key,
            'previous_value' => $previousValue,
            'new_value' => $value,
        ]);

        return $setting;
    }

    /**
     * Saves several settings together: every value is validated first (including the
     * rules that span settings, such as the two revenue shares adding up to 100), then
     * all of them are written in one transaction, so a page never saves half its form.
     * Only settings whose value actually changed are written and audit-logged.
     *
     * @param  array<string, mixed>  $values
     * @return list<string> the keys that changed
     *
     * @throws ValidationException
     */
    public function setMany(array $values, StaffUser $actor): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            $this->assertKnownKey($key);
            $normalized[$key] = $this->normalize($key, $value);
        }

        $this->assertCrossFieldRules(array_merge($this->all(), $normalized));

        $changed = array_keys(array_filter($normalized, fn ($value, $key) => $value !== $this->get($key), ARRAY_FILTER_USE_BOTH));

        DB::transaction(function () use ($changed, $normalized, $actor) {
            foreach ($changed as $key) {
                $this->set($key, $normalized[$key], $actor);
            }
        });

        return $changed;
    }

    /**
     * Turns an incoming value into the canonical stored form for its type, or rejects it.
     *
     * @throws ValidationException
     */
    public function normalize(string $key, mixed $value): mixed
    {
        $spec = self::CATALOG[$key];
        $label = $spec['label'];
        $fail = fn (string $message) => throw ValidationException::withMessages([$key => $message]);

        if (is_string($value)) {
            $value = trim($value);
        }

        if (($spec['nullable'] ?? false) && ($value === null || $value === '')) {
            return null;
        }

        switch ($spec['type']) {
            case 'int':
                if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                    $fail("\"{$label}\" must be a whole number.");
                }
                $value = (int) $value;
                if ($value < ($spec['min'] ?? 0) || (isset($spec['max']) && $value > $spec['max'])) {
                    $fail("\"{$label}\" must be between ".($spec['min'] ?? 0).' and '.($spec['max'] ?? 'any').'.');
                }

                return $value;

            case 'decimal':
            case 'percent':
                $min = $spec['min'] ?? 0;
                $max = $spec['type'] === 'percent' ? 100 : ($spec['max'] ?? null);
                if (! is_numeric($value)) {
                    $fail("\"{$label}\" must be a number.");
                }
                if (bccomp((string) $value, (string) $min, 2) < 0 || ($max !== null && bccomp((string) $value, (string) $max, 2) > 0)) {
                    $fail("\"{$label}\" must be between {$min} and ".($max ?? 'any').'.');
                }

                return number_format((float) $value, 2, '.', '');

            case 'bool':
                $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($bool === null) {
                    $fail("\"{$label}\" must be on or off.");
                }

                return $bool;

            case 'url':
                return $this->normalizeUrl((string) $value, $label, $key);

            case 'string':
            case 'text':
                $max = $spec['max'] ?? ($spec['type'] === 'text' ? 2000 : 191);
                if (! is_string($value) || $value === '') {
                    $fail("\"{$label}\" is required.");
                }
                if (mb_strlen($value) > $max) {
                    $fail("\"{$label}\" may be at most {$max} characters.");
                }

                return $value;

            case 'time':
                if (! is_string($value) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
                    $fail("\"{$label}\" must be a 24-hour time such as 06:00.");
                }

                return $value;

            case 'date':
                if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
                    $fail("\"{$label}\" must be a date (YYYY-MM-DD).");
                }

                return $value;

            case 'timezone':
                if (! is_string($value) || ! in_array($value, DateTimeZone::listIdentifiers(), true)) {
                    $fail("\"{$label}\" must be a valid timezone such as Asia/Kolkata.");
                }

                return $value;

            case 'string_list':
                return collect(is_array($value) ? $value : preg_split('/\r?\n/', (string) $value))
                    ->map(fn ($line) => trim((string) $line))->filter()->values()->all();
        }

        return $value;
    }

    /**
     * Only https web links and mailto: addresses are accepted — no javascript:, data:,
     * intent:, file: or plain http — so the app never opens an unsafe or downgradable target.
     *
     * @throws ValidationException
     */
    private function normalizeUrl(string $value, string $label, string $key): string
    {
        $fail = fn (string $message) => throw ValidationException::withMessages([$key => $message]);

        if (mb_strlen($value) > 2048 || preg_match('/\s/', $value)) {
            $fail("\"{$label}\" must be a single link without spaces.");
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        if ($scheme === 'https') {
            $host = parse_url($value, PHP_URL_HOST);
            if (! is_string($host) || ! str_contains($host, '.') || filter_var($value, FILTER_VALIDATE_URL) === false) {
                $fail("\"{$label}\" must be a full https:// link, for example https://help.example.com.");
            }

            return $value;
        }

        if ($scheme === 'mailto') {
            $address = substr($value, 7);
            $address = explode('?', $address, 2)[0];
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                $fail("\"{$label}\" must be a valid email address after mailto:.");
            }

            return $value;
        }

        $fail("\"{$label}\" must start with https:// (or mailto: for an email address).");
    }

    /**
     * @param  array<string, mixed>  $merged  every setting's value as it would be after the save
     *
     * @throws ValidationException
     */
    private function assertCrossFieldRules(array $merged): void
    {
        if (bccomp(bcadd((string) $merged['ads_earning_business_share'], (string) $merged['ads_earning_user_share'], 2), '100.00', 2) !== 0) {
            throw ValidationException::withMessages([
                'ads_earning_user_share' => 'The business share and the players\' share must add up to exactly 100%.',
            ]);
        }

        if (bccomp((string) $merged['daily_reward_min_coins'], (string) $merged['daily_reward_max_coins'], 2) > 0) {
            throw ValidationException::withMessages([
                'daily_reward_max_coins' => 'The maximum daily reward must be at least the minimum.',
            ]);
        }

        if ($merged['daily_reward_starts_on'] && $merged['daily_reward_ends_on'] && $merged['daily_reward_starts_on'] > $merged['daily_reward_ends_on']) {
            throw ValidationException::withMessages([
                'daily_reward_ends_on' => 'The campaign must end on or after the day it starts.',
            ]);
        }

        if ((int) $merged['tickets_default_free'] > (int) $merged['tickets_max_per_tournament']) {
            throw ValidationException::withMessages([
                'tickets_default_free' => 'The default ticket count cannot be more than the maximum.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertKnownKey(string $key): void
    {
        if (! isset(self::CATALOG[$key])) {
            throw ValidationException::withMessages([
                'key' => "Unknown platform setting \"{$key}\".",
            ]);
        }
    }
}
