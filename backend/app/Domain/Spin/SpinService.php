<?php

namespace App\Domain\Spin;

use App\Domain\Ads\AdsConfig;
use App\Domain\Tokens\TokenService;
use App\Models\RewardedClaim;
use App\Models\SpinAttempt;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The spin wheel. Every spin's winning number is drawn here, by the server, the moment a
 * rewarded ad is verified (RewardedClaimService::applyReward, purpose 'spin') — never by
 * the client, which only animates the wheel to whatever number the server already decided.
 *
 * The draw is a weighted random selection over whole-percent integer weights (basis points,
 * so no floating-point rounding can bias it), using random_int — a CSPRNG, not the client
 * and not a predictable seed.
 */
class SpinService
{
    /** The wheel's 8 physical segments, clockwise from the pointer at the top (0°), each
     *  45° wide. The segment the app lands on for a given winning number is its choice
     *  (purely cosmetic, picked among the matching segments) — the reward itself never
     *  depends on which physical segment is shown. */
    public const array SEGMENTS = [
        ['number' => 1, 'angle' => 0],
        ['number' => 2, 'angle' => 45],
        ['number' => 3, 'angle' => 90],
        ['number' => 1, 'angle' => 135],
        ['number' => 4, 'angle' => 180],
        ['number' => 2, 'angle' => 225],
        ['number' => 1, 'angle' => 270],
        ['number' => 5, 'angle' => 315],
    ];

    public function __construct(
        private readonly AdsConfig $ads,
        private readonly TokenService $tokens,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('minimax.spin_enabled');
    }

    /** The configured odds, number => percent string ("80.00" … "2.00"), always summing to 100. */
    public function probabilities(): array
    {
        return [
            1 => (string) config('minimax.spin_prob_1'),
            2 => (string) config('minimax.spin_prob_2'),
            3 => (string) config('minimax.spin_prob_3'),
            4 => (string) config('minimax.spin_prob_4'),
            5 => (string) config('minimax.spin_prob_5'),
        ];
    }

    /**
     * @return array{enabled: bool, available: bool, ads_available: bool, balance: int, segments: list<array{number: int, angle: int}>}
     */
    public function status(User $user): array
    {
        $adReady = $this->ads->isPurposeActive('spin');

        return [
            'enabled' => $this->enabled(),
            'available' => $this->enabled() && $adReady,
            'ads_available' => $adReady,
            'balance' => $this->tokens->getOrCreateBalance($user)->balance,
            'segments' => self::SEGMENTS,
        ];
    }

    /**
     * @throws ValidationException
     */
    public function assertCanSpin(User $user): void
    {
        if (! $this->enabled()) {
            throw ValidationException::withMessages(['spin' => 'The spin wheel isn\'t running right now.']);
        }
        if (! $this->ads->isPurposeActive('spin')) {
            throw ValidationException::withMessages(['ads' => 'Rewarded ads aren\'t available right now. Try again later.']);
        }
    }

    /**
     * Draws the winning number using the weights in effect right now. random_int is a
     * cryptographically secure PRNG (not the Mersenne Twister rand()/mt_rand() use), so this
     * is not predictable or replayable by a client even if it knew the configured odds.
     *
     * @param  array<int, string>  $probabilities  number => percent string, must sum to 100.00
     */
    public function draw(array $probabilities): int
    {
        // Basis points (percent * 100) so the whole draw is exact integer arithmetic — no
        // float ever decides money-adjacent outcomes.
        $weights = [];
        $total = 0;
        foreach ($probabilities as $number => $percent) {
            $weight = (int) round(((float) $percent) * 100);
            $weights[$number] = $weight;
            $total += $weight;
        }

        if ($total <= 0) {
            throw new \RuntimeException('Spin probabilities are not configured.');
        }

        $roll = random_int(1, $total);
        $cumulative = 0;
        foreach ($weights as $number => $weight) {
            $cumulative += $weight;
            if ($roll <= $cumulative) {
                return $number;
            }
        }

        // Unreachable given the loop above sums to $total and $roll <= $total, but PHPStan
        // (and a future edit) shouldn't have to take that on faith.
        return array_key_last($weights);
    }

    /**
     * Credits a just-verified spin claim exactly once: draws the number, awards that many
     * tokens, and records the attempt. Idempotent on the claim id (its unique spin_attempts
     * row is the idempotency boundary — a duplicate callback finds the existing row and
     * credits nothing twice).
     */
    public function creditSpin(RewardedClaim $claim): SpinAttempt
    {
        if ($existing = SpinAttempt::where('rewarded_claim_id', $claim->id)->first()) {
            return $existing;
        }

        $probabilities = $this->probabilities();
        $number = $this->draw($probabilities);

        $entry = $this->tokens->credit(
            $claim->user,
            $number,
            'spin_reward',
            "spin:{$claim->id}",
            $claim,
            "Spin reward — rolled {$number}",
        );

        return SpinAttempt::create([
            'user_id' => $claim->user_id,
            'rewarded_claim_id' => $claim->id,
            'winning_number' => $number,
            'tokens_awarded' => $number,
            'probabilities_snapshot' => $probabilities,
            'token_ledger_entry_id' => $entry->id,
        ]);
    }
}
