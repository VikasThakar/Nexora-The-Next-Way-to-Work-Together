<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * The date range a statistics screen is looking at.
 *
 * One object rather than a pair of loose dates, because a date range in a
 * reporting screen is never just two dates — it is two dates *in a timezone*,
 * and getting that wrong is how a report quietly reads a day short.
 *
 * The distinction this class exists to keep straight:
 *
 *   the boundaries are chosen in a human timezone. "Last 7 days" for a team in
 *   Stockholm means seven Stockholm days, ending at midnight tonight their
 *   time — not at midnight UTC, which is 01:00 or 02:00 to them and would put
 *   part of this evening's work in tomorrow's bucket.
 *
 *   the boundaries are *stored and compared* as UTC instants, because that is
 *   what is in the database. Every accessor below hands out UTC.
 *
 * The same rule applies to the week buckets: weeks start on Monday in the
 * board's timezone, so a chart of weekly throughput lines up with the week the
 * team actually worked.
 */
final readonly class StatsPeriod
{
    public const LAST_7_DAYS = 'last_7_days';

    public const LAST_30_DAYS = 'last_30_days';

    public const LAST_90_DAYS = 'last_90_days';

    public const THIS_MONTH = 'this_month';

    public const THIS_QUARTER = 'this_quarter';

    public const THIS_YEAR = 'this_year';

    public const CUSTOM = 'custom';

    /**
     * The longest range a single report may cover.
     *
     * Not an arbitrary tidiness rule: the flow metrics fold over every transit
     * event in the range, and an unbounded custom range typed into a URL is the
     * one way a reporting screen can be turned into a denial of service against
     * its own database. Three years is far beyond any real question and still
     * bounded.
     */
    public const MAX_DAYS = 1096;

    private function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $timezone,
        public string $key,
    ) {}

    /**
     * Every preset offered by the filter, in the order it is displayed.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::LAST_7_DAYS => 'Last 7 days',
            self::LAST_30_DAYS => 'Last 30 days',
            self::LAST_90_DAYS => 'Last 90 days',
            self::THIS_MONTH => 'This month',
            self::THIS_QUARTER => 'This quarter',
            self::THIS_YEAR => 'This year',
            self::CUSTOM => 'Custom range',
        ];
    }

    public static function default(?string $timezone = null): self
    {
        return self::preset(self::LAST_30_DAYS, $timezone);
    }

    /**
     * Build one of the named ranges.
     *
     * An unrecognised key falls back to the default rather than throwing: the
     * key arrives from a query string, and a stale bookmark should show a
     * sensible report, not an error page.
     */
    public static function preset(string $key, ?string $timezone = null): self
    {
        $zone = self::zone($timezone);
        $now = CarbonImmutable::now($zone);

        [$from, $to] = match ($key) {
            self::LAST_7_DAYS => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
            self::LAST_90_DAYS => [$now->subDays(89)->startOfDay(), $now->endOfDay()],
            self::THIS_MONTH => [$now->startOfMonth(), $now->endOfDay()],
            self::THIS_QUARTER => [$now->startOfQuarter(), $now->endOfDay()],
            self::THIS_YEAR => [$now->startOfYear(), $now->endOfDay()],
            default => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
        };

        return new self(
            $from->utc(),
            $to->utc(),
            $zone,
            array_key_exists($key, self::options()) && $key !== self::CUSTOM ? $key : self::LAST_30_DAYS,
        );
    }

    /**
     * Build a range from two dates typed into the filter.
     *
     * Both are `Y-m-d` as the user reads them, so the range runs from the very
     * start of the first day to the very end of the last, in their timezone.
     * Anything unparseable, reversed or absurdly long is corrected rather than
     * rejected — the two inputs are a filter, not a form submission worth
     * failing.
     */
    public static function between(?string $from, ?string $to, ?string $timezone = null): self
    {
        $zone = self::zone($timezone);

        $start = self::parse($from, $zone)?->startOfDay();
        $end = self::parse($to, $zone)?->endOfDay();

        if ($start === null && $end === null) {
            return self::default($zone);
        }

        $start ??= $end->subDays(29)->startOfDay();
        $end ??= $start->addDays(29)->endOfDay();

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
        }

        // Clamp from the far end, so the most recent data is what survives.
        if ($start->diffInDays($end) > self::MAX_DAYS) {
            $start = $end->subDays(self::MAX_DAYS)->startOfDay();
        }

        return new self($start->utc(), $end->utc(), $zone, self::CUSTOM);
    }

    public function isCustom(): bool
    {
        return $this->key === self::CUSTOM;
    }

    public function label(): string
    {
        if (! $this->isCustom()) {
            return self::options()[$this->key] ?? 'Last 30 days';
        }

        return $this->fromDate().' to '.$this->toDate();
    }

    /** The first day of the range as the user reads it: `Y-m-d` in their zone. */
    public function fromDate(): string
    {
        return $this->from->setTimezone($this->timezone)->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->setTimezone($this->timezone)->toDateString();
    }

    /**
     * Whole days spanned, counting both ends. Always at least 1.
     */
    public function days(): int
    {
        return max(1, (int) $this->from->setTimezone($this->timezone)->startOfDay()
            ->diffInDays($this->to->setTimezone($this->timezone)->endOfDay()) + 1);
    }

    /**
     * The range shifted back by its own length.
     *
     * Used for the "vs previous period" comparisons, which is the only way a
     * number like "31 tickets closed" means anything on its own.
     *
     * Shifted by whole *days* in the display timezone rather than by the
     * elapsed seconds. Subtracting seconds looks equivalent and is not: an
     * end-of-day boundary carries microseconds, so the arithmetic lands a
     * fraction of a second early and the previous period comes out one calendar
     * day longer than the one it is being compared against. A comparison
     * between a 30-day period and a 31-day period is exactly the kind of error
     * nobody notices, because both numbers look plausible.
     */
    public function previous(): self
    {
        $days = $this->days();

        $end = $this->from->setTimezone($this->timezone)->subDay()->endOfDay();
        $start = $end->subDays($days - 1)->startOfDay();

        return new self($start->utc(), $end->utc(), $this->timezone, self::CUSTOM);
    }

    /**
     * Monday-to-Sunday buckets covering the range, oldest first.
     *
     * Weeks are cut in the display timezone and then converted, so a Monday
     * boundary is a local Monday. The first and last buckets are clipped to the
     * range itself, so a report for "last 10 days" does not silently widen to
     * the two calendar weeks that contain it.
     *
     * @return array<int, array{start: CarbonImmutable, end: CarbonImmutable, label: string}>
     */
    public function weeks(): array
    {
        $local = $this->from->setTimezone($this->timezone);
        $end = $this->to->setTimezone($this->timezone);

        $buckets = [];
        $cursor = $local->startOfWeek(CarbonInterface::MONDAY);

        while ($cursor->lessThanOrEqualTo($end)) {
            $weekEnd = $cursor->endOfWeek(CarbonInterface::SUNDAY);

            $buckets[] = [
                'start' => $cursor->greaterThan($local) ? $cursor->utc() : $this->from,
                'end' => $weekEnd->lessThan($end) ? $weekEnd->utc() : $this->to,
                'label' => $cursor->isoFormat('D MMM'),
            ];

            $cursor = $cursor->addWeek();
        }

        return $buckets;
    }

    /**
     * Which local week a UTC instant belongs to, as the key used by weeks().
     */
    public function weekKeyFor(CarbonInterface $moment): string
    {
        return CarbonImmutable::instance($moment)
            ->setTimezone($this->timezone)
            ->startOfWeek(CarbonInterface::MONDAY)
            ->toDateString();
    }

    /**
     * The same keys weeks() produces, for pre-seeding a bucket map so a quiet
     * week renders as a zero rather than as a gap in the chart.
     *
     * @return array<int, string>
     */
    public function weekKeys(): array
    {
        return array_map(
            fn (array $week): string => $this->weekKeyFor($week['start']),
            $this->weeks(),
        );
    }

    private static function parse(?string $value, string $zone): ?CarbonImmutable
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value, $zone) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Fall back to the workspace default, and to UTC if that is misconfigured.
     *
     * A bad timezone string must not take a reporting screen down, so this
     * degrades rather than throwing.
     */
    private static function zone(?string $timezone): string
    {
        $candidate = $timezone ?: (string) config('workspace.board_defaults.timezone', 'UTC');

        try {
            CarbonImmutable::now($candidate);

            return $candidate;
        } catch (Throwable) {
            return 'UTC';
        }
    }
}
