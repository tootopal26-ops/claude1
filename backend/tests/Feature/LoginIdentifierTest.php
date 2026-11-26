<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoginIdentifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_by_email_in_a_different_letter_case_succeeds(): void
    {
        $user = User::factory()->create(['email' => 'player@example.com', 'password' => bcrypt('password123')]);

        $response = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'Player@EXAMPLE.com',
            'password' => 'password123',
        ]);

        $response->assertOk()->assertJsonPath('data.user.id', $user->id);
    }

    /**
     * @return list<list<string>>
     */
    public static function mobileFormats(): array
    {
        return [
            ['8273093895'],
            ['+91 8273093895'],
            ['08273093895'],
        ];
    }

    #[DataProvider('mobileFormats')]
    public function test_login_by_mobile_number_in_any_valid_format_succeeds(string $typed): void
    {
        $user = User::factory()->create(['mobile' => '8273093895', 'password' => bcrypt('password123')]);

        $response = $this->postJson('/api/v1/auth/login', [
            'identifier' => $typed,
            'password' => 'password123',
        ]);

        $response->assertOk()->assertJsonPath('data.user.id', $user->id);
    }

    public function test_login_by_username_in_a_different_letter_case_succeeds(): void
    {
        $user = User::factory()->create(['username' => 'camelcaseplayer', 'password' => bcrypt('password123')]);

        $response = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'CamelCasePlayer',
            'password' => 'password123',
        ]);

        $response->assertOk()->assertJsonPath('data.user.id', $user->id);
    }

    public function test_wrong_password_returns_the_generic_message(): void
    {
        User::factory()->create(['email' => 'knownplayer@example.com', 'password' => bcrypt('password123')]);

        $response = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'knownplayer@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.identifier.0', 'These credentials do not match our records.');
    }

    public function test_an_unknown_identifier_returns_the_same_generic_message_as_a_wrong_password(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'nobody-here@example.com',
            'password' => 'whatever',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.identifier.0', 'These credentials do not match our records.');
    }

    public function test_a_banned_user_is_told_their_account_is_suspended(): void
    {
        User::factory()->create(['email' => 'banned@example.com', 'password' => bcrypt('password123'), 'is_banned' => true]);

        $response = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'banned@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.identifier.0', 'This account has been suspended.');
    }

    public function test_neither_identifier_nor_mobile_is_rejected_as_missing(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'password' => 'password123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['identifier', 'mobile']);
    }
}
