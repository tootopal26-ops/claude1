<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ads\AdEventRecorder;
use App\Domain\Ads\AdsConfig;
use App\Domain\Ads\AdsEarningService;
use App\Domain\Ads\RewardedClaimService;
use App\Domain\Ads\TournamentTicketService;
use App\Domain\Ads\WithdrawalAdService;
use App\Http\Controllers\Controller;
use App\Models\AdRewardCampaign;
use App\Models\RewardedClaim;
use App\Models\Tournament;
use App\Models\WithdrawalRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The app's ad endpoints. Configuration and claims only — there is deliberately no
 * endpoint that completes a claim or credits a reward: that happens solely through the ad
 * network's signed server-to-server callback (LevelPlayCallbackController).
 */
class AdsController extends Controller
{
    public function config(AdsConfig $ads, RewardedClaimService $claims): JsonResponse
    {
        return response()->json(['data' => [
            ...$ads->clientConfig(),
            'simulation_allowed' => $claims->simulationAllowed(),
        ]]);
    }

    public function events(Request $request, AdEventRecorder $recorder): JsonResponse
    {
        $validated = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:50'],
            'events.*.placement' => ['required', 'string', 'max:40', 'regex:/^[a-z_]+$/'],
            'events.*.event' => ['required', Rule::in(AdEventRecorder::EVENTS)],
            'events.*.ad_network' => ['nullable', 'string', 'max:60'],
            'events.*.ad_unit_id' => ['nullable', 'string', 'max:120'],
            'events.*.revenue_usd' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'events.*.precision' => ['nullable', 'string', 'max:30'],
            'events.*.error_code' => ['nullable', 'string', 'max:40'],
            'events.*.is_test' => ['nullable', 'boolean'],
            'events.*.occurred_at' => ['nullable', 'date'],
        ]);

        $count = $recorder->record($request->user(), $validated['events']);

        return response()->json(['data' => ['recorded' => $count]], 201);
    }

    public function openClaim(Request $request, RewardedClaimService $claims): JsonResponse
    {
        $validated = $request->validate([
            'purpose' => ['required', Rule::in(['tournament_ticket', 'daily_reward', 'ads_earning', 'campaign'])],
            'tournament_id' => ['required_if:purpose,tournament_ticket', 'nullable', 'integer'],
            'campaign_id' => ['required_if:purpose,campaign', 'nullable', 'integer'],
        ]);

        $context = [];
        if ($validated['purpose'] === 'tournament_ticket') {
            $context['tournament'] = Tournament::findOrFail($validated['tournament_id']);
        }
        if ($validated['purpose'] === 'campaign') {
            $context['campaign'] = AdRewardCampaign::findOrFail($validated['campaign_id']);
        }

        $claim = $claims->open($request->user(), $validated['purpose'], $context, $request->ip());

        return response()->json(['data' => $this->presentClaim($claim, $claims)], 201);
    }

    public function showClaim(Request $request, string $token, RewardedClaimService $claims): JsonResponse
    {
        $claim = $this->ownClaim($request, $token);

        return response()->json(['data' => $this->presentClaim($claim, $claims)]);
    }

    /** No ad could be shown (no fill, failure, no SDK): nothing is earned; the claim is closed. */
    public function cancelClaim(Request $request, string $token, RewardedClaimService $claims): JsonResponse
    {
        $claim = $this->ownClaim($request, $token);
        $claims->cancel($claim);

        return response()->json(['data' => $this->presentClaim($claim->fresh(), $claims)]);
    }

    /**
     * Local QA only (404 in production and whenever simulated rewards are off): completes a
     * claim as if the ad network had called back, so flows can be tested without an SDK.
     */
    public function simulateClaim(Request $request, string $token, RewardedClaimService $claims): JsonResponse
    {
        abort_unless($claims->simulationAllowed(), 404);

        $claim = $this->ownClaim($request, $token);
        $claims->simulateCompletion($claim);

        return response()->json(['data' => $this->presentClaim($claim->fresh(), $claims)]);
    }

    /** Fixed-reward campaigns (one verified rewarded ad = the campaign's coins), if Admin runs any. */
    public function campaigns(Request $request, RewardedClaimService $claims): JsonResponse
    {
        return response()->json(['data' => $claims->campaignsFor($request->user())]);
    }

    public function withdrawalAttempt(Request $request, WithdrawalRequest $withdrawal, WithdrawalAdService $withdrawalAds): JsonResponse
    {
        abort_unless($withdrawal->user_id === $request->user()->id, 404);

        return response()->json(['data' => ['show' => $withdrawalAds->attempt($request->user(), $withdrawal)]]);
    }

    private function ownClaim(Request $request, string $token): RewardedClaim
    {
        abort_unless(preg_match('/^[0-9a-f]{32}$/', $token) === 1, 404);

        return RewardedClaim::where('token', $token)->where('user_id', $request->user()->id)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentClaim(RewardedClaim $claim, RewardedClaimService $claims): array
    {
        $ads = app(AdsConfig::class);
        $placement = $ads->placement($claim->placement_key);
        $progress = null;

        if ($claim->purpose === 'tournament_ticket' && $claim->tournament_id) {
            $tournament = Tournament::find($claim->tournament_id);
            $tickets = app(TournamentTicketService::class);
            $progress = $tournament ? ['required' => $tickets->required($tournament), 'earned' => $tickets->earned($tournament, $claim->user)] : null;
        } elseif ($claim->purpose === 'ads_earning') {
            $earning = app(AdsEarningService::class);
            $progress = ['units_today' => $earning->unitsToday($claim->user), 'daily_limit' => (int) config('minimax.ads_earning_daily_view_limit')];
        }

        return [
            'token' => $claim->token,
            'dynamic_user_id' => $claims->dynamicUserId($claim),
            'purpose' => $claim->purpose,
            'placement' => $claim->placement_key,
            'ad_unit_id' => $placement?->adUnit?->levelplay_ad_unit_id,
            'status' => $claim->status,
            'expires_at' => $claim->expires_at->toIso8601String(),
            'reward_coins' => $claim->reward_coins !== null ? (string) $claim->reward_coins : null,
            'counts_for_earning' => $claim->purpose === 'ads_earning' ? $claim->counts_for_earning : null,
            'outcome' => $claim->failure_reason,
            'progress' => $progress,
        ];
    }
}
