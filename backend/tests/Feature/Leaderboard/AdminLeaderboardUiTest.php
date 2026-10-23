<?php

namespace Tests\Feature\Leaderboard;

use App\Livewire\Admin\LeaderboardViewer;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminLeaderboardUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_staff_user_without_the_permission_cannot_view_leaderboards(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => false]);

        $this->actingAs($staff, 'staff')->get(route('staff.leaderboards.index'))->assertForbidden();
    }

    public function test_recompute_is_denied_without_the_permission(): void
    {
        $preset = PermissionPreset::create(['name' => 'no-leaderboards']);
        $permission = Permission::firstOrCreate(['key' => 'tournaments.view'], ['label' => 'x', 'group' => 'Test']);
        $preset->permissions()->attach($permission);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);

        Livewire::actingAs($staff, 'staff')
            ->test(LeaderboardViewer::class)
            ->call('recomputeNow')
            ->assertForbidden();
    }

    public function test_a_super_admin_can_view_and_recompute(): void
    {
        $admin = StaffUser::factory()->create(['is_super_admin' => true]);

        Livewire::actingAs($admin, 'staff')
            ->test(LeaderboardViewer::class)
            ->call('recomputeNow')
            ->assertSet('actionStatus', 'Recomputed.');
    }
}
