<?php

namespace Tests\Feature\StaffSalary;

use App\Domain\StaffSalary\MarkStaffSalarySettlementPaidAction;
use App\Models\StaffSalarySettlement;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MarkStaffSalarySettlementPaidActionTest extends TestCase
{
    use RefreshDatabase;

    private function makeSettlement(): StaffSalarySettlement
    {
        return StaffSalarySettlement::create([
            'staff_user_id' => StaffUser::factory()->create()->id,
            'period_start' => '2026-01-12 00:00:00',
            'period_end' => '2026-01-19 00:00:00',
            'status' => 'pending',
            'total_amount' => '100.00',
        ]);
    }

    public function test_an_empty_reference_is_rejected(): void
    {
        $settlement = $this->makeSettlement();
        $actor = StaffUser::factory()->create();

        $this->expectException(ValidationException::class);
        app(MarkStaffSalarySettlementPaidAction::class)->execute($settlement, '   ', $actor);
    }

    public function test_marking_paid_sets_the_reference_actor_and_timestamp(): void
    {
        $settlement = $this->makeSettlement();
        $actor = StaffUser::factory()->create();

        $result = app(MarkStaffSalarySettlementPaidAction::class)->execute($settlement, 'BANK-REF-123', $actor);

        $this->assertSame('paid', $result->status);
        $this->assertSame('BANK-REF-123', $result->payment_reference);
        $this->assertSame($actor->id, $result->paid_by_staff_id);
        $this->assertNotNull($result->paid_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff_salary.settlement_paid', 'actor_id' => $actor->id]);
    }

    public function test_marking_an_already_paid_settlement_is_idempotent(): void
    {
        $settlement = $this->makeSettlement();
        $firstActor = StaffUser::factory()->create();
        $secondActor = StaffUser::factory()->create();

        app(MarkStaffSalarySettlementPaidAction::class)->execute($settlement, 'BANK-REF-123', $firstActor);
        $result = app(MarkStaffSalarySettlementPaidAction::class)->execute($settlement->fresh(), 'DIFFERENT-REF', $secondActor);

        $this->assertSame($firstActor->id, $result->paid_by_staff_id);
        $this->assertSame('BANK-REF-123', $result->payment_reference);
    }
}
