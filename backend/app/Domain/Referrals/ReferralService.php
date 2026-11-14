<?php

namespace App\Domain\Referrals;

use App\Domain\Settings\PlatformSettingsService;
use App\Domain\Wallet\WalletService;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The two-sided referral reward: a new player who enters a valid code at signup, and the
 * existing player whose code it was. Both reward amounts are admin-configured
 * (PlatformSettingsService group 'referral') and read fresh, inside the crediting
 * transaction, every time — never trusted from the client, never hardcoded.
 *
 * Every referral code is generated for every user at account creation regardless of
 * whether this system is enabled (see User::booted()) — a harmless account attribute.
 * This service only ever decides whether a SIGNUP should turn into a credited Referral
 * row; it never changes a code or a `referred_by_user_id` once set.
 */
class ReferralService
{
    public function __construct(
        private readonly PlatformSettingsService $settings,
        private readonly WalletService $wallet,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('referral_enabled');
    }

    /**
     * Resolves a referral code to its owner, or throws. Does not check eligibility caps —
     * see assertEligible() — because the same "invalid code" message is used for both, by
     * design (never tell a prober whether a code failed for existing-vs-capped reasons).
     *
     * @throws ValidationException
     */
    public function assertValidCode(string $code): User
    {
        $referrer = User::where('referral_code', $code)->first();

        if ($referrer === null) {
            throw ValidationException::withMessages(['referral_code' => "This referral code isn't valid."]);
        }

        return $referrer;
    }

    /**
     * @throws ValidationException
     */
    private function assertEligible(User $referrer): void
    {
        $max = $this->settings->get('referral_max_per_referrer');

        // A plain count() is an ordinary consistent read: under MySQL's default
        // REPEATABLE READ, it would see the snapshot from this transaction's FIRST query
        // (assertValidCode's lookup), not the latest committed rows — so two concurrent
        // signups racing the same referrer could both read a stale under-the-cap count
        // despite the row lock above correctly serializing them one at a time. A locking
        // read (lockForUpdate) always reads the latest committed data regardless of the
        // transaction's snapshot, which is exactly what this check needs.
        if ($max !== null && $referrer->referralsMade()->lockForUpdate()->count() >= (int) $max) {
            // Same message as an unknown code — never reveal that a specific code exists
            // but has simply run out of uses.
            throw ValidationException::withMessages(['referral_code' => "This referral code isn't valid."]);
        }
    }

    /**
     * Applies an optional referral code to a brand-new account. Safe to call with a null/
     * empty code (a no-op) or while the system is disabled (also a no-op — a submitted code
     * never blocks registration just because the feature happens to be off). An actually
     * invalid or ineligible code throws, so the caller's own transaction (registration)
     * rolls back and the player sees a clear, specific error rather than silently losing
     * their referral.
     *
     * Idempotent by construction: `referred_user_id` is unique in the `referrals` table and
     * `referred_by_user_id` is set once, on a brand-new user row, inside this same call —
     * there is no way to invoke this twice for the same new account.
     *
     * @throws ValidationException
     */
    public function applyReferral(User $newUser, ?string $code): ?Referral
    {
        $code = $code !== null ? trim($code) : null;

        if ($code === null || $code === '' || ! $this->enabled() || $newUser->referred_by_user_id !== null) {
            // The last check makes this safe to call more than once for the same account
            // (it never can be, in practice — referred_by_user_id is only ever set here, on
            // a brand-new user, inside registration's own transaction — but a no-op is the
            // correct behaviour for "already referred" rather than a unique-constraint crash).
            return null;
        }

        return DB::transaction(function () use ($newUser, $code) {
            $referrer = $this->assertValidCode($code);

            if ($referrer->id === $newUser->id) {
                // Not reachable through registration (the new account has no row, and so
                // no code, until after this call returns) — guarded anyway for any other
                // caller, with the same message an unknown code gets.
                throw ValidationException::withMessages(['referral_code' => "This referral code isn't valid."]);
            }

            // Locks the referrer's row for the rest of this transaction, so N concurrent
            // signups all using the same code can never all read the same
            // under-the-cap referralsMade() count before any of them commits — see
            // referral_max_per_referrer. The same discipline as WalletService's
            // lockForUpdate() on every balance-affecting write.
            $referrer = User::query()->lockForUpdate()->findOrFail($referrer->id);
            $this->assertEligible($referrer);

            $newPlayerReward = (string) $this->settings->get('referral_new_player_reward');
            $referrerReward = (string) $this->settings->get('referral_referrer_reward');

            $newPlayerEntry = bccomp($newPlayerReward, '0.00', 2) > 0
                ? $this->wallet->creditReward(
                    $newUser,
                    $newPlayerReward,
                    'referral_received',
                    "referral:new_player:{$newUser->id}",
                    null,
                    "Referral signup bonus (code {$code})",
                )
                : null;

            $referrerEntry = bccomp($referrerReward, '0.00', 2) > 0
                ? $this->wallet->creditReward(
                    $referrer,
                    $referrerReward,
                    'referral_given',
                    "referral:referrer:{$newUser->id}",
                    null,
                    'Referral reward for inviting a new player',
                )
                : null;

            $newUser->referred_by_user_id = $referrer->id;
            $newUser->save();

            return Referral::create([
                'referrer_user_id' => $referrer->id,
                'referred_user_id' => $newUser->id,
                'referral_code_used' => $code,
                'new_player_reward' => $newPlayerReward,
                'referrer_reward' => $referrerReward,
                'status' => 'completed',
                'new_player_wallet_ledger_entry_id' => $newPlayerEntry?->id,
                'referrer_wallet_ledger_entry_id' => $referrerEntry?->id,
            ]);
        });
    }

    /**
     * @return array{referral_code: string, successful_referrals: int, total_coins_earned: string}
     */
    public function stats(User $user): array
    {
        return [
            'referral_code' => $user->referral_code,
            'successful_referrals' => $user->referralsMade()->count(),
            'total_coins_earned' => (string) $user->referralsMade()->sum('referrer_reward'),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, Referral>
     */
    public function history(User $user, int $perPage = 20)
    {
        return $user->referralsMade()->with('referred')->latest('id')->paginate($perPage);
    }
}
