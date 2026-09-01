<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The palette a board label may use.
 *
 * Deliberately a closed set rather than a free-text hex value: the classes are
 * written out as literal strings below so Tailwind's scanner can see them at
 * build time. A user-supplied colour would either be purged from the stylesheet
 * or force inline styles.
 */
enum LabelColor: string
{
    case Slate = 'slate';
    case Brand = 'brand';
    case Emerald = 'emerald';
    case Amber = 'amber';
    case Rose = 'rose';
    case Violet = 'violet';
    case Cyan = 'cyan';

    public function label(): string
    {
        return match ($this) {
            self::Slate => 'Grey',
            self::Brand => 'Blue',
            self::Emerald => 'Green',
            self::Amber => 'Amber',
            self::Rose => 'Red',
            self::Violet => 'Violet',
            self::Cyan => 'Cyan',
        };
    }

    /** Chip styling used wherever a label is rendered. */
    public function chipClasses(): string
    {
        return match ($this) {
            self::Slate => 'bg-slate-100 text-slate-700 ring-slate-200',
            self::Brand => 'bg-brand-50 text-brand-700 ring-brand-200',
            self::Emerald => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            self::Amber => 'bg-amber-50 text-amber-700 ring-amber-200',
            self::Rose => 'bg-rose-50 text-rose-700 ring-rose-200',
            self::Violet => 'bg-violet-50 text-violet-700 ring-violet-200',
            self::Cyan => 'bg-cyan-50 text-cyan-700 ring-cyan-200',
        };
    }

    /** Solid swatch used in the colour picker. */
    public function swatchClass(): string
    {
        return match ($this) {
            self::Slate => 'bg-slate-400',
            self::Brand => 'bg-brand-500',
            self::Emerald => 'bg-emerald-500',
            self::Amber => 'bg-amber-500',
            self::Rose => 'bg-rose-500',
            self::Violet => 'bg-violet-500',
            self::Cyan => 'bg-cyan-500',
        };
    }

    public static function default(): self
    {
        return self::Slate;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
