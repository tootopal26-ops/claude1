<?php

namespace Tests\Feature;

use App\Livewire\Admin\UserList;
use App\Livewire\Admin\UserShow;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\StaffUser;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function grantPreset(array $keys): PermissionPreset
    {
        $preset = PermissionPreset::create(['name' => implode('+', $keys)]);

        foreach ($keys as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key, 'group' => 'Test']);
            $preset->permissions()->attach($permission);
        }

        return $preset;
    }

    public function test_a_staff_user_without_users_view_is_denied_the_list(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => false]);

        $this->actingAs($staff, 'staff')->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_a_staff_user_without_users_view_is_denied_the_detail_page(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => false]);
        $player = User::factory()->create();

        $this->actingAs($staff, 'staff')->get(route('admin.users.show', $player))->assertForbidden();
    }

    public function test_a_staff_user_with_users_view_sees_the_player_in_the_list(): void
    {
        $preset = $this->grantPreset(['users.view']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $player = User::factory()->create(['username' => 'findable_player']);

        $this->actingAs($staff, 'staff')->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('findable_player');
    }

    public function test_searching_the_list_filters_by_username(): void
    {
        $preset = $this->grantPreset(['users.view']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        User::factory()->create(['username' => 'alpha_player']);
        User::factory()->create(['username' => 'beta_player']);

        Livewire::actingAs($staff, 'staff')
            ->test(UserList::class)
            ->set('search', 'alpha')
            ->assertSee('alpha_player')
            ->assertDontSee('beta_player');
    }

    public function test_a_staff_user_with_users_view_sees_the_player_detail_page(): void
    {
        $preset = $this->grantPreset(['users.view']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $player = User::factory()->create(['username' => 'viewable_player']);

        $this->actingAs($staff, 'staff')->get(route('admin.users.show', $player))
            ->assertOk()
            ->assertSee('viewable_player');
    }

    public function test_the_ban_button_is_hidden_without_users_ban_permission(): void
    {
        $preset = $this->grantPreset(['users.view']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $player = User::factory()->create();

        Livewire::actingAs($staff, 'staff')
            ->test(UserShow::class, ['user' => $player])
            ->assertDontSee('Ban account');
    }

    public function test_toggle_ban_is_denied_without_users_ban_permission(): void
    {
        $preset = $this->grantPreset(['users.view']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $player = User::factory()->create(['is_banned' => false]);

        Livewire::actingAs($staff, 'staff')
            ->test(UserShow::class, ['user' => $player])
            ->call('toggleBan')
            ->assertForbidden();

        $this->assertFalse($player->fresh()->is_banned);
    }

    public function test_a_staff_user_with_users_ban_can_ban_and_unban_with_an_audit_trail(): void
    {
        $preset = $this->grantPreset(['users.view', 'users.ban']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $player = User::factory()->create(['is_banned' => false]);

        Livewire::actingAs($staff, 'staff')
            ->test(UserShow::class, ['user' => $player])
            ->call('toggleBan');

        $this->assertTrue($player->fresh()->is_banned);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.banned',
            'actor_id' => $staff->id,
            'subject_id' => $player->id,
        ]);

        Livewire::actingAs($staff, 'staff')
            ->test(UserShow::class, ['user' => $player->fresh()])
            ->call('toggleBan');

        $this->assertFalse($player->fresh()->is_banned);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.unbanned',
            'actor_id' => $staff->id,
            'subject_id' => $player->id,
        ]);
    }

    public function test_the_wallet_adjustment_form_is_hidden_without_wallet_manage_permission(): void
    {
        $preset = $this->grantPreset(['users.view']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $player = User::factory()->create();

        Livewire::actingAs($staff, 'staff')
            ->test(UserShow::class, ['user' => $player])
            ->assertDontSee('Adjust wallet');
    }

    public function test_adjust_wallet_is_denied_without_wallet_manage_permission(): void
    {
        $preset = $this->grantPreset(['users.view']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $player = User::factory()->create();

        Livewire::actingAs($staff, 'staff')
            ->test(UserShow::class, ['user' => $player])
            ->set('adjustAmount', '50.00')
            ->set('adjustReason', 'bonus')
            ->call('adjustWallet')
            ->assertForbidden();

        $this->assertSame('0.00', $player->fresh()->wallet->deposit_balance);
    }

    public function test_a_staff_user_with_wallet_manage_can_credit_a_players_deposit_balance(): void
    {
        $preset = $this->grantPreset(['users.view', 'wallet.manage']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $player = User::factory()->create();

        Livewire::actingAs($staff, 'staff')
            ->test(UserShow::class, ['user' => $player])
            ->set('adjustDirection', 'add')
            ->set('adjustAmount', '50.00')
            ->set('adjustBalance', 'deposit')
            ->set('adjustReason', 'goodwill credit')
            ->call('adjustWallet');

        $this->assertSame('50.00', $player->fresh()->wallet->deposit_balance);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'wallet.manual_adjustment',
            'actor_id' => $staff->id,
        ]);
    }

    public function test_a_subtraction_beyond_the_players_balance_is_rejected_and_shown_as_an_error(): void
    {
        $preset = $this->grantPreset(['users.view', 'wallet.manage']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $player = User::factory()->create();

        Livewire::actingAs($staff, 'staff')
            ->test(UserShow::class, ['user' => $player])
            ->set('adjustDirection', 'subtract')
            ->set('adjustAmount', '50.00')
            ->set('adjustBalance', 'deposit')
            ->set('adjustReason', 'overdraft attempt')
            ->call('adjustWallet')
            ->assertSet('adjustError', fn (?string $value) => $value !== null);

        $this->assertSame('0.00', $player->fresh()->wallet->deposit_balance);
    }

    public function test_the_detail_page_lists_the_players_recent_tournament_entries(): void
    {
        $preset = $this->grantPreset(['users.view']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $player = User::factory()->create();
        $tournament = Tournament::factory()->create(['title' => 'Friday Night Solo']);
        TournamentEntry::factory()->create(['user_id' => $player->id, 'tournament_id' => $tournament->id]);

        $this->actingAs($staff, 'staff')->get(route('admin.users.show', $player))
            ->assertOk()
            ->assertSee('Friday Night Solo');
    }
}
