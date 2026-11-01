<?php

namespace App\Domain\Tokens;

use App\Domain\Ads\AdsConfig;
use App\Domain\Wallet\WalletService;
use App\Models\RewardedClaim;
use App\Models\TokenExchangeTransaction;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tokens-for-coins exchange, in the 10 fixed tiers the product spec sets (base rate 50
 * tokens = 1 coin for the first four tiers; tiers 5-10 add a flat +5% bonus — every figure
 * below is the spec's own number, not recomputed, so there's no risk of a rounding drift
 * between what's documented and what's credited). Every tier already lands on a whole coin
 * number; the exchange never credits a fraction.
 *
 * One verified rewarded ad gates one exchange, exactly like a spin: the app opens a claim
 * for purpose 'token_exchange' with the chosen tier, and only RewardedClaimService's
 * verified callback calls exchange() — the app can never trigger a debit/credit pair itself.
 */
class TokenExchangeService
{
    /**
     * @var list<array{tokens: int, coins: string, bonus_percent: string}>
     */
    public const array TIERS = [
        ['tokens' => 50, 'coins' => '1.00', 'bonus_percent' => '0.00'],
        ['tokens' => 100, 'coins' => '2.00', 'bonus_percent' => '0.00'],
        ['tokens' => 250, 'coins' => '5.00', 'bonus_percent' => '0.00'],
        ['tokens' => 500, 'coins' => '10.00', 'bonus_percent' => '0.00'],
        ['tokens' => 1000, 'coins' => '21.00', 'bonus_percent' => '5.00'],
        ['tokens' => 2000, 'coins' => '42.00', 'bonus_percent' => '5.00'],
        ['tokens' => 3000, 'coins' => '63.00', 'bonus_percent' => '5.00'],
        ['tokens' => 5000, 'coins' => '105.00', 'bonus_percent' => '5.00'],
        ['tokens' => 10000, 'coins' => '210.00', 'bonus_percent' => '5.00'],
        ['tokens' => 20000, 'coins' => '420.00', 'bonus_percent' => '5.00'],
    ];

    public function __construct(
        private readonly AdsConfig $ads,
        private readonly TokenService $tokens,
        private readonly WalletService $wallet,
    ) {}

    /**
     * @return list<array{index: int, tokens: int, coins: string, bonus_percent: string}>
     */
    public function tiers(): array
    {
        return array_map(
            fn (int $index, array $tier) => ['index' => $index, ...$tier],
            array_keys(self::TIERS),
            self::TIERS,
        );
    }

    /**
     * @throws ValidationException
     */
    public function assertValidTier(int $tierIndex): array
    {
        if (! isset(self::TIERS[$tierIndex])) {
            throw ValidationException::withMessages(['tier' => 'Choose a valid exchange option.']);
        }

        return self::TIERS[$tierIndex];
    }

    /**
     * @throws ValidationException
     */
    public function assertCanOpen(User $user, int $tierIndex): void
    {
        if (! $this->ads->isPurposeActive('token_exchange')) {
            throw ValidationException::withMessages(['ads' => 'Rewarded ads aren\'t available right now. Try again later.']);
        }

        $tier = $this->assertValidTier($tierIndex);
        $balance = $this->tokens->getOrCreateBalance($user)->balance;

        if ($balance < $tier['tokens']) {
            throw ValidationException::withMessages(['tokens' => "You need {$tier['tokens']} tokens for this exchange; you have {$balance}."]);
        }
    }

    /**
     * Debits the tokens and credits the coins to the existing Deposit wallet, atomically, for
     * a just-verified claim. Idempotent on the claim id. Re-checks the token balance inside
     * the lock (it only increases between open() and here via more spins, so this can only
     * ever find MORE available, never less — but it is still re-verified, never assumed from
     * the pre-check) and fails the exchange (never partially) if the tier has somehow become
     * invalid.
     */
    public function exchange(RewardedClaim $claim, int $tierIndex): TokenExchangeTransaction
    {
        try {
            return DB::transaction(function () use ($claim, $tierIndex) {
                if ($existing = TokenExchangeTransaction::where('rewarded_claim_id', $claim->id)->first()) {
                    return $existing;
                }

                if (! isset(self::TIERS[$tierIndex])) {
                    return TokenExchangeTransaction::create([
                        'user_id' => $claim->user_id,
                        'rewarded_claim_id' => $claim->id,
                        'tier_index' => $tierIndex,
                        'tokens_required' => 0,
                        'coins_received' => '0.00',
                        'bonus_percent' => '0.00',
                        'status' => 'failed',
                        'failure_reason' => 'invalid_tier',
                    ]);
                }

                $tier = self::TIERS[$tierIndex];
                $user = $claim->user;

                try {
                    $tokenEntry = $this->tokens->debit(
                        $user,
                        $tier['tokens'],
                        'exchange_debit',
                        "exchange:{$claim->id}",
                        $claim,
                        "Exchanged for {$tier['coins']} coins",
                    );
                } catch (InsufficientTokenBalanceException) {
                    return TokenExchangeTransaction::create([
                        'user_id' => $user->id,
                        'rewarded_claim_id' => $claim->id,
                        'tier_index' => $tierIndex,
                        'tokens_required' => $tier['tokens'],
                        'coins_received' => '0.00',
                        'bonus_percent' => $tier['bonus_percent'],
                        'status' => 'failed',
                        'failure_reason' => 'insufficient_tokens',
                    ]);
                }

                $walletEntry = $this->wallet->creditReward(
                    $user,
                    $tier['coins'],
                    'ad_reward',
                    "exchange_credit:{$claim->id}",
                    $claim,
                    "Token exchange: {$tier['tokens']} tokens -> {$tier['coins']} coins",
                );

                return TokenExchangeTransaction::create([
                    'user_id' => $user->id,
                    'rewarded_claim_id' => $claim->id,
                    'tier_index' => $tierIndex,
                    'tokens_required' => $tier['tokens'],
                    'coins_received' => $tier['coins'],
                    'bonus_percent' => $tier['bonus_percent'],
                    'status' => 'credited',
                    'token_ledger_entry_id' => $tokenEntry->id,
                    'wallet_ledger_entry_id' => $walletEntry->id,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return TokenExchangeTransaction::where('rewarded_claim_id', $claim->id)->firstOrFail();
        }
    }

    /**
     * @return LengthAwarePaginator<int, TokenExchangeTransaction>
     */
    public function history(User $user, int $perPage = 20)
    {
        return TokenExchangeTransaction::where('user_id', $user->id)->latest('id')->paginate($perPage);
    }
}
