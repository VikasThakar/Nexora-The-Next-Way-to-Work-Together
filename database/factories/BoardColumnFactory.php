<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Board;
use App\Models\BoardColumn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardColumn>
 */
class BoardColumnFactory extends Factory
{
    public function definition(): array
    {
        return [
            'board_id' => Board::factory(),
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'position' => 0,
            'is_done' => false,
        ];
    }

    public function done(): static
    {
        return $this->state(fn (array $attributes): array => ['is_done' => true]);
    }

    public function at(int $position): static
    {
        return $this->state(fn (array $attributes): array => ['position' => $position]);
    }
}
