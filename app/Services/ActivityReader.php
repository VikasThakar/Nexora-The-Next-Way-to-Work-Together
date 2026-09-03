<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Activity;
use App\Models\Board;
use App\Models\User;
use App\Support\ActivityFilters;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Authorized reads of the workspace activity feed.
 *
 * The same shape as App\Services\CommentReader, NotificationReader and
 * AI\AiRunReader, and for the same reason: a screen should not be able to
 * assemble its own query against a table with visibility rules. Every method
 * here starts from App\Models\Activity::readableBy() and can only narrow from
 * there.
 *
 * Two things live here rather than in the value object next door, and both need
 * BoardAccess:
 *
 *   resolving the board filter, which must be indistinguishable between "no
 *   such board" and "a board you cannot reach", so the filter cannot be used to
 *   find out which boards exist;
 *
 *   building the dropdown option lists, which must not name a board or a person
 *   the viewer has no business knowing about.
 */
class ActivityReader
{
    /**
     * How many people the user filter will offer.
     *
     * A workspace has tens of members, not thousands, so this is a guard
     * against a runaway query rather than a real limit — but it is a select
     * element, and one that renders five thousand options is broken whether or
     * not the query was fast.
     */
    private const MAX_ACTOR_OPTIONS = 200;

    public function __construct(private readonly BoardAccess $access) {}

    /**
     * One page of the feed.
     *
     * `causer_type` is pinned to users before eager loading `actor`, which is a
     * plain BelongsTo rather than the package's `causer` MorphTo — a MorphTo
     * cannot be eager loaded with one whereIn, and the feed renders a page of
     * twenty-five rows written by perhaps three people. `board` is loaded for
     * the same reason: strict mode turns a forgotten eager load into a loud
     * failure rather than an N+1, and this is a list.
     */
    public function paginate(
        ?Authenticatable $user,
        ActivityFilters $filters,
        int $perPage = 25,
    ): LengthAwarePaginator {
        return $this->query($user, $filters)
            ->with(['actor:id,name,email,role,deactivated_at', 'board:id,name,slug,ticket_prefix'])
            ->latestFirst()
            ->paginate($perPage);
    }

    /**
     * The filtered, authorized query — without ordering or eager loads, so a
     * caller that only needs a count does not pay for either.
     *
     * @return Builder<Activity>
     */
    public function query(?Authenticatable $user, ActivityFilters $filters): Builder
    {
        $query = Activity::query()->readableBy($user);

        // A slug was asked for. Resolve it against what this viewer may see,
        // and report nothing if it does not resolve — never silently widen to
        // every board.
        if ($filters->boardSlug !== '') {
            $board = $this->board($user, $filters->boardSlug);

            if (! $board instanceof Board) {
                return $query->whereRaw('1 = 0');
            }

            $query->forBoard($board);
        }

        $query->ofType($filters->type)->search($filters->search);

        if ($filters->userId !== null) {
            $query->causedByUser($filters->userId);
        }

        $period = $filters->period($this->timezoneFor($user, $filters));

        if ($period !== null) {
            $query->between($period->from, $period->to);
        }

        return $query;
    }

    /**
     * The board named in the filter, if the viewer may see it.
     *
     * Returns null both for an unknown slug and for a board the viewer cannot
     * reach — deliberately the same answer, as in
     * App\Livewire\Stats\Concerns\FiltersStatistics. It does not 404: a stale
     * bookmark should show an empty feed with the filter still on screen, not
     * an error page.
     */
    public function board(?Authenticatable $user, string $slug): ?Board
    {
        $slug = trim($slug);

        if ($slug === '') {
            return null;
        }

        return $this->access->query($user)
            ->where('boards.slug', $slug)
            ->first();
    }

    /**
     * slug => name for the board dropdown, already scoped to this viewer.
     *
     * Archived boards are included, unlike the sidebar's list: their history is
     * exactly what somebody comes to a feed looking for after a project ends.
     *
     * @return array<string, string>
     */
    public function boardOptions(?Authenticatable $user): array
    {
        return $this->access->query($user)
            ->orderBy('name')
            ->pluck('name', 'slug')
            ->all();
    }

    /**
     * id => name for the user dropdown.
     *
     * Derived from the feed itself — the distinct causers of activity this
     * viewer may read — rather than from board membership, which would be the
     * cheaper query and the wrong list. Somebody who has since been removed
     * from a board, or deactivated, is precisely the person whose past activity
     * gets looked up; a membership-derived list would drop them and quietly
     * make their rows unfindable.
     *
     * @return array<int, string>
     */
    public function actorOptions(?Authenticatable $user): array
    {
        $ids = Activity::query()
            ->readableBy($user)
            ->where('causer_type', (new User)->getMorphClass())
            ->whereNotNull('causer_id')
            ->distinct()
            ->limit(self::MAX_ACTOR_OPTIONS)
            ->pluck('causer_id')
            ->all();

        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Which timezone the feed's day boundaries are cut in — both for the date
     * filter and for the "Today"/"Yesterday" headings the screen groups by, so
     * the two can never disagree about where a day ends.
     *
     * A board's own timezone when a single board is selected, so "yesterday"
     * means yesterday where that team works; the workspace default otherwise,
     * because a feed spanning several boards has no single right answer and
     * silently picking one board's would be worse than picking the default.
     */
    public function timezoneFor(?Authenticatable $user, ActivityFilters $filters): string
    {
        $fallback = (string) config('workspace.board_defaults.timezone', 'UTC');

        if ($filters->boardSlug === '') {
            return $fallback;
        }

        $board = $this->board($user, $filters->boardSlug);

        return $board instanceof Board
            ? (string) $board->setting('timezone', $fallback)
            : $fallback;
    }
}
