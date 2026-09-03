<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * How a moment reads in the activity feed.
 *
 * Two questions, both answered by how old the moment is rather than by one
 * format for everything:
 *
 *   heading()   the day separator a run of rows sits under — "Today",
 *               "Yesterday", then the date.
 *   relative()  the timestamp on an individual row.
 *
 * `diffForHumans()` alone is not enough for either. It happily produces
 * "3 weeks ago" and "11 months ago", which are true and useless: past a couple
 * of days, what somebody scanning a history wants is the date. And it says
 * "1 second ago", which reads like a stopwatch rather than a feed.
 *
 * Every comparison is made in an explicit timezone — the one
 * App\Services\ActivityReader::timezoneFor() chose — so the day a row is filed
 * under is the day it happened where the team works, and matches the boundaries
 * the date filter used. Comparing against the server's clock instead would put
 * an evening's work under tomorrow's heading for half the world.
 */
class ActivityTime
{
    /**
     * The day separator: "Today", "Yesterday", "Monday 25 August", or, once the
     * year is no longer the current one, "25 August 2025".
     */
    public static function heading(?CarbonInterface $moment, string $timezone): string
    {
        if ($moment === null) {
            return 'Undated';
        }

        $local = $moment->copy()->setTimezone($timezone);
        $today = Carbon::now($timezone)->startOfDay();

        return match (true) {
            $local->isSameDay($today) => 'Today',
            $local->isSameDay($today->copy()->subDay()) => 'Yesterday',
            $local->year === $today->year => $local->format('l j F'),
            default => $local->format('j F Y'),
        };
    }

    /**
     * The timestamp on one row.
     *
     * Anything inside the last minute is "Just now" rather than a count of
     * seconds; inside the last day, the familiar relative phrasing; yesterday
     * is named; and older than that becomes a date, because "7 weeks ago" is
     * not something anybody can cross-reference against anything.
     */
    public static function relative(?CarbonInterface $moment, string $timezone): string
    {
        if ($moment === null) {
            return '';
        }

        $local = $moment->copy()->setTimezone($timezone);
        $now = Carbon::now($timezone);
        $today = $now->copy()->startOfDay();

        if ($local->greaterThan($now)) {
            return 'Just now';
        }

        return match (true) {
            $local->diffInSeconds($now) < 60 => 'Just now',

            // Relative to now rather than to an explicit second argument:
            // passing one switches Carbon's phrasing from "2 hours ago" to
            // "2 hours before", which is not how a feed reads. Both operands
            // are absolute instants, so the display timezone above does not
            // affect the distance.
            $local->isSameDay($today) => $local->diffForHumans(),
            $local->isSameDay($today->copy()->subDay()) => 'Yesterday at '.$local->format('H:i'),
            $local->year === $today->year => $local->format('j M'),
            default => $local->format('j M Y'),
        };
    }

    /**
     * The full timestamp, for the `title` attribute — so the abbreviations
     * above never leave a reader unable to find out exactly when something
     * happened.
     */
    public static function exact(?CarbonInterface $moment, string $timezone): string
    {
        if ($moment === null) {
            return '';
        }

        return $moment->copy()->setTimezone($timezone)->format('D j M Y, H:i').' ('.$timezone.')';
    }
}
