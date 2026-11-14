<?php

namespace Tests\Feature\Referrals;

use App\Domain\Referrals\ReferralService;
use App\Domain\Settings\PlatformSettingsService;
use App\Models\Referral;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReferralTest extends TestCase
{
    use RefreshDatabase;

    private function enableReferrals(string $newPlayerReward = '20.00', string $referrerReward = '30.00', ?int $maxPerReferrer = null): void
    {
        app(PlatformSettingsService::class)->setMany([
            'referral_enabled' => true,
            'referral_new_player_reward' => $newPlayerReward,
            'referral_referrer_reward' => $referrerReward,
            'referral_max_per_referrer' => $maxPerReferrer,
        ], StaffUser::factory()->superAdmin()->create());
    }

    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'username' => 'newplayer'.fake()->unique()->numberBetween(1, 999999),
            'mobile' => fake()->unique()->numerify('9##########'),
            'email' => fake()->unique()->safeEmail(),
            // Same fixed DOB the existing RegistrationTest uses — clears
            // DobNotInExclusionWindow and MeetsMinimumAge without being date-sensitive.
            'date_of_birth' => '1995-05-01',
            'state' => 'Delhi',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accept_terms' => true,
        ], $overrides);
    }

    public function test_every_registered_user_gets_a_unique_referral_code(): void
    {
        $users = User::factory()->count(20)->create();

        $this->assertSame(20, $users->pluck('referral_code')->unique()->count());
        $users->each(fn (User $user) => $this->assertNotNull($user->referral_code));
    }

    public function test_a_valid_referral_code_credits_both_players_on_signup(): void
    {
        $this->enableReferrals('20.00', '30.00');
        $referrer = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/register', $this->registerPayload([
            'referral_code' => $referrer->referral_code,
        ]));

        $response->assertCreated();
        $response->assertJsonPath('data.referral.new_player_reward', '20.00');

        $newUser = User::where('username', $response->json('data.user.username'))->first();

        $this->assertSame((string) $referrer->id, (string) $newUser->referred_by_user_id);
        $this->assertSame('20.00', (string) $newUser->wallet->deposit_balance);
        $this->assertSame('30.00', (string) $referrer->refresh()->wallet->deposit_balance);

        $referral = Referral::where('referred_user_id', $newUser->id)->first();
        $this->assertNotNull($referral);
        $this->assertSame('completed', $referral->status);
        $this->assertSame($referrer->id, $referral->referrer_user_id);
    }

    public function test_an_invalid_referral_code_rejects_registration_and_credits_nothing(): void
    {
        $this->enableReferrals();
        $payload = $this->registerPayload(['referral_code' => 'DOES-NOT-EXIST']);

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('referral_code');
        // The whole registration transaction rolled back — an invalid code must not leave
        // a half-created account behind either.
        $this->assertDatabaseMissing('users', ['username' => $payload['username']]);
        $this->assertSame(0, Referral::count());
    }

    public function test_registration_still_succeeds_with_no_referral_code(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->registerPayload());

        $response->assertCreated();
        $this->assertNull($response->json('data.referral'));
        $this->assertSame(0, Referral::count());
    }

    public function test_a_submitted_code_is_a_safe_no_op_while_the_system_is_disabled(): void
    {
        $referrer = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/register', $this->registerPayload([
            'referral_code' => $referrer->referral_code,
        ]));

        $response->assertCreated();
        $this->assertNull($response->json('data.referral'));
        $this->assertSame(0, Referral::count());
        $this->assertSame('0.00', (string) $referrer->refresh()->wallet->deposit_balance);
    }

    public function test_a_player_cannot_refer_themselves(): void
    {
        $this->enableReferrals();
        $user = User::factory()->create();

        $this->expectException(ValidationException::class);
        app(ReferralService::class)->applyReferral($user, $user->referral_code);
    }

    public function test_calling_apply_referral_twice_for_the_same_account_is_a_no_op(): void
    {
        $this->enableReferrals();
        $referrer = User::factory()->create();
        $newUser = User::factory()->create();

        $first = app(ReferralService::class)->applyReferral($newUser, $referrer->referral_code);
        $this->assertNotNull($first);

        $second = app(ReferralService::class)->applyReferral($newUser->refresh(), $referrer->referral_code);
        $this->assertNull($second);

        $this->assertSame(1, Referral::count());
        $this->assertSame('30.00', (string) $referrer->refresh()->wallet->deposit_balance);
    }

    public function test_changing_reward_amounts_does_not_affect_past_referrals(): void
    {
        $this->enableReferrals('20.00', '30.00');
        $referrer = User::factory()->create();
        $firstNewUser = User::factory()->create();

        $referral = app(ReferralService::class)->applyReferral($firstNewUser, $referrer->referral_code);

        $this->enableReferrals('50.00', '75.00');
        $secondNewUser = User::factory()->create();
        $second = app(ReferralService::class)->applyReferral($secondNewUser, $referrer->referral_code);

        $this->assertSame('20.00', (string) $referral->refresh()->new_player_reward);
        $this->assertSame('30.00', (string) $referral->refresh()->referrer_reward);
        $this->assertSame('50.00', (string) $second->new_player_reward);
        $this->assertSame('75.00', (string) $second->referrer_reward);
    }

    public function test_referral_max_per_referrer_is_enforced(): void
    {
        $this->enableReferrals(maxPerReferrer: 2);
        $referrer = User::factory()->create();

        app(ReferralService::class)->applyReferral(User::factory()->create(), $referrer->referral_code);
        app(ReferralService::class)->applyReferral(User::factory()->create(), $referrer->referral_code);

        $this->expectException(ValidationException::class);
        app(ReferralService::class)->applyReferral(User::factory()->create(), $referrer->referral_code);
    }

    public function test_concurrent_signups_never_exceed_the_referrer_cap(): void
    {
        if (! Process::run('mysql --version')->successful()) {
            $this->markTestSkipped('mysql client not available to prepare the concurrency fixture.');
        }

        $env = ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'minimax_testing'];

        $migrate = Process::env($env)->path(base_path())->run('php artisan migrate:fresh --no-interaction');
        $this->assertTrue($migrate->successful(), $migrate->errorOutput());

        $seed = Process::env($env)->path(base_path())->run('php artisan app:seed-referral-concurrency-fixture 3');
        $this->assertTrue($seed->successful(), $seed->errorOutput());

        $fixture = json_decode(trim($seed->output()), true, flags: JSON_THROW_ON_ERROR);
        $code = $fixture['code'];

        $results = Process::pool(function ($pool) use ($env, $code) {
            for ($i = 0; $i < 10; $i++) {
                $pool->as((string) $i)
                    ->env($env)
                    ->path(base_path())
                    ->command("php artisan app:attempt-referral-signup {$code} {$i}");
            }
        })->start()->wait();

        $referred = collect($results)->filter(fn ($result) => str_contains($result->output(), 'REFERRED'));

        $this->assertCount(3, $referred, 'Exactly the configured cap of concurrent signups should be credited as referrals.');

        config(['database.connections.concurrency_check' => array_merge(
            config('database.connections.mysql'),
            ['database' => 'minimax_testing'],
        )]);
        DB::purge('concurrency_check');

        $referralRows = DB::connection('concurrency_check')->table('referrals')->count();
        $this->assertSame(3, $referralRows, 'The referrals table must never hold more rows than the configured cap, however many processes raced.');
    }
}
