<?php

namespace Database\Factories;

use App\Models\StaffUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<StaffUser>
 */
class StaffUserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'display_name' => fake()->name(),
            // Faker's userName() can include dots, which fail the app's
            // alpha_dash validation on a form re-save — keep this charset
            // identical to what StaffForm actually accepts.
            'username' => fake()->unique()->regexify('[a-z][a-z0-9_]{7,14}'),
            'password' => static::$password ??= Hash::make('password'),
            'is_super_admin' => false,
            'is_active' => true,
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_super_admin' => true,
        ]);
    }
}
