<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ticket priority.
 *
 * The stored value is a stable snake_case string so the set can be reordered
 * or relabelled without a data migration. Ordering is expressed by weight()
 * rather than by the column value, because alphabetical ordering of the
 * strings would be meaningless.
 */
enum TicketPriority: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case NiceToHave = 'nice_to_have';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::High => 'High',
            self::Medium => 'Medium',
            self::Low => 'Low',
            self::NiceToHave => 'Nice-to-have',
        };
    }

    /**
     * Lower is more urgent. Used for "priority" sorting in board and list views.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::High => 1,
            self::Medium => 2,
            self::Low => 3,
            self::NiceToHave => 4,
        };
    }

    /**
     * Maps to the variant names understood by the x-ui.badge component, so a
     * priority looks identical everywhere it appears.
     */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Critical => 'rose',
            self::High => 'amber',
            self::Medium => 'brand',
            self::Low => 'slate',
            self::NiceToHave => 'slate',
        };
    }

    /**
     * Small colour chip used on Kanban cards, where a full badge is too heavy.
     */
    public function dotClass(): string
    {
        return match ($this) {
            self::Critical => 'bg-rose-500',
            self::High => 'bg-amber-500',
            self::Medium => 'bg-brand-500',
            self::Low => 'bg-slate-400',
            self::NiceToHave => 'bg-slate-300',
        };
    }

    public static function default(): self
    {
        return self::Medium;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Cases ordered most urgent first.
     *
     * @return array<int, self>
     */
    public static function ordered(): array
    {
        $cases = self::cases();

        usort($cases, fn (self $a, self $b): int => $a->weight() <=> $b->weight());

        return $cases;
    }
}
