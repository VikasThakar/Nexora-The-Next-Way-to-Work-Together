<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Models\AiRun;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Services\BoardAccess;
use App\Support\StatsPeriod;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who is asking, about which boards, over what range.
 *
 * Every statistics query in the application starts from a method on this class
 * and there is no unscoped entry point, for the same reason TicketFinder has
 * none: an aggregate is exactly the kind of query where a forgotten scope does
 * not look wrong. `SELECT COUNT(*) FROM tickets WHERE board_id = ?` returns a
 * number either way — it just happens to be a number that counts internal work
 * for a customer, and no assertion about the page's HTML would ever catch it.
 *
 * So the rules are applied once, here, in SQL:
 *
 *   - tickets and events come from the ordinary `visibleTo()` scopes, the same
 *     ones the board screen uses. A customer's totals therefore exclude
 *     internal tickets by construction, not by remembering to exclude them.
 *   - the board filter is intersected with the boards the viewer may reach,
 *     so a board id typed into the query string cannot widen the report.
 *   - AI runs refuse customers outright (see AiRun::visibleTo), so an AI figure
 *     is unavailable rather than zero for them.
 *
 * Immutable: `forBoard()` and `withPeriod()` return new instances, so a screen
 * can build several views of the same request without one leaking into another.
 */
final class StatisticsScope
{
    /**
     * Memoised for the life of one report.
     *
     * `doneColumnIds()` is asked for by the headline counts, the assignee
     * breakdown, the closure aggregate and the customer summary — four separate
     * places on one page, each of which would otherwise repeat the same
     * two-column lookup. Held here rather than in each caller because the scope
     * is the thing that knows it cannot change during a render.
     *
     * The class is therefore `final` rather than `readonly`: the public surface
     * is still immutable — every property below is individually readonly and
     * withPeriod() returns a new instance — but a whole-class `readonly` forbids
     * even a private cache.
     *
     * @var array<int, int>|null
     */
    private ?array $doneColumnIds = null;

    /**
     * @param  Collection<int, Board>  $boards  the boards actually in scope
     */
    private function __construct(
        public readonly ?Authenticatable $viewer,
        public readonly StatsPeriod $period,
        public readonly ?Board $board,
        public readonly Collection $boards,
    ) {}

    /**
     * Build a scope over every board the viewer may see, or one of them.
     *
     * `$board` is trusted only as a request: it is looked up again through
     * BoardAccess, so passing a board the viewer cannot reach yields an empty
     * scope rather than that board's numbers.
     */
    public static function for(
        ?Authenticatable $viewer,
        ?StatsPeriod $period = null,
        ?Board $board = null,
    ): self {
        $accessible = app(BoardAccess::class)
            ->query($viewer)
            ->notArchived()
            ->orderBy('name')
            ->get();

        $boards = $board instanceof Board
            ? $accessible->where('id', $board->getKey())->values()
            : $accessible;

        $selected = $board instanceof Board ? $boards->first() : null;

        return new self(
            $viewer,
            $period ?? StatsPeriod::default(self::timezoneFor($selected)),
            $selected,
            $boards,
        );
    }

    /**
     * A scope over nothing.
     *
     * Used when a board was explicitly asked for and could not be resolved —
     * because it does not exist, or because the viewer may not see it, which
     * are deliberately indistinguishable.
     *
     * The alternative, quietly falling back to "every board you can see", is
     * the more dangerous one. It is not a leak, but it answers a question
     * nobody asked while the filter still reads as that board's name, and a
     * workspace-wide figure screenshotted as one board's throughput is a
     * mistake somebody will make.
     */
    public static function none(?Authenticatable $viewer, ?StatsPeriod $period = null): self
    {
        return new self($viewer, $period ?? StatsPeriod::default(), null, collect());
    }

    public function withPeriod(StatsPeriod $period): self
    {
        return new self($this->viewer, $period, $this->board, $this->boards);
    }

