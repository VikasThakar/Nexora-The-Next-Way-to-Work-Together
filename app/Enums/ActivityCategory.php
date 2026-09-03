<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The coarse grouping of an activity, stored in `activity_log.log_name`.
 *
 * Spatie calls this the log name and lets a caller pass any string. Making it
 * an enum means the Activity screen's category filter is a `log_name = ?`
 * lookup against an indexed column with a known, closed set of values, rather
 * than a guess about what some caller wrote months ago.
 *
 * Every App\Enums\ActivityType belongs to exactly one of these — see
 * ActivityType::category(), which is where a new type is forced to choose.
 *
 * The values here must never collide with an ActivityType value: the Activity
 * screen accepts either in one filter parameter and resolves a category first.
 * Tests\Unit\ActivityTaxonomyTest holds that line.
 */
enum ActivityCategory: string
{
    case Boards = 'boards';
    case Tickets = 'tickets';
    case Comments = 'comments';
    case Documentation = 'documentation';
    case Members = 'members';
    case Settings = 'settings';

    public function label(): string
    {
        return match ($this) {
            self::Boards => 'Boards',
            self::Tickets => 'Tickets',
            self::Comments => 'Comments',
            self::Documentation => 'Documentation',
            self::Members => 'Members',
            self::Settings => 'Settings',
        };
    }

    /**
     * value => label, for the filter dropdown.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
