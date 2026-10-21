<?php

namespace Tests\Feature\Withdrawal;

use App\Domain\Wallet\WalletService;
use App\Domain\Withdrawal\WithdrawalService;
use App\Livewire\Admin\WithdrawalList;
use App\Livewire\Admin\WithdrawalShow;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminWithdrawalUiTest extends TestCase
{
    use RefreshDatabase;

    private function fundWinnings(User $user, string $amount): void
    {
        app(WalletService::class)->creditWinnings($user, $amount, 'seed:'.$user->id);
    }

    private function unauthorizedStaff(): StaffUser
    {
        $preset = PermissionPreset::create(['name' => 'no-withdrawals-'.uniqid()]);
        $permission = Permission::firstOrCreate(['key' => 'tournaments.view'], ['label' => 'x', 'group' => 'Test']);
        $preset->permissions()->attach($permission);

        return StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
    }

    public function test_a_staff_user_without_the_permission_cannot_view_the_withdrawal_list(): void
    {
        $staff = $this->unauthorizedStaff();

        $this->actingAs($staff, 'staff')->get(route('admin.withdrawals.index'))->assertForbidden();
    }

    public function test_a_staff_user_without_the_permission_cannot_view_a_withdrawal(): void
    {
        $user = User::factory()->create();
        $this->fundWinnings($user, '100.00');
        $withdrawal = app(WithdrawalService::class)->request($user, '40.00', 'player@upi');
        $staff = $this->unauthorizedStaff();

        $this->actingAs($staff, 'staff')->get(route('admin.withdrawals.show', $withdrawal))->assertForbidden();
    }

    public function test_approve_is_denied_without_the_permission(): void
    {
        $user = User::factory()->create();
        $this->fundWinnings($user, '100.00');
        $withdrawal = app(WithdrawalService::class)->request($user, '40.00', 'player@upi');
        $staff = $this->unauthorizedStaff();

        Livewire::actingAs($staff, 'staff')
            ->test(WithdrawalShow::class, ['withdrawal' => $withdrawal])
            ->call('approve')
            ->assertForbidden();

        $this->assertSame('pending', $withdrawal->fresh()->status);
    }

    public function test_a_staff_user_with_the_permission_can_drive_the_full_lifecycle(): void
    {
        $user = User::factory()->create();
        $this->fundWinnings($user, '100.00');
        $withdrawal = app(WithdrawalService::class)->request($user, '40.00', 'player@upi');
        $admin = StaffUser::factory()->create(['is_super_admin' => true]);

        Livewire::actingAs($admin, 'staff')
            ->test(WithdrawalShow::class, ['withdrawal' => $withdrawal])
            ->call('approve')
            ->assertSee('approved');

        $withdrawal->refresh();
        Livewire::actingAs($admin, 'staff')
            ->test(WithdrawalShow::class, ['withdrawal' => $withdrawal])
            ->call('markProcessing')
            ->assertSee('processing');

        $withdrawal->refresh();
        Livewire::actingAs($admin, 'staff')
            ->test(WithdrawalShow::class, ['withdrawal' => $withdrawal])
            ->set('settlementReference', 'UTR999')
            ->call('markPaid');

        $this->assertSame('paid', $withdrawal->fresh()->status);
        $this->assertSame('UTR999', $withdrawal->fresh()->settlement_reference);
    }

    public function test_rejecting_requires_a_reason_and_releases_the_reservation(): void
    {
        $user = User::factory()->create();
        $this->fundWinnings($user, '100.00');
        $withdrawal = app(WithdrawalService::class)->request($user, '40.00', 'player@upi');
        $admin = StaffUser::factory()->create(['is_super_admin' => true]);

        Livewire::actingAs($admin, 'staff')
            ->test(WithdrawalShow::class, ['withdrawal' => $withdrawal])
            ->call('reject')
            ->assertHasErrors('rejectionReason');

        $this->assertSame('pending', $withdrawal->fresh()->status);

        Livewire::actingAs($admin, 'staff')
            ->test(WithdrawalShow::class, ['withdrawal' => $withdrawal])
            ->set('rejectionReason', 'Duplicate request')
            ->call('reject');

        $this->assertSame('rejected', $withdrawal->fresh()->status);
        $this->assertSame('100.00', $user->fresh()->wallet->eligible_winnings_balance);
    }

    public function test_the_list_filters_by_status(): void
    {
        $admin = StaffUser::factory()->create(['is_super_admin' => true]);
        $user = User::factory()->create(['username' => 'pending_player']);
        $this->fundWinnings($user, '100.00');
        app(WithdrawalService::class)->request($user, '10.00', 'player@upi');

        $otherUser = User::factory()->create(['username' => 'rejected_player']);
        $this->fundWinnings($otherUser, '100.00');
        $rejected = app(WithdrawalService::class)->request($otherUser, '10.00', 'other@upi');
        app(WithdrawalService::class)->reject($rejected, 'nope', $admin);

        Livewire::actingAs($admin, 'staff')
            ->test(WithdrawalList::class)
            ->set('statusFilter', 'pending')
            ->assertSee('pending_player')
            ->assertDontSee('rejected_player');
    }
}
