<?php

declare(strict_types=1);

namespace App\Actions\Columns;

use App\Models\BoardColumn;
use App\Services\ActivityLogger;

class UpdateColumn
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * @param  array{name?: string, is_done?: bool}  $attributes
     */
    public function handle(BoardColumn $column, array $attributes): BoardColumn
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

        $column->save();

        if ($changes !== []) {
            $this->activity->columnUpdated($column, $changes);
        }

        return $column;
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