    /**
     * The timezone the report's days and weeks are cut in.
     *
     * A single board reports in its own timezone; a workspace-wide report has
     * no single right answer, so it uses the workspace default.
     */
    public function timezone(): string
    {
        return self::timezoneFor($this->board);
    }

    /**
     * Every board id in scope.
     *
     * @return array<int, int>
     */
    public function boardIds(): array
    {
        return $this->boards->map(fn (Board $board): int => (int) $board->getKey())->all();
    }

    public function isEmpty(): bool
    {
        return $this->boards->isEmpty();
    }

    /**
     * Boards offered in the filter dropdown — only ones the viewer may see.
     *
     * @return array<int, string>
     */
    public function boardOptions(): array
    {
        return $this->boards->isEmpty() && $this->board === null
            ? []
            : app(BoardAccess::class)
                ->query($this->viewer)
                ->notArchived()
                ->orderBy('name')
                ->pluck('name', 'slug')
                ->all();
    }

    // -----------------------------------------------------------------
    // Base queries. Everything downstream starts from one of these.
    // -----------------------------------------------------------------

    /**
     * Tickets in scope, as they stand now.
     *
     * Used for the "how many are open right now" counts, which are genuinely
     * questions about the present. Anything about *flow* uses events() instead.
     *
     * @return Builder<Ticket>
     */
    public function tickets(): Builder
    {
        return Ticket::query()
            ->visibleTo($this->viewer)
            ->whereIn('tickets.board_id', $this->boardIds());
    }

    /**
     * Tickets created within the period.
     *
     * @return Builder<Ticket>
     */
    public function ticketsCreatedInPeriod(): Builder
    {
        return $this->tickets()
            ->whereBetween('tickets.created_at', [$this->period->from, $this->period->to]);
    }

    /**
     * History in scope and in range.
     *
     * `readableBy` rather than `visibleTo`: it adds the event-type deny-list on
     * top of board scoping, so a customer's activity feed cannot include a
     * visibility flip or an AI run.
     *
     * @return Builder<TicketEvent>
     */
    public function events(): Builder
    {
        return TicketEvent::query()
            ->readableBy($this->viewer)
            ->whereIn('ticket_events.board_id', $this->boardIds())
            ->between($this->period->from, $this->period->to);
    }

    /**
     * History in scope, ignoring the period.
     *
     * Cycle time needs this: a ticket closed on Tuesday may have been opened
     * long before the range began, and its start has to be findable.
     *
     * @return Builder<TicketEvent>
     */
    public function allEvents(): Builder
    {
        return TicketEvent::query()
            ->readableBy($this->viewer)
            ->whereIn('ticket_events.board_id', $this->boardIds());
    }

    /**
     * AI runs in scope and in range.
     *
     * Empty for a customer, by refusal rather than by filter — see
     * AiRun::scopeVisibleTo.
     *
     * @return Builder<AiRun>
     */
    public function aiRuns(): Builder
    {
        return AiRun::query()
            ->visibleTo($this->viewer)
            ->whereIn('ai_runs.board_id', $this->boardIds())
            ->whereBetween('ai_runs.created_at', [$this->period->from, $this->period->to]);
    }

    /**
     * Ids of the columns that mean "finished", across the boards in scope.
     *
     * Read once per report and passed down, because every flow metric needs it
     * and it is a two-column index lookup that would otherwise repeat five
     * times on one page.
     *
     * @return array<int, int>
     */
    public function doneColumnIds(): array
    {
        if ($this->doneColumnIds !== null) {
            return $this->doneColumnIds;
        }

        $ids = $this->boardIds();

        if ($ids === []) {
            return $this->doneColumnIds = [];
        }

        return $this->doneColumnIds = BoardColumn::query()
            ->whereIn('board_id', $ids)
            ->where('is_done', true)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private static function timezoneFor(?Board $board): string
    {
        $default = (string) config('workspace.board_defaults.timezone', 'UTC');

        if (! $board instanceof Board) {
            return $default;
        }

        return (string) $board->setting('timezone', $default);
    }
}
