<?php

namespace Tests\Feature\Ads;

use App\Models\AdPlacement;
use App\Models\AdUnit;
use App\Models\DepositOrder;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Shared setup for the Ads tests: switches ads on with a real-looking configuration, adds
 * provider-confirmed deposits, and sends LevelPlay callbacks signed the way the verifier
 * expects (MD5 of timestamp . eventId . appUserId . rewards . privateKey).
 */
trait ConfiguresAds
{
    protected string $levelPlayKey = 'test-private-key-123';

    protected function enableAds(array $placements = ['tournament_ticket', 'daily_login', 'ads_earning', 'redeem_verification', 'rewarded_general', 'withdrawal_success']): void
    {
        config([
            'minimax.ads_master_enabled' => true,
            'minimax.ads_rewarded_enabled' => true,
            'minimax.levelplay_app_key' => 'appkey123',
            'minimax.levelplay_s2s_private_key' => $this->levelPlayKey,
        ]);

        $rewarded = AdUnit::create(['name' => 'Rewarded', 'format' => 'rewarded', 'environment' => 'production', 'levelplay_ad_unit_id' => 'rw-unit-1']);
        $interstitial = AdUnit::create(['name' => 'Interstitial', 'format' => 'interstitial', 'environment' => 'production', 'levelplay_ad_unit_id' => 'is-unit-1']);

        foreach ($placements as $key) {
            $placement = AdPlacement::where('key', $key)->firstOrFail();
            $placement->update(['is_enabled' => true, 'ad_unit_id' => $placement->format === 'rewarded' ? $rewarded->id : $interstitial->id]);
        }
    }

    protected function deposit(User $user, string $amount, string $status = 'success'): DepositOrder
    {
        return DepositOrder::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'our_order_id' => 'T'.Str::random(12),
            'provider' => 'alpha_upi',
            'status' => $status,
            'paid_at' => $status === 'success' ? now() : null,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function signedCallback(string $claimToken, string $eventId, ?string $key = null, string $rewards = '1', ?string $timestamp = null): array
    {
        $timestamp ??= now()->utc()->format('YmdHi');
        $userId = 'mm'.$claimToken;

        return [
            'appUserId' => $userId,
            'rewards' => $rewards,
            'eventId' => $eventId,
            'itemName' => 'Coins',
            'timestamp' => $timestamp,
            'signature' => md5($timestamp.$eventId.$userId.$rewards.($key ?? $this->levelPlayKey)),
        ];
    }

    protected function sendCallback(array $params): TestResponse
    {
        return $this->get('/api/ads/levelplay/callback?'.http_build_query($params));
    }
}
