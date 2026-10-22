<?php

namespace Tests\Feature\StaffSalary;

use App\Domain\Wallet\WalletService;
use App\Domain\Withdrawal\WithdrawalService;
use App\Models\StaffSalaryRule;
use App\Models\StaffSalarySettlement;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MaintainStaffSalariesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_real_staff_action_flows_through_to_a_settlement(): void
    {
        Carbon::setTestNow('2026-01-15 10:00:00');

        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        StaffSalaryRule::create(['staff_user_id' => $staff->id, 'action_type' => 'withdrawal.approved', 'amount_per_action' => '5.00']);

        $user = User::factory()->create();
        app(WalletService::class)->creditWinnings($user, '100.00', 'seed:'.$user->id);
        $withdrawal = app(WithdrawalService::class)->request($user, '40.00', 'player@upi');
        app(WithdrawalService::class)->approve($withdrawal, $staff);

        // Move past the end of that week so it's eligible for settlement.
        Carbon::setTestNow('2026-01-20 10:00:00');

        $this->artisan('staff-salary:maintain')->assertSuccessful();

        $this->assertDatabaseHas('staff_salary_events', ['action_type' => 'withdrawal.approved', 'staff_user_id' => $staff->id, 'amount' => '5.00']);
        $settlement = StaffSalarySettlement::where('staff_user_id', $staff->id)->firstOrFail();
        $this->assertSame('5.00', $settlement->total_amount);
        $this->assertSame('pending', $settlement->status);
    }
}
