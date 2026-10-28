<?php

namespace App\Domain\Ads;

use App\Domain\Redeem\RedeemCodeService;
use App\Domain\Wallet\WalletService;
use App\Models\AdCallbackLog;
use App\Models\AdRewardCampaign;
use App\Models\DailyRewardClaim;
use App\Models\RedeemReservation;
use App\Models\RewardedClaim;
use App\Models\Tournament;
use App\Models\TournamentAdTicket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every reward for watching an ad goes through here, in two steps:
 *
 *  1. open() — the app asks to start one rewarded ad for a purpose (a tournament ticket,
 *     today's daily reward, an Ads Earning view, a redeem code, a reward campaign). The
 *     server checks every rule for that purpose first and returns a claim token. The app
 *     gives LevelPlay that token as its dynamic user ID before showing the ad.
 *  2. complete() — only from LevelPlay's signed server-to-server callback (verifyCallback)
 *     carrying that token. Nothing the app sends can complete a claim: an ad that was
 *     loaded, shown, closed early or failed earns nothing.
 *
 * Completion is one locked transaction per claim: the claim must still be pending, the
 * provider's event ID is unique across all claims (a replayed or duplicated callback is
 * recognised and ignored), and each purpose's reward is applied exactly once — ledger
 * credits carry per-source idempotency keys, tickets are unique per claim.
 */
class RewardedClaimService
{
    /** Pending claims a player may open per hour, across purposes. */
    public const int MAX_CLAIMS_PER_HOUR = 30;

    /** How long after a claim's expiry a delayed callback is still honoured. */
    public const int CALLBACK_GRACE_MINUTES = 30;

    public const string DYNAMIC_USER_PREFIX = 'mm';

    public function __construct(
        private readonly AdsConfig $ads,
        private readonly TournamentTicketService $tickets,
        private readonly DailyRewardService $daily,
        private readonly AdsEarningService $earning,
        private readonly WalletService $wallet,
    ) {}

    /**
     * @param  array{tournament?: Tournament, campaign?: AdRewardCampaign, reservation?: RedeemReservation}  $context
     *
     * @throws ValidationException
     */
    public function open(User $user, string $purpose, array $context = [], ?string $ipAddress = null): RewardedClaim
    {
        if (! isset(AdsConfig::PURPOSE_PLACEMENTS[$purpose])) {
            throw ValidationException::withMessages(['purpose' => 'Unknown reward.']);
        }

        if (! $this->ads->isPurposeActive($purpose)) {
            throw ValidationException::withMessages(['ads' => 'Rewarded ads aren\'t available right now. Try again later.']);
        }

        if ($user->is_banned) {
            throw ValidationException::withMessages(['ads' => 'This account can\'t earn rewards.']);
        }

        $links = ['tournament_id' => null, 'daily_reward_claim_id' => null, 'redeem_reservation_id' => null, 'ad_reward_campaign_id' => null];

        switch ($purpose) {
            case 'tournament_ticket':
                $tournament = $context['tournament'] ?? throw ValidationException::withMessages(['tournament' => 'Choose a tournament.']);
                $this->tickets->assertCanEarn($tournament, $user);
                $links['tournament_id'] = $tournament->id;
                break;

            case 'daily_reward':
                $this->daily->assertCanClaim($user);
                $links['daily_reward_claim_id'] = $this->daily->reserveToday($user)->id;
                break;

            case 'ads_earning':
                $this->earning->assertCanWatch($user);
                break;

            case 'redeem_code':
                $reservation = $context['reservation'] ?? throw ValidationException::withMessages(['code' => 'Enter a code first.']);
                $links['redeem_reservation_id'] = $reservation->id;
                break;

            case 'campaign':
                $campaign = $context['campaign'] ?? throw ValidationException::withMessages(['campaign' => 'Choose a reward.']);
                $this->assertCampaignOpen($campaign, $user);
                $links['ad_reward_campaign_id'] = $campaign->id;
                break;
        }

        // An unexpired pending claim for the same thing is reused: a retried request (or a
        // reopened app) never stacks up claims, and a completed ad is never offered twice.
        $existing = RewardedClaim::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->where($links)
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $recent = RewardedClaim::where('user_id', $user->id)->where('created_at', '>=', now()->subHour())->count();
        if ($recent >= self::MAX_CLAIMS_PER_HOUR) {
            throw ValidationException::withMessages(['ads' => 'Too many ads started. Please wait a while and try again.']);
        }

        return RewardedClaim::create([
            ...$links,
            'token' => bin2hex(random_bytes(16)),
            'user_id' => $user->id,
            'purpose' => $purpose,
            'placement_key' => AdsConfig::PURPOSE_PLACEMENTS[$purpose],
            'status' => 'pending',
            'expires_at' => now()->addMinutes((int) config('minimax.rewarded_claim_ttl_minutes')),
            'ip_address' => $ipAddress,
        ]);
    }

