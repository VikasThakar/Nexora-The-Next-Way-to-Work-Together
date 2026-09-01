<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Relationship types between two tickets.
 *
 * A link is stored exactly once, on the source ticket. Whether it reads
 * forwards or backwards depends on which end you are looking from, which is
 * what inwardLabel()/outwardLabel() express:
 *
 *   A --blocks--> B     A: "blocks B"        B: "is blocked by A"
 *   A --relates--> B    A: "relates to B"    B: "relates to A"
 */
enum TicketLinkType: string
{
    case Blocks = 'blocks';
    case RelatesTo = 'relates_to';

    public function label(): string
    {
        return match ($this) {
            self::Blocks => 'Blocks',
            self::RelatesTo => 'Relates to',
        };
    }

    /** How the link reads from the source ticket. */
    public function outwardLabel(): string
    {
        return match ($this) {
            self::Blocks => 'Blocks',
            self::RelatesTo => 'Relates to',
        };
    }

    /** How the same link reads from the target ticket. */
    public function inwardLabel(): string
    {
        return match ($this) {
            self::Blocks => 'Blocked by',
            self::RelatesTo => 'Relates to',
        };
    }

    /**
     * A symmetric type reads the same from both ends, so the UI should not
     * offer both directions when creating one.
     */
    public function isSymmetric(): bool
    {
        return $this === self::RelatesTo;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->outwardLabel();
        }

        return $options;
    }
}
