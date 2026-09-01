<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
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
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Team,
            'deactivated_at' => null,
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes): array => ['role' => UserRole::Admin]);
    }

    public function team(): static
    {
        return $this->state(fn (array $attributes): array => ['role' => UserRole::Team]);
    }

    public function customer(): static
    {
        return $this->state(fn (array $attributes): array => ['role' => UserRole::Customer]);
    }

    public function role(UserRole $role): static
    {
        return $this->state(fn (array $attributes): array => ['role' => $role]);
    }

    public function deactivated(): static
    {
        return $this->state(fn (array $attributes): array => ['deactivated_at' => now()]);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => ['email_verified_at' => null]);
    }
}
