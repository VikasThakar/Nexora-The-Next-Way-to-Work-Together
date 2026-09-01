<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an AI run is allowed to do.
 *
 * The two modes differ in exactly one respect, and it is the one that matters:
 * whether the model may change code.
 *
 *   Suggest  reads the ticket and the repository and writes an opinion. It
 *            never modifies a working tree, never pushes and never opens a
 *            pull request. This is the safe default.
 *   Apply    works in an isolated clone, on a new branch, and finishes by
 *            opening a pull request for a human to review. It never pushes to
 *            a protected branch and never merges.
 *
 * `Off` is deliberately part of the same enum rather than a separate boolean.
 * A board's automatic behaviour is one setting with three values, so the code
 * that reads it cannot forget to check an "enabled" flag as well.
 */
enum AiRunMode: string
{
    case Off = 'off';
    case Suggest = 'suggest';
    case Apply = 'apply';

    /**
     * The raw value, for config files that cannot call a method.
     */
    public const OFF = 'off';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off',
            self::Suggest => 'Suggest only',
            self::Apply => 'Apply (open a pull request)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Off => 'No AI run is started when a customer raises a ticket.',
            self::Suggest => 'Claude analyses the ticket and posts an internal note. No code is changed.',
            self::Apply => 'Claude works in an isolated clone and opens a pull request for review. Nothing is merged.',
        };
    }

    /**
     * May a run actually be started in this mode?
     *
     * `Off` is a setting, not a run: no ai_runs row is ever created for it.
     */
    public function startsARun(): bool
    {
        return $this !== self::Off;
    }

    /**
     * Does this mode need a writable checkout, a git remote and a pull request?
     */
    public function writesCode(): bool
    {
        return $this === self::Apply;
    }

    /**
     * The mode used when nothing says otherwise.
     *
     * Off, so a board that has never been configured cannot spend money or
     * clone a repository because somebody filed a ticket.
     */
    public static function default(): self
    {
        return self::Off;
    }

    /**
     * The modes an ai_runs row may actually carry.
     *
     * @return array<int, self>
     */
    public static function runnable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $mode): bool => $mode->startsARun()));
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<int, string> */
    public static function runnableValues(): array
    {
        return array_map(fn (self $mode): string => $mode->value, self::runnable());
    }
}
