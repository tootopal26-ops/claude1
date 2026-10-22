<?php

namespace Tests\Feature\StaffSalary;

use App\Domain\StaffSalary\GenerateStaffSalaryEventsAction;
use App\Models\AuditLog;
use App\Models\StaffSalaryEvent;
use App\Models\StaffSalaryRule;
use App\Models\StaffUser;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateStaffSalaryEventsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_matching_active_rule_generates_an_event(): void
    {
        $staff = StaffUser::factory()->create();
        StaffSalaryRule::create(['staff_user_id' => $staff->id, 'action_type' => 'tournament.created', 'amount_per_action' => '15.00']);
        $tournament = Tournament::factory()->create();
        AuditLog::record('tournament.created', $staff, $tournament);

        $created = app(GenerateStaffSalaryEventsAction::class)->execute();

        $this->assertSame(1, $created);
        $event = StaffSalaryEvent::first();
        $this->assertSame($staff->id, $event->staff_user_id);
        $this->assertSame('tournament.created', $event->action_type);
        $this->assertSame('15.00', $event->amount);
        $this->assertSame($tournament->id, $event->source_id);
    }

    public function test_an_inactive_rule_generates_no_event(): void
    {
        $staff = StaffUser::factory()->create();
        StaffSalaryRule::create(['staff_user_id' => $staff->id, 'action_type' => 'tournament.created', 'amount_per_action' => '15.00', 'is_active' => false]);
        AuditLog::record('tournament.created', $staff, Tournament::factory()->create());

        $created = app(GenerateStaffSalaryEventsAction::class)->execute();

        $this->assertSame(0, $created);
        $this->assertSame(0, StaffSalaryEvent::count());
    }

    public function test_an_action_with_no_matching_rule_generates_no_event(): void
    {
        $staff = StaffUser::factory()->create();
        StaffSalaryRule::create(['staff_user_id' => $staff->id, 'action_type' => 'tournament.created', 'amount_per_action' => '15.00']);
        AuditLog::record('tournament.results_published', $staff, Tournament::factory()->create());

        app(GenerateStaffSalaryEventsAction::class)->execute();

        $this->assertSame(0, StaffSalaryEvent::count());
    }

    public function test_a_system_triggered_audit_log_is_never_matched(): void
    {
        $staff = StaffUser::factory()->create();
        StaffSalaryRule::create(['staff_user_id' => $staff->id, 'action_type' => 'tournament.join_window_closed', 'amount_per_action' => '15.00']);
        AuditLog::record('tournament.join_window_closed', null, Tournament::factory()->create());

        app(GenerateStaffSalaryEventsAction::class)->execute();

        $this->assertSame(0, StaffSalaryEvent::count());
    }

    public function test_an_audit_log_with_no_subject_is_excluded(): void
    {
        $staff = StaffUser::factory()->create();
        StaffSalaryRule::create(['staff_user_id' => $staff->id, 'action_type' => 'some.action', 'amount_per_action' => '15.00']);
        AuditLog::record('some.action', $staff, null);

        app(GenerateStaffSalaryEventsAction::class)->execute();

        $this->assertSame(0, StaffSalaryEvent::count());
    }

    public function test_rerunning_is_idempotent(): void
    {
        $staff = StaffUser::factory()->create();
        StaffSalaryRule::create(['staff_user_id' => $staff->id, 'action_type' => 'tournament.created', 'amount_per_action' => '15.00']);
        AuditLog::record('tournament.created', $staff, Tournament::factory()->create());

        $action = app(GenerateStaffSalaryEventsAction::class);
        $action->execute();
        $second = $action->execute();

        $this->assertSame(0, $second);
        $this->assertSame(1, StaffSalaryEvent::count());
    }

    public function test_a_rule_changed_after_generation_does_not_retroactively_change_the_event_amount(): void
    {
        $staff = StaffUser::factory()->create();
        $rule = StaffSalaryRule::create(['staff_user_id' => $staff->id, 'action_type' => 'tournament.created', 'amount_per_action' => '15.00']);
        AuditLog::record('tournament.created', $staff, Tournament::factory()->create());

        app(GenerateStaffSalaryEventsAction::class)->execute();

        $rule->amount_per_action = '999.00';
        $rule->save();

        $this->assertSame('15.00', StaffSalaryEvent::first()->amount);
    }
}
