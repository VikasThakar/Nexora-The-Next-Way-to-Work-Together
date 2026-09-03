<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ActivityCategory;
use App\Enums\ActivityType;

/**
 * The Activity screen's filter bar, as a value object.
 *
 * Every field here arrives from the query string, because a filtered feed
 * should be a link somebody can paste into Slack — "here is everything that
 * moved on the platform board last week" is a URL, not a screenshot.
 *
 * Which means every field is attacker-controlled, and the rule is the same one
 * App\Support\TicketFilters follows: a filter may narrow a feed and can never
 * widen it. They are composed on top of App\Models\Activity::readableBy(), so
 * the worst a hostile value can do is return fewer rows — and the two that
 * could be used to probe (the board slug and the type) are corrected rather
 * than trusted:
 *
 *   an unknown or unreachable board slug yields an empty feed rather than
 *   every board, and is indistinguishable from a board that does not exist, so
 *   the filter cannot be used to enumerate boards. That resolution needs
 *   BoardAccess and therefore lives in App\Services\ActivityReader;
 *
 *   an unrecognised type narrows to nothing (Activity::ofType), not to
 *   everything;
 *
 *   the date range is parsed by StatsPeriod, which corrects instead of
 *   trusting: reversed dates are swapped, an absurd span is clamped. A
 *   hand-edited URL cannot ask the database for ten years of history.
 */
class ActivityFilters
{
    /**
     * The range value meaning "do not filter by date at all".
     *
     * Deliberately not a StatsPeriod preset. StatsPeriod has no unbounded
     * option and should not gain one — it backs the reporting screens, where
     * every figure is "per period" and an unbounded range would be a different
     * question. A feed is the opposite: its natural state is everything, newest
     * first, and pagination is what keeps that affordable.
     */
    public const ALL_TIME = '';

    public function __construct(
        public readonly string $search = '',
        public readonly string $boardSlug = '',
        public readonly ?int $userId = null,
        public readonly string $type = '',
        public readonly string $range = self::ALL_TIME,
        public readonly string $customFrom = '',
        public readonly string $customTo = '',
    ) {}

    /**
     * Build from raw Livewire component state, discarding anything unrecognised.
     *
     * The type is validated here as well as in Activity::ofType(), so that
     * activeCount() below does not report a filter the query is going to
     * ignore — a badge saying "1 filter" over an unfiltered list is its own
     * small lie.
     *
     * @param  array<string, mixed>  $state
     */
    public static function fromArray(array $state): self
    {
        $type = trim((string) ($state['type'] ?? ''));

        if ($type !== '' && ! self::isKnownType($type)) {
            $type = '';
        }

        $range = (string) ($state['range'] ?? self::ALL_TIME);

        if ($range !== self::ALL_TIME && ! array_key_exists($range, StatsPeriod::options())) {
            $range = self::ALL_TIME;
        }

        $userId = $state['userId'] ?? null;

        return new self(
            search: trim((string) ($state['search'] ?? '')),
            boardSlug: trim((string) ($state['boardSlug'] ?? '')),
            userId: ($userId === null || $userId === '' || (int) $userId <= 0) ? null : (int) $userId,
            type: $type,
            range: $range,
            customFrom: trim((string) ($state['customFrom'] ?? '')),
            customTo: trim((string) ($state['customTo'] ?? '')),
        );
    }

    public static function isKnownType(string $value): bool
    {
        return ActivityCategory::tryFrom($value) !== null
            || ActivityType::tryFrom($value) !== null;
    }

    public function hasDateRange(): bool
    {
        return $this->range !== self::ALL_TIME;
    }

    /**
     * The period to filter by, or null when the feed is unbounded.
     */
    public function period(string $timezone): ?StatsPeriod
    {
        if (! $this->hasDateRange()) {
            return null;
        }

        return $this->range === StatsPeriod::CUSTOM
            ? StatsPeriod::between($this->customFrom, $this->customTo, $timezone)
            : StatsPeriod::preset($this->range, $timezone);
    }

    public function isEmpty(): bool
    {
        return $this->activeCount() === 0;
    }

    /**
     * How many filters are narrowing the feed, for the "clear filters" control.
     *
     * The search box is counted: somebody who cannot see why the list is short
     * has usually forgotten what they typed in it.
     */
    public function activeCount(): int
    {
        return count(array_filter([
            $this->search !== '',
            $this->boardSlug !== '',
            $this->userId !== null,
            $this->type !== '',
            $this->hasDateRange(),
        ]));
    }

    /**
     * The options for the type dropdown, grouped the way the screen renders
     * them: the six categories, then the handful of individual types worth
     * asking for on their own.
     *
     * @return array<string, array<string, string>>
     */
    public static function typeOptions(): array
    {
        $detail = [];

        foreach (ActivityType::filterable() as $type) {
            $detail[$type->value] = $type->label();
        }

        return [
            'Category' => ActivityCategory::options(),
            'In detail' => $detail,
        ];
    }
}
