<?php

declare(strict_types=1);

namespace App\Livewire\Activity;

use App\Livewire\Concerns\ListensForBoardUpdates;
use App\Services\ActivityReader;
use App\Support\ActivityFilters;
use App\Support\ActivityTime;
use App\Support\StatsPeriod;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The workspace activity feed.
 *
 * Staff only, and the gate is checked twice for the two different reasons the
 * statistics screen documents:
 *
 *   mount() checks, because a component can be mounted by a Livewire request
 *   that never passed through the route and its `role` middleware;
 *   render() checks again, because Livewire rehydrates a component on every
 *   subsequent request and a user's role can change between two of them.
 *
 * A third check is the one that actually matters, and it is not here:
 * App\Models\Activity::readableBy() refuses a viewer without internal access
 * outright and constrains everyone else to their own boards, in SQL. The two
 * checks above turn "you would see nothing" into a 404, which is the better
 * answer; they are not what keeps the data in.
 *
 * 404 rather than 403, in keeping with the rest of the application: a customer
 * must not learn that a delivery-team feed exists.
 *
 * Nothing is filtered in the browser. The component holds seven scalars of
 * filter state and one page number; every row it renders came back from a query
 * that had already applied the viewer's board membership.
 */
#[Layout('layouts.app')]
#[Title('Activity')]
class Index extends Component
{
    use ListensForBoardUpdates;
    use WithPagination;

    /*
     * Filter state, in the query string so a filtered feed is a link.
     *
     * All seven are attacker-controlled and none of them can widen the feed —
     * see App\Support\ActivityFilters for what each one does with a value it
     * does not recognise.
     */

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'board', except: '')]
    public string $boardSlug = '';

    #[Url(as: 'user', except: '')]
    public string $userId = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(as: 'range', except: ActivityFilters::ALL_TIME)]
    public string $range = ActivityFilters::ALL_TIME;

    #[Url(as: 'from', except: '')]
    public string $customFrom = '';

    #[Url(as: 'to', except: '')]
    public string $customTo = '';

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    private function authorizeAccess(): void
    {
        if (! Gate::allows('view-internal-content')) {
            throw new NotFoundHttpException;
        }
    }

    /**
     * Realtime, reusing the board channels the rest of the application already
     * has rather than adding anything for this screen.
     *
     * Only when the feed is narrowed to one board. A cross-board feed would
     * otherwise need a subscription per board — a dozen websocket channels for
     * one page, most of them silent — and the alternative, polling, is exactly
     * what the brief rules out. `$refresh` re-runs the same authorized, scoped
     * query, so nothing arrives over the socket that the page would not have
     * shown anyway.
     *
     * With broadcasting switched off the trait returns no listeners and the
     * screen simply does not refresh itself, which is how every other realtime
     * surface here degrades.
     *
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        if ($this->boardSlug === '') {
            return [];
        }

        $board = app(ActivityReader::class)->board(auth()->user(), $this->boardSlug);

        return $this->boardUpdateListeners($board === null ? null : (int) $board->getKey());
    }

    /*
     * Any filter change goes back to page one. Without this, narrowing a feed
     * while on page four shows an empty list and looks like a bug.
     */

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedBoardSlug(): void
    {
        $this->resetPage();
    }

    public function updatedUserId(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function updatedRange(string $value): void
    {
        // Leaving the custom range clears its two dates, so they do not linger
        // in the URL and reappear the next time it is selected.
        if ($value !== StatsPeriod::CUSTOM) {
            $this->customFrom = '';
            $this->customTo = '';
        }

        $this->resetPage();
    }

    public function updatedCustomFrom(): void
    {
        $this->resetPage();
    }

    public function updatedCustomTo(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'boardSlug', 'userId', 'type', 'range', 'customFrom', 'customTo']);

        $this->resetPage();
    }

    public function render(ActivityReader $reader)
    {
        $this->authorizeAccess();

        $user = auth()->user();
        $filters = $this->filters();

        $timezone = $reader->timezoneFor($user, $filters);
        $activities = $reader->paginate($user, $filters);

        return view('livewire.activity.index', [
            'activities' => $activities,

            // Grouped for the day separators. Done here rather than in the
            // template so the view has no logic to get wrong, and grouped from
            // the page that was actually returned — a day spanning two pages
            // gets its heading on both, which is what a reader expects from a
            // paginated history.
            'groups' => $activities
                ->getCollection()
                ->groupBy(fn ($activity): string => ActivityTime::heading($activity->created_at, $timezone)),

            'timezone' => $timezone,
            'filters' => $filters,
            'boardOptions' => $reader->boardOptions($user),
            'actorOptions' => $reader->actorOptions($user),
            'typeOptions' => ActivityFilters::typeOptions(),
            'rangeOptions' => StatsPeriod::options(),
            'period' => $filters->period($timezone),
        ]);
    }

    /**
     * Component state as the value object the reader consumes.
     *
     * Rebuilt on every render rather than held as a property: a serialised
     * object is state the browser could tamper with, and rebuilding it costs
     * nothing and re-applies every correction in
     * App\Support\ActivityFilters::fromArray().
     */
    private function filters(): ActivityFilters
    {
        return ActivityFilters::fromArray([
            'search' => $this->search,
            'boardSlug' => $this->boardSlug,
            'userId' => $this->userId,
            'type' => $this->type,
            'range' => $this->range,
            'customFrom' => $this->customFrom,
            'customTo' => $this->customTo,
        ]);
    }
}