    /** The value the app passes to LevelPlay.setDynamicUserId before showing the ad. */
    public function dynamicUserId(RewardedClaim $claim): string
    {
        return self::DYNAMIC_USER_PREFIX.$claim->token;
    }

    /**
     * Handles one LevelPlay server-to-server callback end to end and logs it.
     *
     * @param  array<string, mixed>  $params
     * @return array{outcome: string, event_id: ?string, accepted: bool}
     */
    public function verifyCallback(array $params, ?string $ipAddress): array
    {
        $check = app(LevelPlayCallbackVerifier::class)->verify($params);
        $payload = collect($params)->except(['signature'])->map(fn ($value) => is_scalar($value) ? mb_substr((string) $value, 0, 200) : null)->all();
        $log = fn (string $outcome, ?RewardedClaim $claim = null) => AdCallbackLog::create([
            'provider' => 'levelplay',
            'event_id' => $check['event_id'],
            'outcome' => $outcome,
            'signature_valid' => $check['valid'],
            'rewarded_claim_id' => $claim?->id,
            'payload' => $payload,
            'ip_address' => $ipAddress,
        ]);

        if (! $check['valid']) {
            $log($check['reason']);

            return ['outcome' => $check['reason'], 'event_id' => $check['event_id'], 'accepted' => false];
        }

        $token = $this->tokenFromDynamicUserId((string) $check['user_id']);
        $claim = $token ? RewardedClaim::where('token', $token)->first() : null;

        if ($claim === null) {
            $log('unknown_claim');

            return ['outcome' => 'unknown_claim', 'event_id' => $check['event_id'], 'accepted' => false];
        }

        $outcome = $this->complete($claim, 'levelplay_s2s', 'levelplay', (string) $check['event_id']);
        $log($outcome, $claim);

        return ['outcome' => $outcome, 'event_id' => $check['event_id'], 'accepted' => in_array($outcome, ['verified', 'duplicate'], true)];
    }

    /**
     * Applies a verified completion. Returns a slug: verified, duplicate, expired,
     * not_pending, or event_reused (this provider event already completed another claim).
     */
    public function complete(RewardedClaim $claim, string $method, string $provider, string $eventId): string
    {
        try {
            return DB::transaction(function () use ($claim, $method, $provider, $eventId) {
                /** @var RewardedClaim $locked */
                $locked = RewardedClaim::query()->lockForUpdate()->findOrFail($claim->id);

                if ($locked->status === 'verified') {
                    return $locked->provider === $provider && $locked->provider_event_id === $eventId ? 'duplicate' : 'not_pending';
                }

                if ($locked->status !== 'pending') {
                    return 'not_pending';
                }

                if (now()->greaterThan($locked->expires_at->copy()->addMinutes(self::CALLBACK_GRACE_MINUTES))) {
                    $locked->update(['status' => 'expired', 'failure_reason' => 'callback_after_expiry']);

                    return 'expired';
                }

                // Claim the provider event first: the unique index rejects a reused event ID.
                $locked->fill([
                    'provider' => $provider,
                    'provider_event_id' => $eventId,
                    'verification_method' => $method,
                    'verified_at' => now(),
                    'status' => 'verified',
                ])->save();

                $this->applyReward($locked);

                return 'verified';
            });
        } catch (UniqueConstraintViolationException) {
            return 'event_reused';
        }
    }

