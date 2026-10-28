<?php

namespace App\Console\Commands;

use App\Domain\Ads\AdEventRecorder;
use App\Domain\Ads\AdSettlementService;
use App\Domain\Ads\RewardedClaimService;
use App\Domain\Redeem\RedeemCodeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('ads:maintain')]
#[Description('Expire unanswered rewarded claims and lapsed redeem reservations, open the settlement for the week that just ended, prune old ad telemetry')]
class MaintainAdsCommand extends Command
{
    public function handle(RewardedClaimService $claims, RedeemCodeService $redeem, AdSettlementService $settlements, AdEventRecorder $events): int
    {
        $expiredClaims = $claims->expireStale();
        $expiredReservations = $redeem->expireReservations();
        $this->line("{$expiredClaims} rewarded claim(s) and {$expiredReservations} redeem reservation(s) expired.");

        // A pending settlement only appears in Admin for review; nothing is paid until an
        // Admin enters the provider's finalized revenue, calculates and credits it.
        if (config('minimax.ads_earning_enabled')) {
            try {
                $batch = $settlements->openBatch();
                $this->line("Settlement #{$batch->id} for {$batch->period_start->toDateTimeString()} – {$batch->period_end->toDateTimeString()} UTC is {$batch->status}.");
            } catch (ValidationException $e) {
                $this->line(collect($e->errors())->flatten()->first());
            }
        }

        $this->line($events->prune().' old ad event(s) pruned.');

        return self::SUCCESS;
    }
}
