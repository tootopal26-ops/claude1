<?php

namespace App\Domain\Settings;

use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\StaffUser;
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
 */
class PlatformSettingsService
{
    /**
     * @var array<string, array{label: string, type: 'int'|'decimal'|'string_list'}>
     */
    public const array CATALOG = [
        'min_age' => ['label' => 'Minimum registration age (years)', 'type' => 'int'],
        'dob_exclusion_window_days' => ['label' => 'DOB exclusion window (days)', 'type' => 'int'],
        'wallet_risk_alert_threshold' => ['label' => 'Wallet risk-alert threshold (coins)', 'type' => 'decimal'],
        'blocked_states' => ['label' => 'Blocked states/regions — one per line. Empty means none are blocked; this is a compliance call for the operator to make, not a default this platform ships with.', 'type' => 'string_list'],
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
     * @throws ValidationException
     */
    public function set(string $key, mixed $value, StaffUser $actor): PlatformSetting
    {
        $this->assertKnownKey($key);

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
