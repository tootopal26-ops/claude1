<?php

namespace App\Domain\Security;

use App\Models\LoginAttempt;
use App\Models\RiskAlert;

/**
 * Records every login attempt (success or failure), and raises a
 * repeated_failed_login risk alert the moment an identifier+IP pair
 * crosses FAILURE_THRESHOLD failures within WINDOW_MINUTES — exactly
 * once per crossing (checking for an exact count match, not >=), so a
 * sustained attack raises one alert, not one per subsequent attempt.
 *
 * The identifier passed in must already be the canonical form
 * (LoginIdentifier::canonicalize()) — the caller resolves it once and
 * reuses it everywhere, so a player who varies how they type the same
 * mobile number (or the letter case of their email/username) is still
 * grouped as one target, not several.
 *
 * This is deliberately a different signal than the 'login' RateLimiter
 * (AppServiceProvider), which only throttles 5/minute: an attacker who
 * spaces failures out to stay under that cap (e.g. one every 2 minutes)
 * would never be blocked by it, but still accumulates failures here.
 */
class RecordLoginAttemptAction
{
    private const int FAILURE_THRESHOLD = 5;

    private const int WINDOW_MINUTES = 15;

    public function __construct(private readonly ResolveUserByIdentifierAction $resolveUser) {}

    public function execute(string $identifier, string $ipAddress, bool $successful): LoginAttempt
    {
        $attempt = LoginAttempt::create([
            'identifier' => $identifier,
            'ip_address' => $ipAddress,
            'successful' => $successful,
        ]);

        if (! $successful) {
            $this->maybeRaiseRiskAlert($identifier, $ipAddress);
        }

        return $attempt;
    }

    private function maybeRaiseRiskAlert(string $identifier, string $ipAddress): void
    {
        $recentFailures = LoginAttempt::where('identifier', $identifier)
            ->where('ip_address', $ipAddress)
            ->where('successful', false)
            ->where('created_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->count();

        if ($recentFailures !== self::FAILURE_THRESHOLD) {
            return;
        }

        $user = $this->resolveUser->execute($identifier);

        $details = [
            'identifier' => $identifier,
            'ip_address' => $ipAddress,
            'recent_failure_count' => $recentFailures,
            'window_minutes' => self::WINDOW_MINUTES,
        ];

        // Kept alongside "identifier" (not instead of it) so an existing alert
        // screen, export, or saved search that reads details.mobile still
        // finds it — only possible when the identifier actually resolved to
        // a user, since an unresolved identifier might not be a mobile number
        // at all.
        if ($user !== null) {
            $details['mobile'] = $user->mobile;
        }

        RiskAlert::create([
            'type' => 'repeated_failed_login',
            'risk_level' => 'high',
            'target_user_id' => $user?->id,
            'subject_type' => $user?->getMorphClass(),
            'subject_id' => $user?->id,
            'details' => $details,
        ]);
    }
}
