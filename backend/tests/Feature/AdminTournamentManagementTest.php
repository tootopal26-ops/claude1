<?php

namespace Tests\Feature;

use App\Livewire\Admin\TournamentCreate;
use App\Models\Game;
use App\Models\StaffUser;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminTournamentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_create_a_solo_tournament(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        $game = Game::factory()->create();

        Livewire::actingAs($staff, 'staff')
            ->test(TournamentCreate::class)
            ->set('game_id', $game->id)
            ->set('title', 'Friday Night Solo')
            ->set('entry_fee', 0)
            ->set('total_slots', 48)
            ->set('scheduled_start_at', now()->addDay()->format('Y-m-d\TH:i'))
            ->call('save')
            ->assertRedirect(route('admin.tournaments.index'));

        $this->assertDatabaseHas('tournaments', [
            'title' => 'Friday Night Solo',
            'mode' => 'solo',
            'status' => 'upcoming',
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'tournament.created']);
    }

    public function test_the_entries_page_lists_everyone_who_joined(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        $tournament = Tournament::factory()->create(['total_slots' => 2]);
        $user = User::factory()->create(['username' => 'joined_player']);

        $entry = TournamentEntry::factory()->create([
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
        ]);
        $entry->players()->create(['slot_number' => 1, 'in_game_name' => 'ProPlayer', 'uid' => '123456']);

        $response = $this->actingAs($staff, 'staff')->get(route('admin.tournaments.entries', $tournament));

        $response->assertOk()
            ->assertSee('joined_player')
            ->assertSee('ProPlayer');
    }
}
