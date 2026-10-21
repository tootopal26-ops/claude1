<?php

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\NotificationService;
use App\Livewire\Admin\NotificationCompose;
use App\Models\NotificationBatch;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\StaffUser;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminNotificationComposeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_staff_user_without_the_notifications_permission_is_denied(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => false]);

        $this->actingAs($staff, 'staff')->get(route('admin.notifications.create'))->assertForbidden();
    }

    public function test_sending_to_all_active_users_reaches_every_non_banned_player(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        User::factory()->count(3)->create(['is_banned' => false]);
        User::factory()->create(['is_banned' => true]);

        Livewire::actingAs($staff, 'staff')
            ->test(NotificationCompose::class)
            ->set('title', 'Server update')
            ->set('body', 'Everyone should see this.')
            ->set('audienceType', 'all')
            ->call('send');

        $batch = NotificationBatch::sole();
        $this->assertSame(3, $batch->recipient_count);
        $this->assertSame($staff->id, $batch->created_by_staff_id);
    }

    public function test_sending_to_selected_users_only_reaches_those_users(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        $picked = User::factory()->count(2)->create();
        User::factory()->create(); // not picked

        Livewire::actingAs($staff, 'staff')
            ->test(NotificationCompose::class)
            ->set('title', 'Just for you two')
            ->set('body', 'Hello')
            ->set('audienceType', 'selected_users')
            ->call('addUser', $picked[0]->id)
            ->call('addUser', $picked[1]->id)
            ->call('send');

        $batch = NotificationBatch::sole();
        $this->assertSame(2, $batch->recipient_count);
        $recipientIds = $batch->notifications()->pluck('user_id')->sort()->values()->all();
        $this->assertSame($picked->pluck('id')->sort()->values()->all(), $recipientIds);
    }

    public function test_selected_users_audience_requires_at_least_one_user(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);

        Livewire::actingAs($staff, 'staff')
            ->test(NotificationCompose::class)
            ->set('title', 'Oops')
            ->set('body', 'No recipients chosen')
            ->set('audienceType', 'selected_users')
            ->call('send')
            ->assertHasErrors('selectedUserIds');

        $this->assertSame(0, NotificationBatch::count());
    }

    public function test_sending_to_tournament_participants_only_reaches_confirmed_entries(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        $tournament = Tournament::factory()->create();
        $joinedUser = User::factory()->create();
        $cancelledUser = User::factory()->create();

        TournamentEntry::factory()->create([
            'tournament_id' => $tournament->id,
            'user_id' => $joinedUser->id,
            'status' => 'confirmed',
        ]);
        TournamentEntry::factory()->create([
            'tournament_id' => $tournament->id,
            'user_id' => $cancelledUser->id,
            'status' => 'cancelled',
        ]);

        Livewire::actingAs($staff, 'staff')
            ->test(NotificationCompose::class)
            ->set('title', 'Match reminder')
            ->set('body', 'Starting soon')
            ->set('audienceType', 'tournament_participants')
            ->set('tournamentId', $tournament->id)
            ->call('send');

        $batch = NotificationBatch::sole();
        $this->assertSame(1, $batch->recipient_count);
        $this->assertSame([$joinedUser->id], $batch->notifications()->pluck('user_id')->all());
    }

    public function test_an_idempotency_key_prevents_a_double_submit_from_creating_two_batches(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        User::factory()->create();

        $component = Livewire::actingAs($staff, 'staff')
            ->test(NotificationCompose::class)
            ->set('title', 'Once only')
            ->set('body', 'Should not duplicate')
            ->set('audienceType', 'all');

        $component->call('send');
        $this->assertSame(1, NotificationBatch::count());

        // Simulate the same idempotency key arriving again (e.g. a retried request).
        app(NotificationService::class)->notify(
            recipients: User::all(),
            type: 'admin_broadcast',
            title: 'Once only',
            body: 'Should not duplicate',
            idempotencyKey: $component->get('idempotencyKey'),
        );

        $this->assertSame(1, NotificationBatch::count());
    }

    public function test_requires_authorization_checks_for_notifications_preset(): void
    {
        $permission = Permission::create(['key' => 'notifications.send', 'label' => 'Send notifications', 'group' => 'notifications']);
        $preset = PermissionPreset::create(['name' => 'Support']);
        $preset->permissions()->attach($permission);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);

        $this->actingAs($staff, 'staff')->get(route('admin.notifications.create'))->assertOk();
    }
}
