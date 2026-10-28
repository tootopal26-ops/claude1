<?php

namespace App\Domain\Redeem;

use App\Domain\Ads\RewardedClaimService;
use App\Domain\Wallet\WalletService;
use App\Models\RedeemAttempt;
use App\Models\RedeemCampaign;
use App\Models\RedeemRedemption;
use App\Models\RedeemReservation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
 *
 * A campaign can require a rewarded ad. Then a valid code does not credit anything yet:
 * it reserves one place in the campaign's capacity for this player (a reservation that
 * expires if abandoned, so the code is never consumed by opening the dialog or closing the
 * ad) and opens a rewarded claim. Only the ad network's verified callback for that claim
 * finalizes the redemption, through the same locked, unique, idempotent path as a normal
 * redemption. Live reservations count toward capacity, so a code can never be over-redeemed.
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

        if ($campaign->redeemed_count + $this->liveReservationsExcept($campaign, $user) >= $campaign->total_capacity) {
            return $reject('limit_reached');
        }

        if ($campaign->requires_rewarded_ad) {
            return $this->reserveForAd($campaign, $user);
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

    /**
     * Finalizes a reservation after its rewarded ad was verified (called by
     * RewardedClaimService inside its transaction). Credits at most once.
     *
     * @return array{credited: bool, coins?: int, ledger_entry_id?: int, reason?: string}
     */
    public function completeReservation(RedeemReservation $reservation): array
    {
        /** @var RedeemCampaign $campaign */
        $campaign = RedeemCampaign::query()->lockForUpdate()->findOrFail($reservation->redeem_campaign_id);
        /** @var RedeemReservation $reservation */
        $reservation = RedeemReservation::query()->lockForUpdate()->findOrFail($reservation->id);
        $user = $reservation->user;

        if ($reservation->status === 'completed' || RedeemRedemption::where('redeem_campaign_id', $campaign->id)->where('user_id', $user->id)->exists()) {
            return ['credited' => false, 'reason' => 'already_redeemed'];
        }

        // The place was held while the ad played; only if it lapsed and others filled the
        // campaign meanwhile can it no longer be honoured.
        if ($reservation->expires_at->isPast() && $campaign->redeemed_count + $this->liveReservationsExcept($campaign, $user) >= $campaign->total_capacity) {
            $reservation->update(['status' => 'expired']);

            return ['credited' => false, 'reason' => 'limit_reached'];
        }

        $redemption = RedeemRedemption::create([
            'redeem_campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'coins' => $campaign->reward_coins,
        ]);

        $campaign->increment('redeemed_count');

        $entry = $this->wallet->creditDeposit(
            $user,
            number_format($campaign->reward_coins, 2, '.', ''),
            "redeem:{$campaign->id}:{$user->id}",
            $campaign,
            "Redeem code {$campaign->code}",
        );

        $redemption->update(['wallet_ledger_entry_id' => $entry->id]);
        $reservation->update(['status' => 'completed']);

        RedeemAttempt::create([
            'user_id' => $user->id,
            'redeem_campaign_id' => $campaign->id,
            'submitted_code' => $campaign->code,
            'result' => 'success',
        ]);

        return ['credited' => true, 'coins' => $campaign->reward_coins, 'ledger_entry_id' => $entry->id];
    }

    /** Marks lapsed reservations so they stop holding capacity; returns how many. */
    public function expireReservations(): int
    {
        return RedeemReservation::where('status', 'reserved')->where('expires_at', '<', now())->update(['status' => 'expired']);
    }

    private function liveReservationsExcept(RedeemCampaign $campaign, User $user): int
    {
        return RedeemReservation::query()
            ->where('redeem_campaign_id', $campaign->id)
            ->where('user_id', '!=', $user->id)
            ->where('status', 'reserved')
            ->where('expires_at', '>', now())
            ->count();
    }

    private function reserveForAd(RedeemCampaign $campaign, User $user): RedeemOutcome
    {
        $reservation = RedeemReservation::updateOrCreate(
            ['redeem_campaign_id' => $campaign->id, 'user_id' => $user->id],
            ['status' => 'reserved', 'expires_at' => now()->addMinutes((int) config('minimax.rewarded_claim_ttl_minutes'))],
        );

        try {
            $claims = app(RewardedClaimService::class);
            $claim = $claims->open($user, 'redeem_code', ['reservation' => $reservation]);
        } catch (ValidationException) {
            $reservation->update(['status' => 'expired']);

            return new RedeemOutcome('ads_unavailable', campaignId: $campaign->id);
        }

        return new RedeemOutcome('ad_required', $campaign->reward_coins, $campaign->id, $claim->token, $claims->dynamicUserId($claim));
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
