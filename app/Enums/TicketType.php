<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What kind of work a ticket is.
 *
 * The only genuinely new field in this phase, and it is new because nothing
 * existing could carry it. Priority answers "how urgent", labels are free-form
 * board vocabulary that every board defines differently, and neither answers
 * "is this a defect or a new capability" — the question every triage
 * conversation and every release note starts from.
 *
 * Labels were the obvious candidate to reuse and were rejected: they are
 * per-board, so "Bug" would exist on one board and not another, they are
 * many-per-ticket where this is exactly one, and a board administrator can
 * rename or delete them. A field that reporting will eventually group by cannot
 * rest on that.
 *
 * Stored as a stable snake_case string, like TicketPriority, so the set can be
 * relabelled or reordered without touching data.
 */
enum TicketType: string
{
    case Bug = 'bug';
    case Task = 'task';
    case Feature = 'feature';

    public function label(): string
    {
        return match ($this) {
            self::Bug => 'Bug',
            self::Task => 'Task',
            self::Feature => 'Feature',
        };
    }

    /**
     * Maps to the variant names x-ui.badge understands, so a type looks the
     * same everywhere it appears.
     *
     * Bug is rose because it is the one that means something is broken. Task
     * and Feature are deliberately quiet: most tickets are one of those, and a
     * board where every card shouts communicates nothing.
     */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Bug => 'rose',
            self::Task => 'slate',
            self::Feature => 'brand',
        };
    }

    /**
     * A single glyph for the Kanban card, where a badge is too heavy.
     *
     * Names match the icon set already used by TicketEventType::icon(), so the
     * card component resolves them the same way.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Bug => 'bug',
            self::Task => 'check',
            self::Feature => 'sparkle',
        };
    }

    /**
     * Task, because it is the honest answer for a ticket nobody classified.
     *
     * It is also what every existing row becomes when the column is added, so
     * the default and the backfill are the same decision rather than two.
     */
    public static function default(): self
    {
        return self::Task;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Cases in the order the client's mock-up lists them: Bug, Task, Feature.
     *
     * @return array<int, self>
     */
    public static function ordered(): array
    {
        return [self::Bug, self::Task, self::Feature];
    }
}
