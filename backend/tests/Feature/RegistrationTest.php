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

    public function test_a_digits_only_username_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'username' => '12345678',
            'mobile' => '9123456785',
            'email' => 'digits_only@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accept_terms' => true,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('username');
    }

    public function test_a_nine_digit_mobile_number_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'username' => 'nine_digit_player',
            'mobile' => '912345678',
            'email' => 'nine_digit@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accept_terms' => true,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('mobile');
    }

    public function test_an_eleven_digit_mobile_number_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'username' => 'eleven_digit_player',
            'mobile' => '91234567890',
            'email' => 'eleven_digit@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accept_terms' => true,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('mobile');
    }

    public function test_a_mobile_number_containing_letters_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'username' => 'letters_player',
            'mobile' => '91234abc90',
            'email' => 'letters_player@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accept_terms' => true,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('mobile');
    }

    public function test_registration_normalises_a_mobile_number_with_country_code_and_spaces(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'username' => 'normalised_player',
            'mobile' => '+91 8273093895',
            'email' => 'normalised_player@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accept_terms' => true,
        ]);

        $response->assertCreated()->assertJsonPath('data.user.mobile', '8273093895');
        $this->assertDatabaseHas('users', ['username' => 'normalised_player', 'mobile' => '8273093895']);
    }

    public function test_a_duplicate_mobile_number_is_rejected_after_normalisation(): void
    {
        User::factory()->create(['mobile' => '8273093895']);

        $response = $this->postJson('/api/v1/auth/register', [
            'username' => 'duplicate_after_normalise',
            'mobile' => '08273093895',
            'email' => 'duplicate_after_normalise@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accept_terms' => true,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('mobile');
    }
}
