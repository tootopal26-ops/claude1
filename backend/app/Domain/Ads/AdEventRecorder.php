<?php

namespace App\Domain\Ads;

use App\Models\AdEvent;
use App\Models\AdUnit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stores the app's ad telemetry (SDK init, requests, loads, no-fills, impressions, errors,
 * and the SDK's own revenue estimate). It is unverified by nature — anything a client sends
 * can be forged — so it feeds fill-rate, error and estimated-revenue reports only. Rewards
 * and settlements never read it.
 */
class AdEventRecorder
{
    public const array EVENTS = ['init_success', 'init_failed', 'requested', 'loaded', 'no_fill', 'load_failed', 'impression', 'display_failed', 'clicked', 'closed', 'rewarded_client'];

    /** How long raw telemetry is kept (reports are built from it; nothing else needs it). */
    public const int RETENTION_DAYS = 90;

    /**
     * @param  list<array<string, mixed>>  $events  already validated by the request
     */
    public function record(?User $user, array $events): int
    {
        $now = CarbonImmutable::now();
        $rows = [];

        foreach ($events as $event) {
            $occurredAt = isset($event['occurred_at']) ? CarbonImmutable::parse($event['occurred_at']) : $now;

            // A client clock far off is replaced by the server's time; reports stay sane.
            if ($occurredAt->greaterThan($now->addMinutes(5)) || $occurredAt->lessThan($now->subDays(2))) {
                $occurredAt = $now;
            }

            $rows[] = [
                'user_id' => $user?->id,
                'placement_key' => $event['placement'],
                'event' => $event['event'],
                'ad_network' => isset($event['ad_network']) ? mb_substr((string) $event['ad_network'], 0, 60) : null,
                'ad_unit_id' => isset($event['ad_unit_id']) ? mb_substr((string) $event['ad_unit_id'], 0, 120) : null,
                'revenue_usd' => isset($event['revenue_usd']) && is_numeric($event['revenue_usd']) && $event['revenue_usd'] >= 0 && $event['revenue_usd'] < 100 ? $event['revenue_usd'] : null,
                'revenue_precision' => isset($event['precision']) ? mb_substr((string) $event['precision'], 0, 30) : null,
                'error_code' => isset($event['error_code']) ? mb_substr((string) $event['error_code'], 0, 40) : null,
                // Test while the server is in test mode, whatever the app says: a test ad is never production revenue.
                'is_test' => (bool) config('minimax.ads_test_mode') || (bool) ($event['is_test'] ?? false),
                'occurred_at' => $occurredAt,
                'created_at' => $now,
            ];
        }

        DB::table('ad_events')->insert($rows);
        $this->touchAdUnits($rows);

        return count($rows);
    }

    /**
     * Keeps each ad unit's "last loaded" and "last error" current for the Ad units page
     * (as the app reports them — status information, never used for money).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function touchAdUnits(array $rows): void
    {
        foreach (collect($rows)->whereNotNull('ad_unit_id')->groupBy('ad_unit_id') as $unitId => $events) {
            $loaded = $events->where('event', 'loaded')->max('occurred_at');
            $failure = $events->whereIn('event', ['no_fill', 'load_failed', 'display_failed'])->sortBy('occurred_at')->last();

            $changes = array_filter([
                'last_loaded_at' => $loaded,
                'last_error_at' => $failure['occurred_at'] ?? null,
                'last_error' => $failure ? mb_substr(str_replace('_', ' ', $failure['event']).($failure['error_code'] ? " (code {$failure['error_code']})" : ''), 0, 255) : null,
            ]);

            if ($changes !== []) {
                AdUnit::where('levelplay_ad_unit_id', (string) $unitId)->update($changes);
            }
        }
    }

    public function prune(): int
    {
        return AdEvent::where('occurred_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();
    }
}
