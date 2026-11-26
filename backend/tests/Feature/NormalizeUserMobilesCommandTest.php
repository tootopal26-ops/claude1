<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NormalizeUserMobilesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function insertRawUser(array $overrides = []): int
    {
        return DB::table('users')->insertGetId(array_merge([
            'username' => 'rawuser'.fake()->unique()->numberBetween(1, 999999),
            'mobile' => '9'.fake()->unique()->numerify('#########'),
            'email' => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'),
            'referral_code' => 'RAW'.fake()->unique()->numberBetween(1, 999999),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_dry_run_reports_changes_without_saving_anything(): void
    {
        $id = $this->insertRawUser(['mobile' => '+91 8273093895', 'email' => 'Dry.Run@Example.com']);

        $this->artisan('users:normalize-mobiles', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('Changed: 1')
            ->assertSuccessful();

        $user = User::find($id);
        $this->assertSame('+91 8273093895', $user->mobile);
        $this->assertSame('Dry.Run@Example.com', $user->email);
    }

    public function test_a_real_run_normalises_mobile_and_email(): void
    {
        $id = $this->insertRawUser(['mobile' => '08273093895', 'email' => 'Real.Run@Example.com']);

        $this->artisan('users:normalize-mobiles')->assertSuccessful();

        $user = User::find($id);
        $this->assertSame('8273093895', $user->mobile);
        $this->assertSame('real.run@example.com', $user->email);
    }

    public function test_a_mobile_that_does_not_normalise_to_ten_digits_is_reported_and_left_unchanged(): void
    {
        $id = $this->insertRawUser(['mobile' => '12345']);

        $this->artisan('users:normalize-mobiles')
            ->expectsOutputToContain('Skipped (conflicts): 1')
            ->expectsOutputToContain('does not normalise to exactly 10 digits')
            ->assertSuccessful();

        $this->assertSame('12345', User::find($id)->mobile);
    }

    public function test_two_users_whose_mobiles_would_collide_after_normalisation_are_both_left_unchanged(): void
    {
        $idA = $this->insertRawUser(['mobile' => '919988776655']); // -> 9988776655
        $idB = $this->insertRawUser(['mobile' => '09988776655']); // -> 9988776655 too

        $this->artisan('users:normalize-mobiles')
            ->expectsOutputToContain('Skipped (conflicts): 2')
            ->expectsOutputToContain('collides with another user')
            ->assertSuccessful();

        $this->assertSame('919988776655', User::find($idA)->mobile);
        $this->assertSame('09988776655', User::find($idB)->mobile);
    }

    public function test_a_normalised_mobile_that_collides_with_an_existing_unchanged_user_is_left_unchanged(): void
    {
        $this->insertRawUser(['mobile' => '9988776655']); // already clean, won't change
        $changing = $this->insertRawUser(['mobile' => '09988776655']); // would normalise to the same value

        $this->artisan('users:normalize-mobiles')
            ->expectsOutputToContain('Skipped (conflicts): 1')
            ->assertSuccessful();

        $this->assertSame('09988776655', User::find($changing)->mobile);
    }

    public function test_two_users_whose_emails_would_collide_after_case_folding_are_both_left_unchanged(): void
    {
        $idA = $this->insertRawUser(['email' => 'Same.Address@Example.com']);
        $idB = $this->insertRawUser(['email' => 'same.address@example.com'.' ']); // trims+folds to the same address

        $this->artisan('users:normalize-mobiles')
            ->expectsOutputToContain('collides with another user')
            ->assertSuccessful();

        $this->assertSame('Same.Address@Example.com', User::find($idA)->email);
        $this->assertSame('same.address@example.com ', User::find($idB)->email);
    }

    public function test_a_digits_only_username_is_reported_but_not_renamed(): void
    {
        $this->insertRawUser(['username' => '98765432']);

        $this->artisan('users:normalize-mobiles')
            ->expectsOutputToContain('digits only')
            ->expectsOutputToContain('98765432')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['username' => '98765432']);
    }

    public function test_an_already_clean_user_is_reported_as_unchanged(): void
    {
        $this->insertRawUser(['mobile' => '8273093895', 'email' => 'already.clean@example.com']);

        $this->artisan('users:normalize-mobiles')
            ->expectsOutputToContain('Changed: 0')
            ->expectsOutputToContain('Skipped (conflicts): 0')
            ->assertSuccessful();
    }
}
