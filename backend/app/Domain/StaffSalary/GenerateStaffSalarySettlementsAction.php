<?php

namespace App\Domain\StaffSalary;

use App\Domain\Leaderboard\LeaderboardPeriodCalculator;
use App\Models\StaffSalaryEvent;
use App\Models\StaffSalarySettlement;
use App\Models\StaffSalarySettlementItem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Groups every unsettled staff_salary_event into a weekly (Mon–Sun IST)
 * settlement per staff member, for any week that has already ended.
 * Idempotent: an event already attached to a settlement_item is excluded
 * up front, and a race on the same event is caught via the unique
 * constraint on staff_salary_settlement_items.staff_salary_event_id.
 */
class GenerateStaffSalarySettlementsAction
{
    public function execute(): int
    {
        $unsettled = StaffSalaryEvent::query()
            ->doesntHave('settlementItem')
            ->orderBy('occurred_at')
            ->get()
            ->groupBy(function (StaffSalaryEvent $event) {
                $weekStart = LeaderboardPeriodCalculator::weekBoundsFor($event->occurred_at)['start'];

                return $event->staff_user_id.'|'.$weekStart->toDateTimeString();
            });

        $settlementsTouched = 0;

        foreach ($unsettled as $key => $events) {
            [$staffUserId, $weekStartString] = explode('|', $key, 2);
            $weekStart = Carbon::parse($weekStartString, 'UTC');
            $weekEnd = $weekStart->copy()->addWeek();

            if (now()->lessThan($weekEnd)) {
                continue;
            }

            if ($this->settleGroup((int) $staffUserId, $weekStart, $weekEnd, $events)) {
                $settlementsTouched++;
            }
        }

        return $settlementsTouched;
    }

    /**
     * @param  Collection<int, StaffSalaryEvent>  $events
     */
    private function settleGroup(int $staffUserId, Carbon $weekStart, Carbon $weekEnd, Collection $events): bool
    {
        return DB::transaction(function () use ($staffUserId, $weekStart, $weekEnd, $events) {
            $settlement = StaffSalarySettlement::firstOrCreate(
                ['staff_user_id' => $staffUserId, 'period_start' => $weekStart],
                ['period_end' => $weekEnd, 'status' => 'pending', 'total_amount' => '0.00'],
            );

            if ($settlement->isPaid()) {
                // Already paid — a late-arriving event for this week is
                // left unsettled rather than silently growing a closed
                // settlement; a future run will pick it up once staff
                // decide how to handle it.
                return false;
            }

            $addedTotal = '0.00';

            foreach ($events as $event) {
                try {
                    StaffSalarySettlementItem::create([
                        'staff_salary_settlement_id' => $settlement->id,
                        'staff_salary_event_id' => $event->id,
                        'amount' => $event->amount,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    continue;
                }

                $addedTotal = bcadd($addedTotal, (string) $event->amount, 2);
            }

            if (bccomp($addedTotal, '0.00', 2) > 0) {
                $settlement->total_amount = bcadd((string) $settlement->total_amount, $addedTotal, 2);
                $settlement->save();
            }

            return true;
        });
    }
}
