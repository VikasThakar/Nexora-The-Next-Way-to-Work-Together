<?php

declare(strict_types=1);

namespace App\Livewire\Stats\Concerns;

use App\Models\Board;
use App\Services\BoardAccess;
use App\Services\Statistics\StatisticsScope;
use App\Services\Statistics\StatisticsScopeResolver;
use App\Support\StatsPeriod;
use Livewire\Attributes\Url;

/**
 * The board and date-range filter shared by both statistics screens.
 *
 * The filter is in the query string so a report is a link — "here is our Q3 on
 * the platform board" is a URL somebody pastes into Slack, not a screenshot.
 *
 * Which means every value here is attacker-controlled, and none of them can
 * widen a report:
 *
 *   the board is resolved through BoardAccess, so a slug the viewer cannot
 *   reach yields an empty scope rather than that board's figures. It is
 *   deliberately not route-model-bound and never loaded by id.
 *
 *   the range is parsed by StatsPeriod, which corrects rather than trusts:
 *   an unknown preset falls back to the default, reversed dates are swapped,
 *   and an absurd span is clamped. A hand-edited URL cannot ask the database
 *   for ten years of transitions.
 *
 * Neither filter touches visibility at all — that is StatisticsScope's job, and
 * it applies the ordinary `visibleTo()` scopes whatever these say.
 */
trait FiltersStatistics
{
    /** Board slug, or '' for every board the viewer can reach. */
    #[Url(as: 'board', except: '')]
    public string $boardSlug = '';

    #[Url(as: 'range', except: StatsPeriod::LAST_30_DAYS)]
    public string $range = StatsPeriod::LAST_30_DAYS;

    #[Url(as: 'from', except: '')]
    public string $customFrom = '';

    #[Url(as: 'to', except: '')]
    public string $customTo = '';

    /**
     * Rebuild the report whenever a filter changes.
     *
     * Livewire re-renders on any property update, so this only has to correct
     * the state — switching away from the custom range clears the two dates so
     * they do not linger in the URL and reappear later.
     */
    public function updatedRange(string $value): void
    {
        if ($value !== StatsPeriod::CUSTOM) {
            $this->customFrom = '';
            $this->customTo = '';
        }
    }

    /**
     * The scope every figure on the page is derived from.
     *
     * Resolved fresh on each render rather than held as component state: a
     * property survives across requests, and a serialised scope carrying board
     * ids would be state the client could tamper with. Rebuilding costs one
     * board query and removes the question entirely.
     */
    protected function scope(): StatisticsScope
    {
        /*
         * Delegated rather than inlined, because the CSV export route has to
         * answer exactly the same question from exactly the same four values
         * and is a controller rather than a component. Two implementations of
         * "which numbers may this person ask for" is one too many.
         */
        return app(StatisticsScopeResolver::class)->resolve(
            auth()->user(),
            $this->boardSlug,
            $this->range,
            $this->customFrom,
            $this->customTo,
        );
    }

    protected function period(string $timezone): StatsPeriod
    {
        return app(StatisticsScopeResolver::class)
            ->period($this->range, $this->customFrom, $this->customTo, $timezone);
    }

    /**
     * The filters as the export route expects them.
     *
     * Built here so the download link on the page and the report on the page
     * cannot describe different periods.
     *
     * @return array<string, string>
     */
    protected function exportQuery(): array
    {
        return array_filter([
            'board' => $this->boardSlug,
            'range' => $this->range,
            'from' => $this->customFrom,
            'to' => $this->customTo,
        ], static fn (string $value): bool => $value !== '');
    }

    /**
     * The board named in the filter, if the viewer may see it.
     *
     * Returns null both when the slug is unknown and when it names a board the
     * viewer cannot reach — deliberately indistinguishable, so the filter
     * cannot be used to enumerate which boards exist. The caller turns that
     * null into an empty report rather than into "all boards".
     *
     * It does not 404. A report is not a board, and a stale bookmark should
     * show an empty report with the filter still visible, not an error page.
     */
    protected function selectedBoard(): ?Board
    {
        if (trim($this->boardSlug) === '') {
            return null;
        }

        return app(BoardAccess::class)
            ->query(auth()->user())
            ->where('boards.slug', $this->boardSlug)
            ->first();
    }

    /**
     * Options for the board dropdown: slug => name, already scoped.
     *
     * @return array<string, string>
     */
    protected function boardOptions(): array
    {
        return app(BoardAccess::class)
            ->query(auth()->user())
            ->notArchived()
            ->orderBy('name')
            ->pluck('name', 'slug')
            ->all();
    }
}
