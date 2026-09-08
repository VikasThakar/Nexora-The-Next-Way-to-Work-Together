<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Models\Board;
use App\Services\BoardAccess;
use App\Support\StatsPeriod;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Turns four query-string values into a scope.
 *
 * Extracted from App\Livewire\Stats\Concerns\FiltersStatistics because the CSV
 * export is a controller and cannot use a Livewire trait — and because an
 * export that resolved its own board and its own date range would be a second
 * definition of "which numbers is this person allowed to ask for". Two
 * definitions of that is one too many, and the copy in the download route is
 * exactly the one nobody would notice going stale.
 *
 * Nothing here is trusted:
 *
 *   the board slug is resolved through BoardAccess, so a slug the viewer cannot
 *   reach yields a scope over nothing rather than that board's figures. "No
 *   such board" and "not your board" are deliberately the same answer, so the
 *   filter cannot be used to find out which boards exist.
 *
 *   the range is parsed by StatsPeriod, which corrects rather than trusts: an
 *   unknown preset falls back to the default, reversed dates are swapped, and
 *   an absurd span is clamped. A hand-edited URL cannot ask the database for
 *   ten years of transitions.
 *
 * Visibility itself is not decided here at all — StatisticsScope applies the
 * ordinary `visibleTo()` scopes whatever these values say.
 */
class StatisticsScopeResolver
{
    public function __construct(private readonly BoardAccess $access) {}

    public function resolve(
        ?Authenticatable $viewer,
        string $boardSlug = '',
        string $range = StatsPeriod::LAST_30_DAYS,
        string $customFrom = '',
        string $customTo = '',
    ): StatisticsScope {
        $board = $this->board($viewer, $boardSlug);

        // A slug was asked for and did not resolve. Report nothing rather than
        // silently widening to every board — see StatisticsScope::none().
        if ($board === null && trim($boardSlug) !== '') {
            return StatisticsScope::none($viewer, $this->period(
                $range,
                $customFrom,
                $customTo,
                (string) config('workspace.board_defaults.timezone', 'UTC'),
            ));
        }

        // The timezone is the board's when a single board is selected, so its
        // days and weeks are cut where the team actually works.
        $base = StatisticsScope::for($viewer, null, $board);

        return $base->withPeriod($this->period($range, $customFrom, $customTo, $base->timezone()));
    }

    /**
     * The board named in the filter, if the viewer may see it.
     */
    public function board(?Authenticatable $viewer, string $slug): ?Board
    {
        if (trim($slug) === '') {
            return null;
        }

        return $this->access->query($viewer)->where('boards.slug', $slug)->first();
    }

    public function period(string $range, string $customFrom, string $customTo, string $timezone): StatsPeriod
    {
        return $range === StatsPeriod::CUSTOM
            ? StatsPeriod::between($customFrom, $customTo, $timezone)
            : StatsPeriod::preset($range, $timezone);
    }
}
