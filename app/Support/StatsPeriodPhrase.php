<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The date ranges somebody says out loud, as a StatsPeriod.
 *
 * StatsPeriod already owns every range the statistics screens offer, and this
 * class does not replace or reimplement any of them — it widens the *vocabulary*
 * and delegates. "last 30 days" is `StatsPeriod::preset()`; "yesterday" is a
 * pair of dates handed to `StatsPeriod::between()`. Either way the object that
 * comes back is the same one the statistics pages use, so an assistant chart and
 * the statistics screen agree about what "this month" means.
 *
 * Why not add these to StatsPeriod::options()
 * -------------------------------------------
 * Because `options()` is the filter UI on the statistics page: every key in it
 * becomes a dropdown entry. "Today" and "Yesterday" are things people say to an
 * assistant and are close to useless as a report filter — a one-day window on a
 * flow-metrics page is noise. So the assistant's vocabulary is a superset of the
 * screen's, held separately, and the screen is untouched.
 *
 * The keys are what the model is offered
 * --------------------------------------
 * `keys()` feeds an `enum` in the statistics tool's input schema, so the model
 * picks from a fixed list that AiToolInput validates before anything runs. There
 * is no free-text date parsing here and deliberately so: a phrase parser is a
 * thing that mostly works, and "mostly" applied to a date range produces a chart
 * that is quietly about the wrong fortnight. A model choosing from eleven named
 * ranges cannot be subtly wrong, only plainly wrong — and `from`/`to` covers
 * anything genuinely custom.
 *
 * Everything is computed in the viewer's timezone, then stored UTC, because
 * StatsPeriod already does that and "today" is the one range where getting the
 * zone wrong is most obvious.
 */
final class StatsPeriodPhrase
{
    public const TODAY = 'today';

    public const YESTERDAY = 'yesterday';

    public const THIS_WEEK = 'this_week';

    public const LAST_WEEK = 'last_week';

    public const LAST_MONTH = 'last_month';

    /**
     * Every range the assistant may ask for, with the words for each.
     *
     * The order is shortest-first, which is the order the descriptions read in
     * naturally when they are printed into the tool's schema.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::TODAY => 'Today',
            self::YESTERDAY => 'Yesterday',
            self::THIS_WEEK => 'This week',
            self::LAST_WEEK => 'Last week',
            StatsPeriod::LAST_7_DAYS => 'Last 7 days',
            StatsPeriod::LAST_30_DAYS => 'Last 30 days',
            StatsPeriod::LAST_90_DAYS => 'Last 90 days',
            StatsPeriod::THIS_MONTH => 'This month',
            self::LAST_MONTH => 'Last month',
            StatsPeriod::THIS_QUARTER => 'This quarter',
            StatsPeriod::THIS_YEAR => 'This year',
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::options());
    }

    /**
     * The human name for a key, for the sentence the tool reports.
     */
    public static function label(string $key): string
    {
        return self::options()[$key] ?? self::options()[StatsPeriod::LAST_30_DAYS];
    }

    /**
     * Resolve a key — and optionally an explicit range — into a period.
     *
     * `$from`/`$to` win when either is present, because a person who named two
     * dates has been more specific than a person who named a preset, and the
     * model is told to send only one or the other. Unparseable dates fall
     * through to StatsPeriod::between()'s own correction rather than being
     * rejected here; a filter is not a form worth failing.
     */
    public static function resolve(
        ?string $key,
        ?string $from = null,
        ?string $to = null,
        ?string $timezone = null,
    ): StatsPeriod {
        if (($from !== null && trim($from) !== '') || ($to !== null && trim($to) !== '')) {
            return StatsPeriod::between($from, $to, $timezone);
        }

        $key = is_string($key) ? trim(strtolower($key)) : '';

        // The ranges StatsPeriod already knows, handed straight back to it so
        // there is exactly one definition of "this quarter" in the codebase.
        if (in_array($key, [
            StatsPeriod::LAST_7_DAYS,
            StatsPeriod::LAST_30_DAYS,
            StatsPeriod::LAST_90_DAYS,
            StatsPeriod::THIS_MONTH,
            StatsPeriod::THIS_QUARTER,
            StatsPeriod::THIS_YEAR,
        ], true)) {
            return StatsPeriod::preset($key, $timezone);
        }

        $now = CarbonImmutable::now(self::zone($timezone));

        /*
         * The five this class adds, as a pair of dates.
         *
         * Weeks start on Monday via Carbon's locale-aware startOfWeek(), which
         * is what the rest of the product's weekly bucketing uses — see
         * StatsPeriod::weeks(). A chart whose weeks began on Sunday while the
         * statistics page's began on Monday would be two different reports
         * that look like one.
         */
        [$start, $end] = match ($key) {
            self::TODAY => [$now, $now],
            self::YESTERDAY => [$now->subDay(), $now->subDay()],
            self::THIS_WEEK => [$now->startOfWeek(), $now],
            self::LAST_WEEK => [$now->subWeek()->startOfWeek(), $now->subWeek()->endOfWeek()],
            self::LAST_MONTH => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            // Unrecognised is the default range rather than an error, for the
            // same reason StatsPeriod::preset() does the same.
            default => [null, null],
        };

        if ($start === null || $end === null) {
            return StatsPeriod::default($timezone);
        }

        return StatsPeriod::between(
            $start->toDateString(),
            $end->toDateString(),
            $timezone,
        );
    }

    /**
     * A description of the whole vocabulary, for the tool's input schema.
     *
     * Generated rather than written out, so adding a range above cannot leave
     * the model with a stale list of what it may ask for.
     */
    public static function schemaDescription(): string
    {
        return 'The date range to report over. One of: '.implode(', ', self::keys())
            .'. Defaults to '.StatsPeriod::LAST_30_DAYS.'. '
            .'For anything else, omit this and pass `from` and `to` instead.';
    }

    private static function zone(?string $timezone): string
    {
        $zone = trim((string) $timezone);

        if ($zone !== '' && in_array($zone, timezone_identifiers_list(), true)) {
            return $zone;
        }

        return (string) config('app.timezone', 'UTC');
    }
}
