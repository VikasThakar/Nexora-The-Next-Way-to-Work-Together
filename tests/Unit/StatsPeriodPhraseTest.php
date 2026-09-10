<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\StatsPeriod;
use App\Support\StatsPeriodPhrase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * The date ranges the assistant may ask for.
 *
 * A plain unit test: no database, and time frozen, because every assertion here
 * is about arithmetic on a calendar. The one thing worth testing hardest is
 * that the ranges this class *adds* line up with the ones StatsPeriod already
 * owned — a chart labelled "this month" that covered a different month from the
 * statistics page would be two reports that look like one.
 */
class StatsPeriodPhraseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A Thursday, mid-month, mid-quarter — so a week boundary, a month
        // boundary and a quarter boundary are all visible in the results.
        Carbon::setTestNow(Carbon::parse('2026-09-10 14:30:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_existing_presets_are_delegated_rather_than_reimplemented(): void
    {
        foreach ([
            StatsPeriod::LAST_7_DAYS,
            StatsPeriod::LAST_30_DAYS,
            StatsPeriod::LAST_90_DAYS,
            StatsPeriod::THIS_MONTH,
            StatsPeriod::THIS_QUARTER,
            StatsPeriod::THIS_YEAR,
        ] as $key) {
            $mine = StatsPeriodPhrase::resolve($key, null, null, 'UTC');
            $theirs = StatsPeriod::preset($key, 'UTC');

            // Identical dates AND the same key, so `label()` reads the same on
            // a chart as it does on the statistics filter.
            $this->assertSame($theirs->fromDate(), $mine->fromDate(), "{$key} start differs.");
            $this->assertSame($theirs->toDate(), $mine->toDate(), "{$key} end differs.");
            $this->assertSame($theirs->key, $mine->key, "{$key} key differs.");
        }
    }

    public function test_today_is_a_single_day(): void
    {
        $period = StatsPeriodPhrase::resolve(StatsPeriodPhrase::TODAY, null, null, 'UTC');

        $this->assertSame('2026-09-10', $period->fromDate());
        $this->assertSame('2026-09-10', $period->toDate());
        $this->assertSame(1, $period->days());
    }

    public function test_yesterday_is_the_day_before_and_excludes_today(): void
    {
        $period = StatsPeriodPhrase::resolve(StatsPeriodPhrase::YESTERDAY, null, null, 'UTC');

        $this->assertSame('2026-09-09', $period->fromDate());
        $this->assertSame('2026-09-09', $period->toDate());
        $this->assertSame(1, $period->days());
    }

    /**
     * Weeks start on Monday, matching StatsPeriod's own weekly bucketing.
     *
     * 10 September 2026 is a Thursday, so this week began on the 7th.
     */
    public function test_this_week_runs_from_monday_to_today(): void
    {
        $period = StatsPeriodPhrase::resolve(StatsPeriodPhrase::THIS_WEEK, null, null, 'UTC');

        $this->assertSame('2026-09-07', $period->fromDate());
        $this->assertSame('2026-09-10', $period->toDate());
    }

    public function test_last_week_is_a_whole_week_and_does_not_run_into_this_one(): void
    {
        $period = StatsPeriodPhrase::resolve(StatsPeriodPhrase::LAST_WEEK, null, null, 'UTC');

        $this->assertSame('2026-08-31', $period->fromDate());
        $this->assertSame('2026-09-06', $period->toDate());
        $this->assertSame(7, $period->days());
    }

    public function test_last_month_is_a_whole_calendar_month(): void
    {
        $period = StatsPeriodPhrase::resolve(StatsPeriodPhrase::LAST_MONTH, null, null, 'UTC');

        $this->assertSame('2026-08-01', $period->fromDate());
        $this->assertSame('2026-08-31', $period->toDate());
        $this->assertSame(31, $period->days());
    }

    /**
     * `subMonthNoOverflow` matters on the 31st.
     *
     * Plain month arithmetic from 31 March lands on 31 February, which Carbon
     * rolls forward into March — so "last month" asked on the 31st would
     * return part of the current month.
     */
    public function test_last_month_is_correct_when_asked_on_a_day_the_previous_month_lacks(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-31 09:00:00', 'UTC'));

        $period = StatsPeriodPhrase::resolve(StatsPeriodPhrase::LAST_MONTH, null, null, 'UTC');

        $this->assertSame('2026-02-01', $period->fromDate());
        $this->assertSame('2026-02-28', $period->toDate());
    }

    public function test_an_explicit_range_wins_over_a_preset(): void
    {
        $period = StatsPeriodPhrase::resolve(
            StatsPeriodPhrase::TODAY,
            '2026-01-05',
            '2026-01-09',
            'UTC',
        );

        $this->assertSame('2026-01-05', $period->fromDate());
        $this->assertSame('2026-01-09', $period->toDate());
        $this->assertTrue($period->isCustom());
    }

    /**
     * An unknown key is the default range, never an error.
     *
     * The value arrives from a model, and the failure mode of a stale or
     * invented key should be "it answered about the last thirty days" rather
     * than a request that dies.
     */
    public function test_an_unknown_key_falls_back_to_the_default_range(): void
    {
        $period = StatsPeriodPhrase::resolve('since_the_dawn_of_time', null, null, 'UTC');

        $this->assertSame(StatsPeriod::LAST_30_DAYS, $period->key);
        $this->assertSame(StatsPeriod::default('UTC')->fromDate(), $period->fromDate());
    }

    public function test_null_and_empty_keys_fall_back_too(): void
    {
        foreach ([null, '', '   '] as $key) {
            $this->assertSame(
                StatsPeriod::LAST_30_DAYS,
                StatsPeriodPhrase::resolve($key, null, null, 'UTC')->key
            );
        }
    }

    public function test_the_vocabulary_is_offered_to_the_model_as_a_closed_list(): void
    {
        $keys = StatsPeriodPhrase::keys();

        // Every key resolves, which is what makes the schema's enum safe to
        // hand to a model: there is no value in the list that fails.
        foreach ($keys as $key) {
            $this->assertNotSame('', StatsPeriodPhrase::label($key));
            $this->assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2}$/', StatsPeriodPhrase::resolve($key, null, null, 'UTC')->fromDate()));
        }

        // And the description the model reads names them all, generated from
        // the same list so it cannot go stale.
        $description = StatsPeriodPhrase::schemaDescription();

        foreach ($keys as $key) {
            $this->assertStringContainsString($key, $description);
        }
    }

    public function test_a_timezone_shifts_which_day_today_is(): void
    {
        // 14:30 UTC on the 10th is already the 11th in Auckland (UTC+12).
        $utc = StatsPeriodPhrase::resolve(StatsPeriodPhrase::TODAY, null, null, 'UTC');
        $nz = StatsPeriodPhrase::resolve(StatsPeriodPhrase::TODAY, null, null, 'Pacific/Auckland');

        $this->assertSame('2026-09-10', $utc->fromDate());
        $this->assertSame('2026-09-11', $nz->fromDate());
    }
}
