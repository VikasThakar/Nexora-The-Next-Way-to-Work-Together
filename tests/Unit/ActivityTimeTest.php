<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ActivityTime;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * How a moment reads in the activity feed.
 *
 * The clock is frozen for every case, because each assertion is about the
 * distance between two instants and a test that read the real clock would be a
 * different test at midnight.
 *
 * Every instant is also constructed with an explicit timezone. Without one,
 * Carbon uses PHP's default, and these tests would then assert something
 * different on a machine configured for a different zone than on CI — which is
 * exactly the class of bug the helper under test exists to prevent.
 */
class ActivityTimeTest extends TestCase
{
    private const NOW = '2026-09-02 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_moment_inside_the_last_minute_is_just_now(): void
    {
        $this->assertSame('Just now', $this->relative('2026-09-02 11:59:30'));
    }

    public function test_a_moment_earlier_today_reads_relatively(): void
    {
        $this->assertSame('2 hours ago', $this->relative('2026-09-02 10:00:00'));
    }

    public function test_yesterday_is_named_and_keeps_its_time(): void
    {
        $this->assertSame('Yesterday at 09:30', $this->relative('2026-09-01 09:30:00'));
    }

    public function test_older_this_year_becomes_a_date_rather_than_a_vague_distance(): void
    {
        // Not "3 weeks ago", which is true and impossible to cross-reference
        // against anything.
        $this->assertSame('10 Aug', $this->relative('2026-08-10 09:30:00'));
    }

    public function test_a_previous_year_carries_the_year(): void
    {
        $this->assertSame('31 Aug 2025', $this->relative('2025-08-31 09:30:00'));
    }

    public function test_a_clock_skewed_into_the_future_does_not_read_as_from_now(): void
    {
        // A queued job on a host a few seconds ahead must not put
        // "1 second from now" in a history.
        $this->assertSame('Just now', $this->relative('2026-09-02 12:00:05'));
    }

    public function test_headings_name_today_and_yesterday(): void
    {
        $this->assertSame('Today', $this->heading('2026-09-02 01:00:00'));
        $this->assertSame('Yesterday', $this->heading('2026-09-01 23:59:00'));
    }

    public function test_older_headings_are_dated_and_gain_a_year_once_it_changes(): void
    {
        $this->assertSame('Monday 31 August', $this->heading('2026-08-31 10:00:00'));
        $this->assertSame('31 August 2025', $this->heading('2025-08-31 10:00:00'));
    }

    public function test_the_day_is_decided_in_the_given_timezone_not_the_servers(): void
    {
        // 23:30 UTC on the 2nd is 01:30 on the 3rd in Stockholm. A team there
        // filed it today; a heading cut in UTC files it yesterday. Both answers
        // are correct for their own reader, which is the point.
        Carbon::setTestNow(Carbon::parse('2026-09-03 00:30:00', 'UTC'));

        $moment = Carbon::parse('2026-09-02 23:30:00', 'UTC');

        $this->assertSame('Yesterday', ActivityTime::heading($moment, 'UTC'));
        $this->assertSame('Today', ActivityTime::heading($moment, 'Europe/Stockholm'));
    }

    public function test_the_relative_form_is_also_cut_in_the_given_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-03 06:00:00', 'UTC'));

        $moment = Carbon::parse('2026-09-02 23:30:00', 'UTC');

        // Six and a half hours earlier either way — but only one of the two
        // readers calls it yesterday.
        $this->assertSame('Yesterday at 23:30', ActivityTime::relative($moment, 'UTC'));
        $this->assertSame('6 hours ago', ActivityTime::relative($moment, 'Europe/Stockholm'));
    }

    public function test_a_missing_timestamp_degrades_rather_than_throwing(): void
    {
        $this->assertSame('Undated', ActivityTime::heading(null, 'UTC'));
        $this->assertSame('', ActivityTime::relative(null, 'UTC'));
        $this->assertSame('', ActivityTime::exact(null, 'UTC'));
    }

    public function test_the_exact_form_names_the_timezone_it_is_shown_in(): void
    {
        $moment = Carbon::parse('2026-09-02 09:30:00', 'UTC');

        $this->assertSame('Wed 2 Sep 2026, 09:30 (UTC)', ActivityTime::exact($moment, 'UTC'));
        $this->assertSame('Wed 2 Sep 2026, 11:30 (Europe/Stockholm)', ActivityTime::exact($moment, 'Europe/Stockholm'));
    }

    private function relative(string $moment, string $timezone = 'UTC'): string
    {
        return ActivityTime::relative(Carbon::parse($moment, 'UTC'), $timezone);
    }

    private function heading(string $moment, string $timezone = 'UTC'): string
    {
        return ActivityTime::heading(Carbon::parse($moment, 'UTC'), $timezone);
    }
}
