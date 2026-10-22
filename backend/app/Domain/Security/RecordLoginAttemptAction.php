<?php

namespace App\Domain\Security;

use App\Models\LoginAttempt;
use App\Models\RiskAlert;
use App\Models\User;

/**
 * Records every login attempt (success or failure), and raises a
 * repeated_failed_login risk alert the moment a mobile+IP pair crosses
 * FAILURE_THRESHOLD failures within WINDOW_MINUTES — exactly once per
 * crossing (checking for an exact count match, not >=), so a sustained
 * attack raises one alert, not one per subsequent attempt.
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

    public function execute(string $mobile, string $ipAddress, bool $successful): LoginAttempt
    {
        $attempt = LoginAttempt::create([
            'mobile' => $mobile,
            'ip_address' => $ipAddress,
            'successful' => $successful,
        ]);

        if (! $successful) {
            $this->maybeRaiseRiskAlert($mobile, $ipAddress);
        }

        return $attempt;
    }

    private function maybeRaiseRiskAlert(string $mobile, string $ipAddress): void
    {
        $recentFailures = LoginAttempt::where('mobile', $mobile)
            ->where('ip_address', $ipAddress)
            ->where('successful', false)
            ->where('created_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->count();

        if ($recentFailures !== self::FAILURE_THRESHOLD) {
            return;
        }

        $user = User::where('mobile', $mobile)->first();

        RiskAlert::create([
            'type' => 'repeated_failed_login',
            'risk_level' => 'high',
            'target_user_id' => $user?->id,
            'subject_type' => $user?->getMorphClass(),
            'subject_id' => $user?->id,
            'details' => [
                'mobile' => $mobile,
                'ip_address' => $ipAddress,
                'recent_failure_count' => $recentFailures,
                'window_minutes' => self::WINDOW_MINUTES,
            ],
        ]);
    }
}
