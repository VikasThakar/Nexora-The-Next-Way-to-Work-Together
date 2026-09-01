<?php

declare(strict_types=1);

namespace App\Actions\Labels;

use App\Enums\LabelColor;
use App\Models\Label;

class UpdateLabel
{
    /**
     * @param  array{name?: string, color?: string|LabelColor|null}  $attributes
     */
    public function handle(Label $label, array $attributes): Label
    {
        if (array_key_exists('name', $attributes)) {
            $label->name = trim((string) $attributes['name']);
        }

        if (array_key_exists('color', $attributes)) {
            $color = $attributes['color'];

            $label->color = $color instanceof LabelColor
                ? $color
                : (LabelColor::tryFrom((string) $color) ?? $label->color);
        }

        $label->save();

        return $label;
    }
}
