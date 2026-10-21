<?php

namespace Tests\Feature\Notifications;

use App\Models\DevicePushToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevicePushTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_a_push_token_requires_authentication(): void
    {
        $this->postJson('/api/v1/devices/push-token', ['token' => 'abc123'])->assertUnauthorized();
    }

    public function test_an_authenticated_player_can_register_a_push_token(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/devices/push-token', [
            'token' => 'fcm-token-1',
            'platform' => 'android',
            'device_name' => 'Pixel 9',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('device_push_tokens', [
            'user_id' => $user->id,
            'token' => 'fcm-token-1',
            'platform' => 'android',
        ]);
    }

    public function test_re_registering_the_same_token_under_a_different_account_reassigns_it(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        $this->actingAs($firstUser, 'sanctum')
            ->postJson('/api/v1/devices/push-token', ['token' => 'shared-device-token'])
            ->assertCreated();

        $this->actingAs($secondUser, 'sanctum')
            ->postJson('/api/v1/devices/push-token', ['token' => 'shared-device-token'])
            ->assertCreated();

        $this->assertSame(1, DevicePushToken::where('token', 'shared-device-token')->count());
        $this->assertDatabaseHas('device_push_tokens', [
            'token' => 'shared-device-token',
            'user_id' => $secondUser->id,
        ]);
    }

    public function test_a_player_can_only_deregister_their_own_token(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        DevicePushToken::factory()->for($owner)->create(['token' => 'owner-token']);

        $this->actingAs($other, 'sanctum')
            ->deleteJson('/api/v1/devices/push-token', ['token' => 'owner-token'])
            ->assertOk();

        $this->assertDatabaseHas('device_push_tokens', ['token' => 'owner-token', 'user_id' => $owner->id]);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/v1/devices/push-token', ['token' => 'owner-token'])
            ->assertOk();

        $this->assertDatabaseMissing('device_push_tokens', ['token' => 'owner-token']);
    }
}
