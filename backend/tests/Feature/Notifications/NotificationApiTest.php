<?php

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\NotificationService;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_listing_notifications_requires_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
    }

    public function test_a_player_only_sees_their_own_notifications_with_an_accurate_unread_count(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $service = app(NotificationService::class);
        $service->notify([$user], 'test.event', 'Hello', 'For you', idempotencyKey: 'n1');
        $service->notify([$user], 'test.event', 'Hello again', 'Also for you', idempotencyKey: 'n2');
        $service->notify([$otherUser], 'test.event', 'Not yours', 'Belongs to someone else', idempotencyKey: 'n3');

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/notifications');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(2, $response->json('meta.unread_count'));
    }

    public function test_marking_a_notification_as_read_updates_the_unread_count(): void
    {
        $user = User::factory()->create();
        app(NotificationService::class)->notify([$user], 'test.event', 'Hello', 'Body', idempotencyKey: 'n1');
        $notification = Notification::where('user_id', $user->id)->firstOrFail();

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/notifications/{$notification->id}/read");

        $response->assertOk()->assertJsonPath('data.read', true);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_a_player_cannot_mark_another_players_notification_as_read(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        app(NotificationService::class)->notify([$owner], 'test.event', 'Hello', 'Body', idempotencyKey: 'n1');
        $notification = Notification::where('user_id', $owner->id)->firstOrFail();

        $response = $this->actingAs($intruder, 'sanctum')->postJson("/api/v1/notifications/{$notification->id}/read");

        $response->assertNotFound();
        $this->assertNull($notification->fresh()->read_at);
    }
}
