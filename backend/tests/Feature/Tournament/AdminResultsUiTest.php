<?php

namespace Tests\Feature\Tournament;

use App\Domain\Tournament\SubmitTournamentResultsAction;
use App\Livewire\Admin\TournamentResults;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\StaffUser;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminResultsUiTest extends TestCase
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

    public function test_a_staff_user_without_results_submit_cannot_view_the_results_page(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => false]);
        $tournament = Tournament::factory()->create(['status' => 'ongoing']);

        $this->actingAs($staff, 'staff')->get(route('staff.tournaments.results', $tournament))->assertForbidden();
    }

    public function test_submitting_results_is_denied_without_the_permission(): void
    {
        $preset = $this->grantPreset(['tournaments.view']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $tournament = Tournament::factory()->create(['status' => 'ongoing']);

        Livewire::actingAs($staff, 'staff')
            ->test(TournamentResults::class, ['tournament' => $tournament])
            ->call('submit')
            ->assertForbidden();
    }

    public function test_a_staff_user_with_the_permission_can_publish_results(): void
    {
        $preset = $this->grantPreset(['results.submit']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $tournament = Tournament::factory()->create(['status' => 'ongoing', 'prize_pool' => 100]);
        $user = User::factory()->create(['username' => 'result_player']);
        $entry = TournamentEntry::factory()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id]);

        Livewire::actingAs($staff, 'staff')
            ->test(TournamentResults::class, ['tournament' => $tournament])
            ->assertSee('result_player')
            ->set('rows.0.rank', 1)
            ->set('rows.0.kills', 7)
            ->set('rows.0.prize_awarded', '50.00')
            ->call('submit');

        $this->assertSame('completed', $tournament->fresh()->status);
        $this->assertSame('50.00', $user->fresh()->wallet->eligible_winnings_balance);
        $this->assertSame(1, $entry->fresh()->currentResult()->revision_number);
    }

    public function test_the_form_prefills_with_the_current_result_for_a_correction(): void
    {
        $preset = $this->grantPreset(['results.submit']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $tournament = Tournament::factory()->create(['status' => 'ongoing', 'prize_pool' => 100]);
        $entry = TournamentEntry::factory()->create(['tournament_id' => $tournament->id]);

        app(SubmitTournamentResultsAction::class)->execute($tournament, [
            ['tournament_entry_id' => $entry->id, 'rank' => 3, 'kills' => 4, 'prize_awarded' => '15.00'],
        ], $staff);

        Livewire::actingAs($staff, 'staff')
            ->test(TournamentResults::class, ['tournament' => $tournament->fresh()])
            ->assertSet('rows.0.rank', 3)
            ->assertSet('rows.0.kills', 4)
            ->assertSet('rows.0.prize_awarded', '15.00');
    }
}
