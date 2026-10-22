<?php

declare(strict_types=1);

namespace App\Actions\Columns;

use App\Actions\Concerns\LocksBoardStructure;
use App\Models\BoardColumn;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\BoardBroadcaster;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class UpdateColumn
{
    use LocksBoardStructure;

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly BoardBroadcaster $broadcaster,
    ) {}

    /**
     * @param  array{name?: string, is_done?: bool}  $attributes
     */
    public function handle(BoardColumn $column, array $attributes, ?User $actor = null): BoardColumn
    {
        if (array_key_exists('name', $attributes)) {
            $column->name = trim((string) $attributes['name']);
        }

        if (array_key_exists('is_done', $attributes)) {
            $column->is_done = (bool) $attributes['is_done'];
        }

        // Read before the save, while Eloquent still knows what changed. An
        // edit that changed nothing records nothing — the settings screen saves
        // a column on blur, so most of those saves are no-ops.
        $changes = $this->diff($column);

        if ($changes === []) {
            return $column;
        }

        DB::transaction(function () use ($column, $changes, $actor): void {
            $this->lockBoard((int) $column->board_id);

            if (isset($changes['name'])) {
                $this->assertNameFree($column);
            }

            $column->save();

            $this->activity->columnUpdated($column, $changes, $actor);
            $this->broadcaster->structureChanged((int) $column->board_id);
        });

        return $column;
    }

    /**
     * Names are unique per tab, ignoring case. A change of case alone is a
     * rename of the same column, not a clash with itself.
     */
    private function assertNameFree(BoardColumn $column): void
    {
        $name = mb_strtolower((string) $column->name);

        $taken = BoardColumn::query()
            ->where('board_tab_id', $column->board_tab_id)
            ->whereKeyNot($column->getKey())
            ->pluck('name')
            ->contains(fn ($other): bool => mb_strtolower(trim((string) $other)) === $name);

        if ($taken) {
            throw new RuntimeException('This tab already has a column called "'.$column->name.'".');
        }
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function diff(BoardColumn $column): array
    {
        $changes = [];

        foreach (array_keys($column->getDirty()) as $field) {
            $changes[$field] = [
                $column->getOriginal($field),
                $column->getAttribute($field),
            ];
        }

        return $changes;
    }
}
