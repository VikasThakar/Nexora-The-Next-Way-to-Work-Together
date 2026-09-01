<?php

declare(strict_types=1);

namespace App\Actions\Columns;

use App\Models\BoardColumn;

class UpdateColumn
{
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

        $column->save();

        return $column;
    }
}
