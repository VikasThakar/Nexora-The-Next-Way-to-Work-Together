<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LabelColor;
use App\Models\Board;
use App\Models\Label;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Label>
 */
class LabelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'board_id' => Board::factory(),
            'name' => fake()->unique()->word(),
            'color' => fake()->randomElement(LabelColor::cases()),
        ];
    }

    public function color(LabelColor $color): static
    {
        return $this->state(fn (array $attributes): array => ['color' => $color]);
    }
}
