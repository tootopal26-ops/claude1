<?php

namespace App\Domain\Redeem;

use App\Domain\Wallet\WalletService;
use App\Models\RedeemAttempt;
use App\Models\RedeemCampaign;
use App\Models\RedeemRedemption;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Redeems a code for a player: one transaction that locks the campaign, checks
 * every rule, records the redemption and credits the deposit wallet through the
 * ledger service — all of it or none of it.
 *
 * - The campaign row is locked for the whole transaction, so concurrent
 *   redemptions of one code are serialized and the capacity check is exact.
 * - (campaign, player) is unique in the database, so a player can never redeem
 *   a campaign twice even if the application check were bypassed.
 * - The reward comes from the stored campaign, never from the request.
 * - The ledger credit carries an idempotency key per (campaign, player).
 */
class RedeemCodeService
{
    public function __construct(private readonly WalletService $wallet) {}

    public function redeem(User $user, string $input, ?string $ipAddress = null): RedeemOutcome
    {
        $code = RedeemCodeGenerator::normalize($input);

        if ($code === null) {
            return $this->record($user, new RedeemOutcome('invalid_format'), mb_substr((string) preg_replace('/\s+/', '', strtoupper($input)), 0, 16), $ipAddress);
        }

        if ($user->is_banned) {
            return $this->record($user, new RedeemOutcome('ineligible'), $code, $ipAddress);
        }

        try {
            $outcome = DB::transaction(fn () => $this->attempt($user, $code));
        } catch (\Throwable $e) {
            // Nothing was credited or recorded: the transaction rolled back as a whole.
            $this->record($user, new RedeemOutcome('error'), $code, $ipAddress);

            throw $e;
        }

        return $this->record($user, $outcome, $code, $ipAddress);
    }

    private function attempt(User $user, string $code): RedeemOutcome
    {
        /** @var RedeemCampaign|null $campaign */
        $campaign = RedeemCampaign::query()->where('code', $code)->lockForUpdate()->first();

        if ($campaign === null) {
            return new RedeemOutcome('invalid_code');
        }

        $reject = fn (string $result) => new RedeemOutcome($result, campaignId: $campaign->id);

        if ($campaign->isArchived() || ! $campaign->is_active) {
            return $reject('inactive');
        }

        if ($campaign->hasExpired()) {
            return $reject('expired');
        }

        if (RedeemRedemption::where('redeem_campaign_id', $campaign->id)->where('user_id', $user->id)->exists()) {
            return $reject('already_redeemed');
        }

        if ($campaign->redeemed_count >= $campaign->total_capacity) {
            return $reject('limit_reached');
        }

        try {
            $redemption = RedeemRedemption::create([
                'redeem_campaign_id' => $campaign->id,
                'user_id' => $user->id,
                'coins' => $campaign->reward_coins,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $reject('already_redeemed');
        }

        $campaign->increment('redeemed_count');

        $entry = $this->wallet->creditDeposit(
            $user,
            number_format($campaign->reward_coins, 2, '.', ''),
            "redeem:{$campaign->id}:{$user->id}",
            $campaign,
            "Redeem code {$campaign->code}",
        );

        $redemption->update(['wallet_ledger_entry_id' => $entry->id]);

        return new RedeemOutcome('success', $campaign->reward_coins, $campaign->id);
    }

    private function record(User $user, RedeemOutcome $outcome, string $code, ?string $ipAddress): RedeemOutcome
    {
        RedeemAttempt::create([
            'user_id' => $user->id,
            'redeem_campaign_id' => $outcome->campaignId,
            'submitted_code' => $code,
            'result' => $outcome->result,
            'ip_address' => $ipAddress,
        ]);

        return $outcome;
    }
}
