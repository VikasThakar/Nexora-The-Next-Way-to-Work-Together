<?php

declare(strict_types=1);

namespace App\Actions\Labels;

use App\Enums\LabelColor;
use App\Models\Board;
use App\Models\Label;

class CreateLabel
{
    /**
     * @param  array{name: string, color?: string|LabelColor|null}  $attributes
     */
    public function handle(Board $board, array $attributes): Label
    {
        $color = $attributes['color'] ?? null;

        return $board->labels()->create([
            'name' => trim($attributes['name']),
            'color' => $color instanceof LabelColor
                ? $color
                : (LabelColor::tryFrom((string) $color) ?? LabelColor::default()),
        ]);
    }
}
