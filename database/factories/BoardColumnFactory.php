<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\BoardTab;
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

            // Resolved after board_id, so the tab is always on the column's own
            // board (the database refuses anything else). Defaults to the
            // board's leftmost tab, creating "Main" if the board has none yet.
            'board_tab_id' => fn (array $attributes): int => $this->tabFor((int) $attributes['board_id']),

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

    public function inTab(BoardTab $tab): static
    {
        return $this->state(fn (array $attributes): array => [
            'board_id' => $tab->board_id,
            'board_tab_id' => $tab->getKey(),
        ]);
    }

    private function tabFor(int $boardId): int
    {
        $existing = BoardTab::query()
            ->where('board_id', $boardId)
            ->orderBy('position')
            ->orderBy('id')
            ->value('id');

        return $existing !== null
            ? (int) $existing
            : BoardTab::factory()->main()->create(['board_id' => $boardId])->getKey();
    }
}
