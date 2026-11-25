<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_player_can_register_and_receives_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'username' => 'player_one',
            'mobile' => '9123456789',
            'email' => 'player@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accept_terms' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.username', 'player_one')
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'username', 'mobile']]]);

        $this->assertDatabaseHas('users', ['username' => 'player_one', 'mobile' => '9123456789']);
    }

    public function test_registration_rejects_a_duplicate_mobile_number(): void
    {
        User::factory()->create(['mobile' => '9123456789']);

        $response = $this->postJson('/api/v1/auth/register', [
            'username' => 'someone_else',
            'mobile' => '9123456789',
            'email' => 'someone_else@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accept_terms' => true,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('mobile');
    }

    public function test_a_registered_player_can_log_in_with_mobile_and_password(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'username' => 'player_two',
            'mobile' => '9123456780',
            'email' => 'player2@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accept_terms' => true,
        ])->assertCreated();

        $response = $this->postJson('/api/v1/auth/login', [
            'mobile' => '9123456780',
            'password' => 'password123',
        ]);

        $response->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_login_rejects_an_incorrect_password(): void
    {
        User::factory()->create(['mobile' => '9123456781']);

        $response = $this->postJson('/api/v1/auth/login', [
            'mobile' => '9123456781',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('mobile');
    }

    public function test_authenticated_player_can_fetch_their_own_profile(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me');

        $response->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_registration_without_date_of_birth_or_state_succeeds_and_stores_neither(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'username' => 'no_dob_player',
            'mobile' => '9123456782',
            'email' => 'no_dob_player@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accept_terms' => true,
        ]);

        $response->assertCreated();

        $user = User::where('username', 'no_dob_player')->firstOrFail();
        $this->assertNull($user->date_of_birth);
        $this->assertNull($user->state);
    }

    public function test_an_old_app_build_that_still_sends_date_of_birth_and_state_still_succeeds_without_storing_them(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'username' => 'old_build_player',
            'mobile' => '9123456783',
            'email' => 'old_build_player@example.com',
            'date_of_birth' => '1995-05-01',
            'state' => 'Maharashtra',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accept_terms' => true,
        ]);

        $response->assertCreated();

        $user = User::where('username', 'old_build_player')->firstOrFail();
        $this->assertNull($user->date_of_birth);
        $this->assertNull($user->state);
    }

    public function test_a_username_containing_a_space_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'username' => 'has space',
            'mobile' => '9123456784',
            'email' => 'has_space@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accept_terms' => true,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('username');
    }
}
