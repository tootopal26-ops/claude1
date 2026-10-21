<?php

namespace Tests\Feature\Tournament;

use App\Domain\Tournament\ReleaseCredentialsAction;
use App\Livewire\Admin\TournamentEntries;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\StaffUser;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CredentialReleaseTest extends TestCase
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

    public function test_releasing_credentials_sets_released_at_and_flips_status_to_ongoing(): void
    {
        $tournament = Tournament::factory()->create(['status' => 'upcoming']);
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);

        app(ReleaseCredentialsAction::class)->execute($tournament, 'ROOM123', 'secretpass', $staff);

        $tournament->refresh();
        $this->assertNotNull($tournament->credentials_released_at);
        $this->assertSame('ongoing', $tournament->status);
        $this->assertSame('ROOM123', $tournament->credential->room_id);
        $this->assertSame('secretpass', $tournament->credential->room_password);
    }

    public function test_credentials_cannot_be_released_twice(): void
    {
        $tournament = Tournament::factory()->create(['status' => 'upcoming']);
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);

        app(ReleaseCredentialsAction::class)->execute($tournament, 'ROOM123', 'secretpass', $staff);

        $this->expectException(ValidationException::class);
        app(ReleaseCredentialsAction::class)->execute($tournament->fresh(), 'ROOM456', 'otherpass', $staff);
    }

    public function test_releasing_credentials_notifies_joined_players_without_leaking_the_secrets(): void
    {
        $tournament = Tournament::factory()->create(['status' => 'upcoming']);
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        $user = User::factory()->create();
        $entry = TournamentEntry::factory()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id]);

        app(ReleaseCredentialsAction::class)->execute($tournament, 'TOPSECRETROOM', 'TOPSECRETPASS', $staff);

        $notification = Notification::where('user_id', $user->id)->firstOrFail();
        $this->assertStringNotContainsString('TOPSECRETROOM', json_encode($notification->data));
        $this->assertStringNotContainsString('TOPSECRETPASS', json_encode($notification->data));
        $this->assertStringNotContainsString('TOPSECRETROOM', $notification->body);
        $this->assertStringNotContainsString('TOPSECRETPASS', $notification->body);
    }

    public function test_a_non_participant_cannot_fetch_credentials(): void
    {
        $tournament = Tournament::factory()->create();
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        app(ReleaseCredentialsAction::class)->execute($tournament, 'ROOM123', 'secretpass', $staff);

        $outsider = User::factory()->create();

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/v1/tournaments/{$tournament->id}/credentials")
            ->assertForbidden();
    }

    public function test_a_joined_player_cannot_fetch_credentials_before_release(): void
    {
        $tournament = Tournament::factory()->create();
        $user = User::factory()->create();
        TournamentEntry::factory()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/tournaments/{$tournament->id}/credentials")
            ->assertNotFound();
    }

    public function test_a_joined_player_can_fetch_credentials_after_release(): void
    {
        $tournament = Tournament::factory()->create();
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        $user = User::factory()->create();
        TournamentEntry::factory()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id]);

        app(ReleaseCredentialsAction::class)->execute($tournament, 'ROOM123', 'secretpass', $staff);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/tournaments/{$tournament->id}/credentials")
            ->assertOk()
            ->assertJsonPath('data.room_id', 'ROOM123')
            ->assertJsonPath('data.room_password', 'secretpass');
    }

    public function test_the_release_form_is_hidden_without_credentials_release_permission(): void
    {
        $preset = $this->grantPreset(['tournaments.view']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $tournament = Tournament::factory()->create();

        Livewire::actingAs($staff, 'staff')
            ->test(TournamentEntries::class, ['tournament' => $tournament])
            ->assertDontSee('Release to joined players');
    }

    public function test_releasing_credentials_is_denied_without_the_permission(): void
    {
        $preset = $this->grantPreset(['tournaments.view']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $tournament = Tournament::factory()->create();

        Livewire::actingAs($staff, 'staff')
            ->test(TournamentEntries::class, ['tournament' => $tournament])
            ->set('roomId', 'ROOM123')
            ->set('roomPassword', 'secretpass')
            ->call('releaseCredentials')
            ->assertForbidden();

        $this->assertNull($tournament->fresh()->credentials_released_at);
    }

    public function test_a_staff_user_with_the_permission_can_release_credentials_via_the_admin_ui(): void
    {
        $preset = $this->grantPreset(['tournaments.view', 'credentials.release']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $tournament = Tournament::factory()->create();

        Livewire::actingAs($staff, 'staff')
            ->test(TournamentEntries::class, ['tournament' => $tournament])
            ->set('roomId', 'ROOM123')
            ->set('roomPassword', 'secretpass')
            ->call('releaseCredentials');

        $this->assertNotNull($tournament->fresh()->credentials_released_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tournament.credentials_released',
            'actor_id' => $staff->id,
        ]);
    }
}
