<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who decided that this run should happen.
 *
 * Kept separate from `triggered_by_id` (which records *which person* the run is
 * attributable to) because the two answer different questions and only one of
 * them governs cost:
 *
 *   Automatic  nobody chose this. A customer filed a ticket and the board is
 *              configured to react. These are the runs that can run away, so
 *              they are the ones the daily cap exists for.
 *   Manual     a member of staff pressed a button. Authorized, attributable,
 *              and subject to a much looser cap.
 *
 * An automatic run still carries the customer's user id in `triggered_by_id`,
 * because knowing which ticket author caused the spend is useful — but that id
 * grants nothing. Authorization for a manual run is decided by AiRunPolicy.
 */
enum AiRunTrigger: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Automatic => 'Automatic',
            self::Manual => 'Manual',
        };
    }

    public function isAutomatic(): bool
    {
        return $this === self::Automatic;
    }

    public function isManual(): bool
    {
        return $this === self::Manual;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