    /**
     * Local QA only: completes a claim as if the ad network had called back, so the flows can
     * be exercised without a real ad SDK. Refused in production whatever the configuration.
     */
    public function simulationAllowed(): bool
    {
        return (bool) config('minimax.ads_allow_simulated_rewards') && ! app()->isProduction();
    }

    public function simulateCompletion(RewardedClaim $claim): string
    {
        abort_unless($this->simulationAllowed(), 404);

        return $this->complete($claim, 'simulated', 'simulated', 'sim-'.$claim->token);
    }

    /** Marks claims whose callback window has passed; returns how many. */
    public function expireStale(): int
    {
        return RewardedClaim::query()
            ->where('status', 'pending')
            ->where('expires_at', '<', now()->subMinutes(self::CALLBACK_GRACE_MINUTES))
            ->update(['status' => 'expired', 'failure_reason' => 'no_callback']);
    }

    public function cancel(RewardedClaim $claim): void
    {
        RewardedClaim::where('id', $claim->id)->where('status', 'pending')->update(['status' => 'cancelled', 'failure_reason' => 'closed_by_player']);
    }

    public function tokenFromDynamicUserId(string $dynamicUserId): ?string
    {
        if (! str_starts_with($dynamicUserId, self::DYNAMIC_USER_PREFIX)) {
            return null;
        }

        $token = substr($dynamicUserId, strlen(self::DYNAMIC_USER_PREFIX));

        return preg_match('/^[0-9a-f]{32}$/', $token) ? $token : null;
    }

    /**
     * The reward for a just-verified claim, inside complete()'s transaction. A purpose whose
     * situation changed while the ad played (tournament closed, campaign budget used up)
     * keeps the claim verified but grants nothing, with the reason recorded.
     */
    private function applyReward(RewardedClaim $claim): void
    {
        $user = $claim->user;

        switch ($claim->purpose) {
            case 'tournament_ticket':
                /** @var Tournament $tournament */
                $tournament = Tournament::query()->lockForUpdate()->findOrFail($claim->tournament_id);
                if (! $tournament->isJoiningOpen()) {
                    $claim->update(['failure_reason' => 'tournament_closed']);
                } elseif ($this->tickets->remaining($tournament, $user) === 0) {
                    $claim->update(['failure_reason' => 'tickets_already_complete']);
                } else {
                    TournamentAdTicket::create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'rewarded_claim_id' => $claim->id]);
                }
                break;

            case 'daily_reward':
                /** @var DailyRewardClaim $dailyClaim */
                $dailyClaim = DailyRewardClaim::findOrFail($claim->daily_reward_claim_id);
                if ($this->daily->credit($dailyClaim)) {
                    $dailyClaim->refresh();
                    $claim->update(['reward_coins' => $dailyClaim->amount, 'wallet_ledger_entry_id' => $dailyClaim->wallet_ledger_entry_id]);
                } else {
                    $claim->update(['failure_reason' => 'already_claimed_today']);
                }
                break;

            case 'ads_earning':
                // Lock the preference row so two callbacks for the same player can't both
                // slip under the daily cap.
                DB::table('ads_earning_preferences')->where('user_id', $user->id)->lockForUpdate()->first();
                $claim->update(['counts_for_earning' => $this->earning->countsNow($user)]);
                break;

            case 'redeem_code':
                $reservation = RedeemReservation::findOrFail($claim->redeem_reservation_id);
                $result = app(RedeemCodeService::class)->completeReservation($reservation);
                $claim->update([
                    'reward_coins' => $result['coins'] ?? null,
                    'wallet_ledger_entry_id' => $result['ledger_entry_id'] ?? null,
                    'failure_reason' => $result['credited'] ? null : $result['reason'],
                ]);
                break;

