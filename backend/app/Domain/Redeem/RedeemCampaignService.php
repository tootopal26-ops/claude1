<?php

namespace App\Domain\Redeem;

use App\Models\AuditLog;
use App\Models\RedeemCampaign;
use App\Models\StaffUser;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Admin operations on redeem-code campaigns. Every change is audit-logged.
 * Campaigns are never deleted: redemptions reference them and are part of the
 * wallet history, so retiring one means archiving it.
 */
class RedeemCampaignService
{
    public const MAX_ATTEMPTS = 25;

    public function __construct(private readonly RedeemCodeGenerator $generator) {}

    /**
     * @param  array{name: string, total_capacity: int, reward_coins: int, expires_at: Carbon, is_active: bool}  $data
     */
    public function create(array $data, StaffUser $actor): RedeemCampaign
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $campaign = RedeemCampaign::create([
                    'name' => $data['name'],
                    'code' => $this->generator->generate(),
                    'total_capacity' => $data['total_capacity'],
                    'reward_coins' => $data['reward_coins'],
                    'expires_at' => $data['expires_at'],
                    'is_active' => $data['is_active'],
                    'created_by_staff_id' => $actor->id,
                ]);

                AuditLog::record('redeem_campaign.created', $actor, $campaign, [
                    'name' => $campaign->name,
                    'code' => $campaign->code,
                    'total_capacity' => $campaign->total_capacity,
                    'reward_coins' => $campaign->reward_coins,
                    'potential_liability' => $campaign->potentialLiability(),
                    'expires_at' => $campaign->expires_at->toIso8601String(),
                ]);

                return $campaign;
            } catch (UniqueConstraintViolationException) {
                // That code already exists — the unique index is the authority; draw another.
            }
        }

        throw new \RuntimeException('Could not generate a unique redeem code. Try again.');
    }

    public function setActive(RedeemCampaign $campaign, bool $active, StaffUser $actor): void
    {
        $campaign->update(['is_active' => $active]);

        AuditLog::record($active ? 'redeem_campaign.activated' : 'redeem_campaign.deactivated', $actor, $campaign, ['code' => $campaign->code]);
    }

    public function archive(RedeemCampaign $campaign, StaffUser $actor): void
    {
        $campaign->forceFill(['archived_at' => now(), 'is_active' => false])->save();

        AuditLog::record('redeem_campaign.archived', $actor, $campaign, ['code' => $campaign->code, 'redeemed_count' => $campaign->redeemed_count]);
    }

    public function unarchive(RedeemCampaign $campaign, StaffUser $actor): void
    {
        // Comes back inactive: a retired campaign is never silently live again.
        $campaign->forceFill(['archived_at' => null])->save();

        AuditLog::record('redeem_campaign.unarchived', $actor, $campaign, ['code' => $campaign->code]);
    }
}
