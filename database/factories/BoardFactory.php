<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Board;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Board>
 */
class BoardFactory extends Factory
{
    public function definition(): array
    {
        $name = Str::headline(fake()->unique()->words(2, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            // Must satisfy config('workspace.ticket_prefix.pattern'): an
            // uppercase letter followed by uppercase letters or digits.
            // Random alphanumerics would start with a digit ~28% of the time
            // and then fail form validation in tests that round-trip a board.
            'ticket_prefix' => Str::upper(fake()->unique()->lexify('????')),
            'description' => fake()->sentence(),
            'settings' => [],
            'created_by_id' => null,
            'archived_at' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => ['archived_at' => now()]);
    }

    public function createdBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => ['created_by_id' => $user->getKey()]);
    }

    /**
     * Attach members after creation.
     *
     * @param  array<int, User>  $users
     */
    public function withMembers(array $users): static
    {
        return $this->afterCreating(function (Board $board) use ($users): void {
            $board->members()->syncWithoutDetaching(
                collect($users)->map->getKey()->all()
            );
        });
    }
}