            case 'campaign':
                $this->creditCampaign($claim, $user);
                break;
        }
    }

    /**
     * The fixed-reward campaigns a player can see right now (running, within dates), each with
     * whether another claim is possible today. Nothing here reserves or credits anything.
     *
     * @return list<array{id: int, name: string, reward_coins: string, daily_limit: int, available: bool, reason: ?string}>
     */
    public function campaignsFor(User $user): array
    {
        if (! $this->ads->isPurposeActive('campaign')) {
            return [];
        }

        return AdRewardCampaign::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderBy('id')
            ->get()
            ->map(function (AdRewardCampaign $campaign) use ($user) {
                $reason = $this->campaignRefusal($campaign, $user);

                return [
                    'id' => $campaign->id,
                    'name' => $campaign->name,
                    'reward_coins' => (string) $campaign->reward_coins,
                    'daily_limit' => (int) $campaign->daily_limit_per_user,
                    'available' => $reason === null,
                    'reason' => $reason,
                ];
            })
            ->values()
            ->all();
    }

    private function creditCampaign(RewardedClaim $claim, User $user): void
    {
        /** @var AdRewardCampaign $campaign */
        $campaign = AdRewardCampaign::query()->lockForUpdate()->findOrFail($claim->ad_reward_campaign_id);

        $reason = $this->campaignRefusal($campaign, $user, excludeClaimId: $claim->id);
        if ($reason !== null) {
            $claim->update(['failure_reason' => $reason]);

            return;
        }

        $entry = $this->wallet->creditReward(
            $user,
            (string) $campaign->reward_coins,
            'ad_campaign_reward',
            "ad_campaign:{$claim->id}",
            $claim,
            "Reward: {$campaign->name}",
        );

        $campaign->update(['credited_coins' => bcadd((string) $campaign->credited_coins, (string) $campaign->reward_coins, 2)]);
        $claim->update(['reward_coins' => $campaign->reward_coins, 'wallet_ledger_entry_id' => $entry->id]);
    }

    /**
     * @throws ValidationException
     */
    private function assertCampaignOpen(AdRewardCampaign $campaign, User $user): void
    {
        $reason = $this->campaignRefusal($campaign, $user);

        if ($reason !== null) {
            throw ValidationException::withMessages(['campaign' => match ($reason) {
                'daily_limit' => 'You\'ve reached today\'s limit for this reward.',
                'budget_spent' => 'This reward has run out.',
                default => 'This reward isn\'t available right now.',
            }]);
        }
    }

    private function campaignRefusal(AdRewardCampaign $campaign, User $user, ?int $excludeClaimId = null): ?string
    {
        if (! $campaign->is_active || ($campaign->starts_at && $campaign->starts_at->isFuture()) || ($campaign->ends_at && $campaign->ends_at->isPast())) {
            return 'campaign_inactive';
        }

        if ($campaign->budget_coins !== null && bccomp(bcadd((string) $campaign->credited_coins, (string) $campaign->reward_coins, 2), (string) $campaign->budget_coins, 2) > 0) {
            return 'budget_spent';
        }

        $todayStart = CarbonImmutable::now((string) config('minimax.ads_earning_timezone'))->startOfDay()->utc();
        $creditedToday = RewardedClaim::query()
            ->where('user_id', $user->id)
            ->where('ad_reward_campaign_id', $campaign->id)
            ->where('status', 'verified')
            ->whereNotNull('wallet_ledger_entry_id')
            ->where('verified_at', '>=', $todayStart)
            ->when($excludeClaimId, fn ($query) => $query->where('id', '!=', $excludeClaimId))
            ->count();

        return $creditedToday >= $campaign->daily_limit_per_user ? 'daily_limit' : null;
    }
}
