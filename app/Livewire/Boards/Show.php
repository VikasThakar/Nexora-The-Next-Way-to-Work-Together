<?php

declare(strict_types=1);

namespace App\Livewire\Boards;

use App\Actions\Tickets\CreateTicket;
use App\Actions\Tickets\MoveTicket;
use App\Enums\TicketPriority;
use App\Livewire\Concerns\ListensForBoardUpdates;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Ticket;
use App\Services\BoardAccess;
use App\Services\TicketFinder;
use App\Support\TicketFilters;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The Kanban board.
 *
 * One component owns the whole board rather than nesting a component per card:
 * a drag-and-drop move has to re-render the source column, the destination
 * column and their counts together, and with per-card components that would be
 * several round trips instead of one.
 *
 * Access is decided once in mount() by BoardPolicy::view, which denies as 404.
 * Every ticket read then goes through TicketFinder, so which cards appear is
 * decided in SQL rather than by anything in this class.
 */
#[Layout('layouts.app')]
class Show extends Component
{
    use ListensForBoardUpdates;

    public Board $board;

    /*
     * Filters live in the query string so a filtered board is a link somebody
     * can paste to a colleague. None of them can widen visibility: they are
     * composed on top of the visibility scope, so the worst a hand-edited URL
     * achieves is fewer rows.
     */

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $assignee = '';

    /** @var array<int, string> */
    #[Url(except: [])]
    public array $priorities = [];

    /** @var array<int, int> */
    #[Url(as: 'labels', except: [])]
    public array $labelIds = [];

    #[Url(except: TicketFilters::VISIBILITY_ALL)]
    public string $visibility = TicketFilters::VISIBILITY_ALL;

    public bool $showFilters = false;

    /** Inline "add a card" state, one column at a time. */
    public ?int $quickAddColumnId = null;

    public string $quickAddTitle = '';

    public function mount(Board $board): void
    {
        $this->authorize('view', $board);

        $this->board = $board;
    }

    /**
     * Re-render when somebody else changes something on this board.
     *
     * The event carries no data, so this is a plain `$refresh`: the board is
     * rebuilt by the same scoped query that drew it in the first place, and a
     * viewer can never be shown a card the query would not have returned.
     *
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return $this->boardUpdateListeners(isset($this->board) ? (int) $this->board->getKey() : null);
    }

    // -----------------------------------------------------------------
    // Drag and drop
    // -----------------------------------------------------------------

    /**
     * Persist a card drop.
     *
     * Called by wire:sort with the dragged ticket id and its new index; the
     * destination column id is baked into each column's handler, so this is
     * always told where the card landed.
     *
     * All three arguments come from the browser and none is trusted:
     *   - the ticket is re-fetched through the visibility scope, so a customer
     *     cannot move a ticket they are not allowed to see;
     *   - the policy decides whether this user may move it at all;
     *   - the column is looked up within this board, so a foreign column id
     *     resolves to nothing rather than to another board.
     */
    public function moveTicket(int $ticketId, int $position, int $columnId, MoveTicket $mover, TicketFinder $finder): void
    {
        $ticket = $finder->query($this->board, auth()->user())
            ->whereKey($ticketId)
            ->first();

        abort_unless($ticket instanceof Ticket, 404);

        $this->authorize('move', $ticket);

        $column = $this->board->columns()->whereKey($columnId)->first();

        abort_unless($column instanceof BoardColumn, 404);

        $mover->handle($ticket, $column, max(0, $position), auth()->user());
    }

    // -----------------------------------------------------------------
    // Quick add
    // -----------------------------------------------------------------

    public function startQuickAdd(int $columnId): void
    {
        $this->authorize('create', [Ticket::class, $this->board]);

        $this->quickAddColumnId = $columnId;
        $this->quickAddTitle = '';
        $this->resetValidation();
    }

    public function cancelQuickAdd(): void
    {
        $this->quickAddColumnId = null;
        $this->quickAddTitle = '';
        $this->resetValidation();
    }

    /**
     * Create a ticket from just a title.
     *
     * The requested column is passed through, but CreateTicket overrides it for
     * a customer — their tickets always land in the first column — so this can
     * be offered to everyone without a branch here.
     */
    public function quickAdd(CreateTicket $createTicket): void
    {
        $this->authorize('create', [Ticket::class, $this->board]);

        $validated = $this->validate([
            'quickAddTitle' => ['required', 'string', 'max:200'],
            'quickAddColumnId' => ['required', 'integer'],
        ]);

        $createTicket->handle($this->board, [
            'title' => $validated['quickAddTitle'],
            'board_column_id' => $validated['quickAddColumnId'],
        ], auth()->user());

        $this->quickAddTitle = '';

        $this->dispatch('ticket-created');
    }

    // -----------------------------------------------------------------
    // Filters
    // -----------------------------------------------------------------

    public function clearFilters(): void
    {
        $this->reset(['search', 'assignee', 'priorities', 'labelIds', 'visibility']);
    }

    public function toggleFilters(): void
    {
        $this->showFilters = ! $this->showFilters;
    }

    private function filters(): TicketFilters
    {
        return TicketFilters::fromArray([
            'search' => $this->search,
            'assignee' => $this->assignee,
            'priorities' => $this->priorities,
            'labelIds' => $this->labelIds,
            'visibility' => $this->visibility,
        ]);
    }

    // -----------------------------------------------------------------
    // Render
    // -----------------------------------------------------------------

    public function render(TicketFinder $finder, BoardAccess $access)
    {
        $user = auth()->user();
        $filters = $this->filters();

        $columns = $this->board->columns()->ordered()->get();

        // One query for the whole board, grouped in PHP. Per-column queries
        // would cost a round trip per column plus their eager loads.
        $tickets = $finder->forBoard($this->board, $user, $filters);

        /** @var Collection<int, Collection<int, Ticket>> $byColumn */
        $byColumn = $tickets->groupBy('board_column_id');

        return view('livewire.boards.show', [
            'columns' => $columns,
            'ticketsByColumn' => $byColumn,
            'totalVisible' => $tickets->count(),
            'filters' => $filters,
            'boardLabels' => $this->board->labels()->ordered()->get(),
            'assignableMembers' => $this->board->assignableMembers()->orderBy('name')->get(),
            'priorityOptions' => TicketPriority::ordered(),
            'canCreateTickets' => $user->can('create', [Ticket::class, $this->board]),
            // Drag-and-drop is a delivery-team action; the same rule is
            // enforced per ticket by TicketPolicy::move when a drop arrives.
            'canReorder' => $user->isStaff(),
            'canConfigureBoard' => $access->canManageBoardContent($user, $this->board),
            'canManageBoard' => $user->can('update', $this->board),
            'canSeeInternal' => $access->canSeeInternalContent($user),
            'canUseAiChat' => $user->can('useAiChat', $this->board),
        ])->title($this->board->name);
    }
}
