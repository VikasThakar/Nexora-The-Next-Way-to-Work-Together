<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\StatsPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The date range every report is scoped by.
 *
 * Worth unit testing rather than only exercising through a screen, because
 * almost every bug a reporting feature ships with is here: an off-by-one day,
 * a timezone boundary, or an unbounded custom range.
 *
 * Extends the application's TestCase rather than PHPUnit's, so the framework
 * has set the default timezone. Without that, `Carbon::parse('2026-06-15
 * 00:30')` means whatever the machine running the tests thinks local time is —
 * which on a European developer's laptop is a different instant, in a different
 * week, from the same string on a CI runner. Every moment constructed below is
 * given an explicit timezone for the same reason.
 */
class StatsPeriodTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-15 14:30:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_last_seven_days_covers_today_and_the_six_before_it(): void
    {
        $period = StatsPeriod::preset(StatsPeriod::LAST_7_DAYS, 'UTC');

        $this->assertSame('2026-06-09', $period->fromDate());
        $this->assertSame('2026-06-15', $period->toDate());
        $this->assertSame(7, $period->days());
    }

    public function test_boundaries_are_cut_in_the_display_timezone(): void
    {
        // Stockholm is UTC+2 in June, so "today" there began at 22:00 UTC
        // yesterday. A report cut at UTC midnight would put a Stockholm
        // team's evening work in tomorrow's bucket.
        $period = StatsPeriod::preset(StatsPeriod::LAST_7_DAYS, 'Europe/Stockholm');

        $this->assertSame('2026-06-09T00:00:00+02:00', $period->from->setTimezone('Europe/Stockholm')->toIso8601String());

        // …and the value actually used in a query is UTC.
        $this->assertSame('UTC', $period->from->timezone->getName());
        $this->assertSame('2026-06-08 22:00:00', $period->from->toDateTimeString());
    }

    public function test_an_unknown_preset_falls_back_rather_than_throwing(): void
    {
        // The key arrives from a query string; a stale bookmark should show a
        // sensible report, not an error page.
        $period = StatsPeriod::preset('last_million_years', 'UTC');

        $this->assertSame(StatsPeriod::LAST_30_DAYS, $period->key);
        $this->assertSame('Last 30 days', $period->label());
    }

    public function test_a_custom_range_runs_from_the_start_of_one_day_to_the_end_of_the_other(): void
    {
        $period = StatsPeriod::between('2026-03-01', '2026-03-31', 'UTC');

        $this->assertTrue($period->isCustom());
        $this->assertSame('2026-03-01 00:00:00', $period->from->toDateTimeString());
        $this->assertSame('2026-03-31 23:59:59', $period->to->toDateTimeString());
        $this->assertSame(31, $period->days());
    }

    public function test_reversed_dates_are_swapped_rather_than_rejected(): void
    {
        $period = StatsPeriod::between('2026-03-31', '2026-03-01', 'UTC');

        $this->assertSame('2026-03-01', $period->fromDate());
        $this->assertSame('2026-03-31', $period->toDate());
    }

    public function test_an_absurd_range_is_clamped_from_the_recent_end(): void
    {
        $period = StatsPeriod::between('1970-01-01', '2026-06-15', 'UTC');

        // The flow metrics fold over every transition in the range, so an
        // unbounded custom range typed into a URL is the one way a reporting
        // screen becomes a denial of service against its own database.
        $this->assertLessThanOrEqual(StatsPeriod::MAX_DAYS + 1, $period->days());

        // The most recent data is what survives the clamp.
        $this->assertSame('2026-06-15', $period->toDate());
    }

    public function test_unparseable_dates_fall_back_to_the_default_range(): void
    {
        $period = StatsPeriod::between('yesterday-ish', 'soon', 'UTC');

        $this->assertSame(StatsPeriod::LAST_30_DAYS, $period->key);
    }

    public function test_one_missing_end_is_inferred_from_the_other(): void
    {
        $from = StatsPeriod::between('2026-03-01', null, 'UTC');
        $this->assertSame('2026-03-01', $from->fromDate());
        $this->assertSame('2026-03-30', $from->toDate());

        $to = StatsPeriod::between(null, '2026-03-31', 'UTC');
        $this->assertSame('2026-03-02', $to->fromDate());
        $this->assertSame('2026-03-31', $to->toDate());
    }

    public function test_weeks_start_on_monday_and_are_clipped_to_the_range(): void
    {
        // 2026-06-03 is a Wednesday; 2026-06-15 is a Monday.
        $period = StatsPeriod::between('2026-06-03', '2026-06-15', 'UTC');

        $weeks = $period->weeks();

        $this->assertCount(3, $weeks);

        // The first bucket does not silently widen back to Monday 1 June.
        $this->assertSame('2026-06-03 00:00:00', $weeks[0]['start']->toDateTimeString());
        $this->assertSame('2026-06-07 23:59:59', $weeks[0]['end']->toDateTimeString());

        // Nor does the last widen forward to Sunday 21 June.
        $this->assertSame('2026-06-15 00:00:00', $weeks[2]['start']->toDateTimeString());
        $this->assertSame('2026-06-15 23:59:59', $weeks[2]['end']->toDateTimeString());
    }

    public function test_a_moment_is_bucketed_into_the_week_that_contains_it(): void
    {
        $period = StatsPeriod::between('2026-06-01', '2026-06-30', 'UTC');

        $this->assertSame('2026-06-08', $period->weekKeyFor(CarbonImmutable::parse('2026-06-10 12:00:00', 'UTC')));

        // Sunday belongs to the week that began on the Monday before it, not
        // to the one starting the next day.
        $this->assertSame('2026-06-08', $period->weekKeyFor(CarbonImmutable::parse('2026-06-14 23:00:00', 'UTC')));
        $this->assertSame('2026-06-15', $period->weekKeyFor(CarbonImmutable::parse('2026-06-15 00:30:00', 'UTC')));
    }

    public function test_a_moment_is_bucketed_by_local_time_not_utc(): void
    {
        // The same instant is Sunday evening in London and Monday morning in
        // Stockholm, and each team's chart should agree with the week they
        // actually worked.
        $instant = CarbonImmutable::parse('2026-06-14 22:30:00', 'UTC');

        $london = StatsPeriod::between('2026-06-01', '2026-06-30', 'Europe/London');
        $stockholm = StatsPeriod::between('2026-06-01', '2026-06-30', 'Europe/Stockholm');

        $this->assertSame('2026-06-08', $london->weekKeyFor($instant));
        $this->assertSame('2026-06-15', $stockholm->weekKeyFor($instant));
    }

    public function test_week_keys_line_up_with_the_buckets_they_seed(): void
    {
        $period = StatsPeriod::between('2026-05-01', '2026-06-15', 'UTC');

        // The charts pre-seed a map from weekKeys() and then fill it using
        // weekKeyFor(). If those two disagreed, every closure would land in a
        // bucket that is silently discarded.
        $this->assertSame(count($period->weeks()), count($period->weekKeys()));

        foreach ($period->weeks() as $index => $week) {
            $this->assertSame($period->weekKeys()[$index], $period->weekKeyFor($week['start']));
        }
    }

    public function test_the_previous_period_is_the_same_length_and_does_not_overlap(): void
    {
        $period = StatsPeriod::between('2026-06-01', '2026-06-30', 'UTC');
        $previous = $period->previous();

        $this->assertTrue($previous->to->lessThan($period->from));
        $this->assertSame($period->days(), $previous->days());
    }

    public function test_an_invalid_timezone_degrades_to_utc(): void
    {
        // A bad configuration value must not take a reporting screen down.
        $period = StatsPeriod::preset(StatsPeriod::LAST_7_DAYS, 'Mars/Olympus_Mons');

        $this->assertSame('UTC', $period->timezone);
    }
}
