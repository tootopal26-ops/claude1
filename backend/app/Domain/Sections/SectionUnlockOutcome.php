<?php

namespace App\Domain\Sections;

/**
 * What happened to one unlock request. `result` is the stable slug the API
 * returns; the messages are what the player sees.
 */
final class SectionUnlockOutcome
{
    public const MESSAGES = [
        'success' => 'Unlocked.',
        'already_unlocked' => "You've already unlocked this section.",
        'unknown_section' => "That section isn't available.",
        'not_for_sale' => "This section isn't locked right now.",
        'price_changed' => 'The price changed. Review it and confirm again.',
        'insufficient_balance' => "You don't have enough coins to unlock this section.",
        'ineligible' => "This account can't unlock sections.",
    ];

    public function __construct(
        public readonly string $result,
        public readonly ?string $key = null,
        public readonly int $price = 0,
        public readonly string $charged = '0.00',
        public readonly string $available = '0.00',
    ) {}

    public function succeeded(): bool
    {
        return $this->result === 'success' || $this->result === 'already_unlocked';
    }

    public function message(): string
    {
        return self::MESSAGES[$this->result] ?? 'This section could not be unlocked.';
    }
}
