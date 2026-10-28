<?php

namespace App\Domain\Redeem;

/**
 * What happened to one redeem attempt. `result` is the stable slug the API and
 * the attempt history use; messages are what the player sees.
 */
final class RedeemOutcome
{
    public const MESSAGES = [
        'success' => 'Code redeemed.',
        'invalid_format' => 'Enter the 5-character code exactly as shown.',
        'invalid_code' => "That code isn't valid. Check it and try again.",
        'inactive' => "This code isn't active right now.",
        'expired' => 'This code has expired.',
        'limit_reached' => 'This code has been fully redeemed.',
        'already_redeemed' => "You've already redeemed this code.",
        'ineligible' => "This account can't redeem codes.",
        'ad_required' => 'Watch a short ad to receive this reward.',
        'ads_unavailable' => "This code needs a short ad, and ads aren't available right now. Try again later.",
    ];

    public function __construct(
        public readonly string $result,
        public readonly int $coins = 0,
        public readonly ?int $campaignId = null,
        /** For `ad_required`: the rewarded claim to complete, and the ID to give the ad SDK. */
        public readonly ?string $claimToken = null,
        public readonly ?string $dynamicUserId = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->result === 'success';
    }

    public function message(): string
    {
        return self::MESSAGES[$this->result] ?? 'This code could not be redeemed.';
    }
}
