<?php

namespace Tests\Feature\StaffSalary;

use App\Domain\StaffSalary\GenerateStaffSalarySettlementsAction;
use App\Models\StaffSalaryEvent;
use App\Models\StaffSalarySettlement;
use App\Models\StaffUser;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GenerateStaffSalarySettlementsActionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeEvent(StaffUser $staff, string $occurredAt, string $amount): StaffSalaryEvent
    {
        static $counter = 0;
        $counter++;

        return StaffSalaryEvent::create([
            'staff_user_id' => $staff->id,
            'action_type' => 'tournament.created',
            'source_type' => Tournament::class,
            'source_id' => $counter,
            'amount' => $amount,
            'occurred_at' => $occurredAt,
        ]);
    }

    public function test_events_in_an_ended_week_are_grouped_into_one_settlement(): void
    {
        Carbon::setTestNow('2026-01-15 10:00:00'); // Thursday — week starts Mon Jan 12
        $staff = StaffUser::factory()->create();
        $this->makeEvent($staff, '2026-01-12 10:00:00', '10.00');
        $this->makeEvent($staff, '2026-01-14 10:00:00', '20.00');

        // Move "now" past the end of that week (which ends Mon Jan 19 00:00 IST).
        Carbon::setTestNow('2026-01-20 10:00:00');

        $touched = app(GenerateStaffSalarySettlementsAction::class)->execute();

        $this->assertSame(1, $touched);
        $settlement = StaffSalarySettlement::where('staff_user_id', $staff->id)->firstOrFail();
        $this->assertSame('30.00', $settlement->total_amount);
        $this->assertSame('pending', $settlement->status);
        $this->assertSame(2, $settlement->items()->count());
    }

    public function test_events_in_the_current_unended_week_are_not_settled(): void
    {
        Carbon::setTestNow('2026-01-15 10:00:00');
        $staff = StaffUser::factory()->create();
        $this->makeEvent($staff, '2026-01-15 09:00:00', '10.00');

        app(GenerateStaffSalarySettlementsAction::class)->execute();

        $this->assertSame(0, StaffSalarySettlement::count());
    }

    public function test_rerunning_does_not_double_count(): void
    {
        Carbon::setTestNow('2026-01-15 10:00:00');
        $staff = StaffUser::factory()->create();
        $this->makeEvent($staff, '2026-01-12 10:00:00', '10.00');

        Carbon::setTestNow('2026-01-20 10:00:00');
        $action = app(GenerateStaffSalarySettlementsAction::class);
        $action->execute();
        $action->execute();

        $this->assertSame(1, StaffSalarySettlement::count());
        $this->assertSame('10.00', StaffSalarySettlement::first()->total_amount);
    }

    public function test_a_late_event_for_an_already_paid_settlement_is_left_unsettled(): void
    {
        Carbon::setTestNow('2026-01-15 10:00:00');
        $staff = StaffUser::factory()->create();
        $this->makeEvent($staff, '2026-01-12 10:00:00', '10.00');

        Carbon::setTestNow('2026-01-20 10:00:00');
        app(GenerateStaffSalarySettlementsAction::class)->execute();

        $settlement = StaffSalarySettlement::where('staff_user_id', $staff->id)->firstOrFail();
        $settlement->status = 'paid';
        $settlement->paid_at = now();
        $settlement->save();

        // A late-arriving event for the same already-paid week.
        $this->makeEvent($staff, '2026-01-13 10:00:00', '5.00');
        app(GenerateStaffSalarySettlementsAction::class)->execute();

        $this->assertSame('10.00', $settlement->fresh()->total_amount);
        $this->assertSame(1, StaffSalaryEvent::whereDoesntHave('settlementItem')->count());
    }

    public function test_two_staff_members_in_the_same_week_get_separate_settlements(): void
    {
        Carbon::setTestNow('2026-01-15 10:00:00');
        $staffA = StaffUser::factory()->create();
        $staffB = StaffUser::factory()->create();
        $this->makeEvent($staffA, '2026-01-12 10:00:00', '10.00');
        $this->makeEvent($staffB, '2026-01-12 10:00:00', '20.00');

        Carbon::setTestNow('2026-01-20 10:00:00');
        app(GenerateStaffSalarySettlementsAction::class)->execute();

        $this->assertSame(2, StaffSalarySettlement::count());
        $this->assertSame('10.00', StaffSalarySettlement::where('staff_user_id', $staffA->id)->first()->total_amount);
        $this->assertSame('20.00', StaffSalarySettlement::where('staff_user_id', $staffB->id)->first()->total_amount);
    }
}
