<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Test/synthetic users only. The shared factory password is for automated tests and is never
 * seeded into any real database.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public const TEST_PASSWORD = 'password';

    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make(self::TEST_PASSWORD),
            'remember_token' => Str::random(10),
            'role' => Role::Viewer,
            'is_active' => true,
        ];
    }

    public function admin(): static
    {
        return $this->state(['role' => Role::Admin]);
    }

    public function editor(): static
    {
        return $this->state(['role' => Role::Editor]);
    }

    public function viewer(): static
    {
        return $this->state(['role' => Role::Viewer]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }
}
