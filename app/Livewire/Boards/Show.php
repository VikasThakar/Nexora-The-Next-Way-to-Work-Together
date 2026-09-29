<?php

declare(strict_types=1);

namespace App\Livewire\Boards;

use App\Actions\Tickets\AttachFilesToTicket;
use App\Actions\Tickets\CreateTicket;
use App\Actions\Tickets\MoveTicket;
use App\Enums\TicketPriority;
use App\Enums\TicketType;
use App\Livewire\Concerns\ListensForBoardUpdates;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Ticket;
use App\Services\BoardAccess;
use App\Services\TicketFinder;
use App\Support\TicketFilters;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

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
    use WithFileUploads;

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

    /*
     * Inline "add a card" state, one column at a time.
     *
     * The client asked for type, assignee, priority, labels and a due date here
     * alongside the title. All five already exist on a ticket, so nothing new
     * is introduced — but a Kanban column is 18rem wide, and six controls
     * stacked in it would be a form, not a quick add.
     *
     * So the title stays alone and the rest live behind `quickAddExpanded`.
     * Enter still creates a ticket from a title and nothing else, which is the
     * interaction people actually use fifty times a day; the details are one
     * click away for the times they are wanted.
     */
    public ?int $quickAddColumnId = null;

    public string $quickAddTitle = '';

    public bool $quickAddExpanded = false;

    public string $quickAddType = '';

    public string $quickAddPriority = '';

    public string $quickAddAssigneeId = '';

    public string $quickAddDueDate = '';

    /** @var array<int, int> */
    public array $quickAddLabelIds = [];

    /**
     * Files just dropped, pasted or chosen on the quick-add card.
     *
     * Livewire's temporary uploads for one request. updatedQuickAddUploads()
     * validates them and moves them into $quickAddFiles, so this is empty
     * whenever the component is idle — the same shape as the assistant's
     * composer, and for the same reason: a file input bound with wire:model
     * replaces its value on every choice, and the card has to accumulate.
     *
     * @var array<int, TemporaryUploadedFile>
     */
    public array $quickAddUploads = [];

    /**
     * Files waiting for the ticket to exist.
     *
     * A screenshot is the fastest way to describe a bug, and the quick-add
     * card is the fastest way to file one, so the two belong together: the
     * files are held here as temporary uploads, shown as thumbnails on the
     * card, and stored against the ticket the moment quickAdd() has created
     * it — pictures embedded in the description so they show, everything
     * else attached.
     *
     * @var array<int, TemporaryUploadedFile>
     */
    public array $quickAddFiles = [];

    public function mount(Board $board): void
    {
        $this->authorize('view', $board);

        $this->board = $board;

        $this->resetQuickAdd();
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
        $this->resetQuickAdd();
        $this->resetValidation();
    }

    public function cancelQuickAdd(): void
    {
        $this->quickAddColumnId = null;
        $this->quickAddExpanded = false;
        $this->resetQuickAdd();
        $this->resetValidation();
    }

    /**
     * Show or hide the extra fields.
     *
     * Not reset when it closes: somebody filing three bugs in a row should not
     * have to reopen the details each time.
     */
    public function toggleQuickAddDetails(): void
    {
        $this->quickAddExpanded = ! $this->quickAddExpanded;
    }

    public function toggleQuickAddLabel(int $labelId): void
    {
        $selected = collect($this->quickAddLabelIds);

        $this->quickAddLabelIds = $selected->contains($labelId)
            ? $selected->reject(fn ($id): bool => (int) $id === $labelId)->values()->all()
            : $selected->push($labelId)->values()->all();
    }

    /**
     * Create a ticket from the inline form.
     *
     * Every field is passed straight through to CreateTicket, which is the only
     * place that decides what a given author is allowed to set: a customer's
     * ticket lands in the first column, unassigned, with no labels and visible
     * to them, however this form was filled in or forged. Nothing is branched
     * on the role here, so the two cannot fall out of step.
     */
    /**
     * Files chosen on the card: validate, then keep.
     *
     * The same limits as every other place a file can be added, read from the
     * same configuration. A refused file is reported and the rest are kept,
     * so one oversized recording does not throw away the screenshots that
     * came with it.
     */
    public function updatedQuickAddUploads(): void
    {
        $this->authorize('create', [Ticket::class, $this->board]);

        $limit = (int) config('attachments.max_per_owner');

        $this->validate([
            'quickAddUploads' => ['array', 'max:'.max(0, $limit - count($this->quickAddFiles))],
            'quickAddUploads.*' => [
                'file',
                'max:'.config('attachments.max_size_kb'),
                'mimes:'.implode(',', (array) config('attachments.allowed_mimes')),
            ],
        ], [
            'quickAddUploads.max' => 'A ticket can have at most '.$limit.' attachments.',
        ], ['quickAddUploads.*' => 'file']);

        foreach ($this->quickAddUploads as $file) {
            if ($file instanceof TemporaryUploadedFile) {
                $this->quickAddFiles[] = $file;
            }
        }

        $this->reset('quickAddUploads');
    }

    public function removeQuickAddFile(int $index): void
    {
        if (! array_key_exists($index, $this->quickAddFiles)) {
            return;
        }

        $file = $this->quickAddFiles[$index];

        unset($this->quickAddFiles[$index]);
        $this->quickAddFiles = array_values($this->quickAddFiles);

        if ($file instanceof TemporaryUploadedFile) {
            try {
                $file->delete();
            } catch (\Throwable) {
                // Already gone is the outcome wanted.
            }
        }
    }

    /**
     * The staged files, described for the card.
     *
     * @return array<int, array{name: string, image: bool, url: ?string}>
     */
    public function quickAddFilePreviews(): array
    {
        $previews = [];

        foreach ($this->quickAddFiles as $file) {
            if (! $file instanceof TemporaryUploadedFile) {
                continue;
            }

            $type = (string) ($file->getMimeType() ?: $file->getClientMimeType());
            $image = str_starts_with($type, 'image/');

            $url = null;

            if ($image) {
                try {
                    $url = $file->temporaryUrl();
                } catch (\Throwable) {
                    $url = null;
                }
            }

            $previews[] = [
                'name' => $file->getClientOriginalName(),
                'image' => $image,
                'url' => $url,
            ];
        }

        return $previews;
    }

    public function quickAdd(CreateTicket $createTicket, AttachFilesToTicket $attach): void
    {
        $this->authorize('create', [Ticket::class, $this->board]);

        $validated = $this->validate([
            'quickAddTitle' => ['required', 'string', 'max:200'],
            'quickAddColumnId' => ['required', 'integer'],
            'quickAddType' => ['nullable', Rule::enum(TicketType::class)],
            'quickAddPriority' => ['nullable', Rule::enum(TicketPriority::class)],
            'quickAddAssigneeId' => ['nullable', 'integer'],
            'quickAddDueDate' => ['nullable', 'date'],
            'quickAddLabelIds' => ['array'],
            'quickAddLabelIds.*' => ['integer'],
        ], attributes: [
            'quickAddTitle' => 'title',
            'quickAddDueDate' => 'due date',
        ]);

        $ticket = $createTicket->handle($this->board, [
            'title' => $validated['quickAddTitle'],
            'board_column_id' => $validated['quickAddColumnId'],
            'type' => $validated['quickAddType'] ?: null,
            'priority' => $validated['quickAddPriority'] ?: null,
            'assignee_id' => $validated['quickAddAssigneeId'] ?: null,
            'due_date' => $validated['quickAddDueDate'] ?: null,
            'label_ids' => $validated['quickAddLabelIds'],
        ], auth()->user());

        /*
         * The files that were waiting for it. Stored against the ticket and
         * — for pictures — written into its description so they show, rather
         * than sitting as names in a list.
         */
        if ($this->quickAddFiles !== []) {
            $attach->embed($ticket, $attach->uploads($ticket, $this->quickAddFiles, auth()->user()));
        }

        // The form stays open on the same column, cleared and ready: adding
        // several cards in a row is the whole point of quick add.
        $this->resetQuickAdd();

        $this->dispatch('ticket-created');
    }

    /**
     * Clear the form back to its defaults, leaving the column selection and
     * the expanded/collapsed choice alone.
     */
    private function resetQuickAdd(): void
    {
        $this->quickAddTitle = '';
        $this->quickAddType = TicketType::default()->value;
        $this->quickAddPriority = TicketPriority::default()->value;
        $this->quickAddAssigneeId = '';
        $this->quickAddDueDate = '';
        $this->quickAddLabelIds = [];
        $this->quickAddUploads = [];
        $this->quickAddFiles = [];
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
            'typeOptions' => TicketType::ordered(),
            'canCreateTickets' => $user->can('create', [Ticket::class, $this->board]),

            // Quick add offers these only to staff, mirroring what
            // CreateTicket will actually honour. Showing a customer an
            // assignee picker whose value is then dropped would be a lie.
            'canQuickAddDetails' => $user->isStaff(),
            'quickAddFilePreviews' => $this->quickAddFilePreviews(),
            // Drag-and-drop is a delivery-team action; the same rule is
            // enforced per ticket by TicketPolicy::move when a drop arrives.
            'canReorder' => $user->isStaff(),
            'canConfigureBoard' => $access->canManageBoardContent($user, $this->board),
            'canManageBoard' => $user->can('update', $this->board),
            'canSeeInternal' => $access->canSeeInternalContent($user),
        ])->title($this->board->name);
    }
}
