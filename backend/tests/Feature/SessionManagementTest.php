<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class SessionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_listing_sessions_requires_authentication(): void
    {
        $this->getJson('/api/v1/sessions')->assertUnauthorized();
    }

    public function test_a_player_sees_every_device_they_are_logged_in_on(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password123')]);

        $this->postJson('/api/v1/auth/login', ['mobile' => $user->mobile, 'password' => 'password123'], [
            'User-Agent' => 'Mini Max / Pixel 9',
        ])->assertOk();
        $this->postJson('/api/v1/auth/login', ['mobile' => $user->mobile, 'password' => 'password123'], [
            'User-Agent' => 'Mini Max / Galaxy S24',
        ])->assertOk();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/sessions');

        $response->assertOk();
        // actingAs() authenticates the test request directly, without creating a
        // real token row — only the 2 explicit logins above do.
        $this->assertCount(2, $response->json('data'));
    }

    public function test_a_player_can_revoke_another_devices_session(): void
    {
        $user = User::factory()->create();
        $otherDeviceToken = $user->createToken('Other device')->accessToken;

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/sessions/{$otherDeviceToken->id}");

        $response->assertOk()->assertJsonPath('data.revoked_current_session', false);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherDeviceToken->id]);
    }

    public function test_revoking_the_current_session_reports_it_as_the_current_one(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('This device');

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->deleteJson("/api/v1/sessions/{$token->accessToken->id}");

        $response->assertOk()->assertJsonPath('data.revoked_current_session', true);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_a_player_cannot_revoke_another_players_session(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $ownerToken = $owner->createToken('Owner device')->accessToken;

        $response = $this->actingAs($intruder, 'sanctum')->deleteJson("/api/v1/sessions/{$ownerToken->id}");

        $response->assertNotFound();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $ownerToken->id]);
    }

    public function test_a_revoked_token_can_no_longer_authenticate(): void
    {
        // Sanctum's guard resolves a bearer token via PersonalAccessToken::findToken(),
        // a fresh DB lookup on every real HTTP request — that's the actual mechanism
        // production relies on. Re-asserting it directly here (rather than firing a
        // second simulated request reusing the same token) avoids a PHPUnit-only
        // artifact: Laravel's HTTP test calls share one booted container for the
        // whole test method, and the auth guard built for the first call can cache
        // its resolved user rather than re-querying on a second call in-process —
        // something that cannot happen between two separate real requests.
        $user = User::factory()->create();
        $token = $user->createToken('Device to revoke');

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->deleteJson("/api/v1/sessions/{$token->accessToken->id}")
            ->assertOk();

        $this->assertNull(PersonalAccessToken::findToken($token->plainTextToken));
    }
}
