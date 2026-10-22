<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Board;
use App\Models\BoardTab;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BoardTab>
 */
class BoardTabFactory extends Factory
{
    public function definition(): array
    {
        return [
            'board_id' => Board::factory(),
            'name' => Str::headline(fake()->unique()->words(2, true)),

            // Resolved after the name, so an overridden name gets its own slug.
            'slug' => fn (array $attributes): string => Str::slug((string) $attributes['name']) ?: 'tab',

            // Appended to the right of the board's existing tabs.
            'position' => fn (array $attributes): int => $this->nextPosition((int) $attributes['board_id']),
        ];
    }

    /** The tab every board starts with. */
    public function main(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => BoardTab::DEFAULT_NAME,
            'slug' => 'main',
        ]);
    }

    private function nextPosition(int $boardId): int
    {
        $tabs = BoardTab::query()->where('board_id', $boardId);

        return $tabs->exists() ? (int) $tabs->max('position') + 1 : 0;
    }
}
