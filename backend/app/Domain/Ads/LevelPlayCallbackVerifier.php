<?php

namespace App\Domain\Ads;

use Carbon\CarbonImmutable;

/**
 * Checks a LevelPlay (ironSource) rewarded server-to-server callback.
 *
 * LevelPlay calls the URL configured in its dashboard after a player finishes a rewarded
 * ad, with the player ID (here: the claim token the app passed to LevelPlay.setDynamicUserId),
 * the reward, a unique event ID, a timestamp and — when a private key is set in the
 * dashboard — a signature. The signature is the MD5 hex digest of
 *
 *     timestamp . eventId . appUserId . rewards . privateKey
 *
 * and a callback is only accepted when that digest matches. The private key lives only in
 * the server environment (LEVELPLAY_S2S_PRIVATE_KEY); without it every callback is refused.
 *
 * IMPORTANT before going live: this scheme follows ironSource's documented server-to-server
 * callback format, but the documentation could not be reached from the environment this was
 * built in. Use the dashboard's "send test callback" to confirm the parameter names and the
 * signature order match (see docs/ads-monetization.md). If they differ, callbacks are
 * refused — they are never accepted by mistake.
 */
class LevelPlayCallbackVerifier
{
    /** Oldest callback timestamp accepted (LevelPlay retries failed deliveries for a while). */
    public const int MAX_AGE_HOURS = 48;

    public function isConfigured(): bool
    {
        return is_string(config('minimax.levelplay_s2s_private_key')) && config('minimax.levelplay_s2s_private_key') !== '';
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{valid: bool, reason: ?string, event_id: ?string, user_id: ?string, rewards: ?string}
     */
    public function verify(array $params): array
    {
        $eventId = $this->stringParam($params, 'eventId');
        $userId = $this->stringParam($params, 'appUserId');
        $rewards = $this->stringParam($params, 'rewards');
        $timestamp = $this->stringParam($params, 'timestamp');
        $signature = $this->stringParam($params, 'signature');

        $result = fn (bool $valid, ?string $reason) => [
            'valid' => $valid,
            'reason' => $reason,
            'event_id' => $eventId,
            'user_id' => $userId,
            'rewards' => $rewards,
        ];

        if (! $this->isConfigured()) {
            return $result(false, 'not_configured');
        }

        if ($eventId === null || $userId === null || $rewards === null || $timestamp === null || $signature === null) {
            return $result(false, 'missing_parameters');
        }

        if (strlen($eventId) > 120 || ! preg_match('/^[A-Za-z0-9._:-]+$/', $eventId)) {
            return $result(false, 'malformed_event_id');
        }

        $expected = md5($timestamp.$eventId.$userId.$rewards.config('minimax.levelplay_s2s_private_key'));

        if (! hash_equals($expected, strtolower($signature))) {
            return $result(false, 'bad_signature');
        }

        if ($this->isStale($timestamp)) {
            return $result(false, 'stale_timestamp');
        }

        return $result(true, null);
    }

    /**
     * ironSource timestamps are UTC in YYYYMMDDHHmm form. A timestamp in another form is not
     * treated as stale on its own — the signature and the one-reward-per-event rule still apply.
     */
    private function isStale(string $timestamp): bool
    {
        if (! preg_match('/^\d{12}$/', $timestamp)) {
            return false;
        }

        $sentAt = CarbonImmutable::createFromFormat('YmdHi', $timestamp, 'UTC');

        return $sentAt !== null && $sentAt->lessThan(now()->subHours(self::MAX_AGE_HOURS));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function stringParam(array $params, string $key): ?string
    {
        $value = $params[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
