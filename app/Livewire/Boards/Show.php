<?php

declare(strict_types=1);

namespace App\Livewire\Boards;

use App\Actions\Attachments\AdoptDraftAttachments;
use App\Actions\Tickets\CreateTicket;
use App\Actions\Tickets\MoveTicket;
use App\Actions\Tickets\MoveTicketToTab;
use App\Enums\TicketEventType;
use App\Enums\TicketPriority;
use App\Enums\TicketType;
use App\Livewire\Boards\Concerns\ManagesBoardTabs;
use App\Livewire\Concerns\CollectsDraftAttachments;
use App\Livewire\Concerns\ListensForBoardUpdates;
use App\Models\Attachment;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\BoardTab;
use App\Models\Ticket;
use App\Services\AttachmentStorage;
use App\Services\BoardAccess;
use App\Services\TicketActivity;
use App\Services\TicketFinder;
use App\Support\TicketFilters;
use App\Support\TicketPlace;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * The Kanban board.
 *
 * One component owns the whole board rather than nesting a component per card:
 * a drag-and-drop move has to re-render the source column, the destination
 * column and their counts together, and with per-card components that would be
 * several round trips instead of one.
 *
 * A board is split into tabs, each with its own columns, and this shows one
 * tab at a time — the tab strip above the columns switches between them
 * without leaving the board. The tab on screen is in the URL (?tab=<slug>), so
 * a tab is a link, refresh keeps it and back/forward steps between tabs; a
 * board opened without one shows the tab this person last had open there.
 *
 * Access is decided once in mount() by BoardPolicy::view, which denies as 404.
 * Every ticket read then goes through TicketFinder, so which cards appear is
 * decided in SQL rather than by anything in this class.
 */
#[Layout('layouts.app')]
class Show extends Component
{
    use CollectsDraftAttachments;
    use ListensForBoardUpdates;
    use ManagesBoardTabs;
    use WithFileUploads;

    public Board $board;

    /*
     * The tab on screen, by its slug. '' means the board's default tab, so the
     * plain board URL stays plain; any other tab appears as ?tab=<slug>.
     * history: true makes the browser's back and forward buttons step between
     * the tabs somebody opened, as they would between pages.
     */
    #[Url(as: 'tab', history: true, except: '')]
    public string $tab = '';

    /** A short message under the tab strip ("GRI-12 moved to …"). */
    public ?string $notice = null;

    /*
     * Filters live in the query string so a filtered board is a link somebody
     * can paste to a colleague. None of them can widen visibility: they are
     * composed on top of the visibility scope, so the worst a hand-edited URL
     * achieves is fewer rows.
     *
     * They belong to the board, not to a tab: switching tabs keeps them, and
     * the counts on the tab strip say where the matching tickets are.
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
     * is introduced — but a Kanban column is 20rem wide, and six form controls
     * stacked in it would be a form, not a quick add.
     *
     * That was once solved with a `quickAddExpanded` flag and a Details button.
     * It is not any more, and the flag is gone: the fields were only ever
     * collapsed with a `hidden` class, so they stayed in the document and
     * stayed bound either way, and a card filed without opening them still
     * submitted a type and a priority. The disclosure bought no simplicity
     * anywhere — not here, not in the request — and cost a click.
     *
     * What makes the controls fit instead is their size. Priority is five
     * coloured dots, type is three small pills, and both are built from radios
     * that need no room for a label because they *are* the label. See
     * resources/views/components/ui/choice.blade.php.
     *
     * Enter still creates a ticket from a title and nothing else, which is the
     * interaction people actually use fifty times a day.
     */
    public ?int $quickAddColumnId = null;

    public string $quickAddTitle = '';

    public string $quickAddType = '';

    public string $quickAddPriority = '';

    public string $quickAddAssigneeId = '';

    public string $quickAddDueDate = '';

    /** @var array<int, int> */
    public array $quickAddLabelIds = [];

    /**
     * Whether the card is visible to the board's customers.
     *
     * Starts from the board's default (ticked, unless the board says
     * otherwise) and is offered to staff only; CreateTicket forces a
     * customer's own card visible whatever arrives here.
     */
    public bool $quickAddCustomerVisible = true;

    /**
     * Files dropped onto the open quick-add card.
     *
     * Named so that Livewire's `updated` hook fires, unlike the editor's
     * equivalent: there is no editor here to hand URLs back to, so storing on
     * the property write is exactly what is wanted. The card renders the stored
     * drafts, and the browser never needs to know where they live.
     *
     * @var array<int, TemporaryUploadedFile>
     */
    public array $quickAddUploads = [];

    /** The tab on screen, resolved once per request (see activeTab()). */
    private ?BoardTab $resolvedTab = null;

    public function mount(Board $board): void
    {
        $this->authorize('view', $board);

        $this->board = $board;

        $this->openInitialTab();

        $this->resetQuickAdd();
    }

    /**
     * The board the quick add's files are filed under.
     *
     * Required by CollectsDraftAttachments. There is only ever one board on
     * this screen, which is why the trait's board and the component's are the
     * same thing.
     */
    protected function uploadBoard(): Board
    {
        return $this->board;
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
    // Tabs
    // -----------------------------------------------------------------

    /**
     * Click on a tab in the strip.
     */
    public function selectTab(int $tabId): void
    {
        $tab = $this->tabOnBoard($tabId);

        if ($this->activeTab() instanceof BoardTab && $tab->is($this->activeTab())) {
            return;
        }

        $this->leaveTab();
        $this->switchTo($tab);
    }

    /**
     * The browser's back or forward button changed ?tab — or somebody edited
     * it. Resolved within this board like a click, never trusted.
     */
    public function updatedTab(string $value): void
    {
        $this->resolvedTab = null;

        $tab = $value === '' ? $this->board->defaultTab() : $this->board->tabBySlug($value);

        if ($value !== '' && ! $tab instanceof BoardTab) {
            $this->notice = 'That tab does not exist on this board any more.';
        }

        $this->leaveTab();
        $this->switchTo($tab);
    }

    public function dismissNotice(): void
    {
        $this->notice = null;
    }

    /**
     * Pick the tab a fresh visit opens on: the one in the URL, else the one
     * this person last had open on this board, else the default tab. A slug
     * that is not one of this board's tabs falls back, with a word about it,
     * and is corrected in the URL.
     */
    private function openInitialTab(): void
    {
        $requested = $this->tab;
        $tab = $this->board->tabBySlug($requested);

        if ($requested !== '' && ! $tab instanceof BoardTab) {
            $this->notice = 'That tab does not exist on this board any more.';
        }

        if ($requested === '') {
            $remembered = session()->get($this->rememberKey());

            $tab = is_numeric($remembered)
                ? $this->board->tabs()->whereKey((int) $remembered)->first()
                : null;
        }

        $this->switchTo($tab);
    }

    /**
     * Put a tab on screen: the URL, the memory of the last tab, and a word to
     * the assistant panel (which sits outside this component) about where the
     * person now is.
     */
    protected function switchTo(?BoardTab $tab): void
    {
        $default = $this->board->defaultTab();
        $tab ??= $default;

        $this->resolvedTab = $tab;
        $this->tab = $tab instanceof BoardTab && ! $tab->is($default) ? (string) $tab->slug : '';

        if ($tab instanceof BoardTab) {
            session()->put($this->rememberKey(), (int) $tab->getKey());
        }

        $this->dispatch('board-tab-changed', board: $this->board->slug, tab: $tab?->slug);
    }

    /**
     * Whatever belonged to the tab being left: an open quick-add card (and any
     * files dropped on it), and an open delete panel.
     */
    protected function leaveTab(): void
    {
        if ($this->quickAddColumnId !== null) {
            $this->discardDraftUploads(app(AttachmentStorage::class));
            $this->quickAddColumnId = null;
            $this->resetQuickAdd();
            $this->resetValidation();
        }

        $this->cancelDeletingTab();
    }

    /**
     * The tab on screen.
     *
     * Resolved from the slug on every request — another person may have
     * removed the tab since this page was drawn, in which case the board falls
     * back to its default tab and says so rather than showing nothing.
     *
     * Null only for a board with no tabs at all, which the application never
     * creates (every board starts with "Main") but which the board still
     * draws: as the "no columns" state, with "+" to add a first tab.
     */
    protected function activeTab(): ?BoardTab
    {
        if ($this->resolvedTab instanceof BoardTab) {
            return $this->resolvedTab;
        }

        $this->board->loadMissing('tabs');

        $tab = $this->tab === '' ? $this->board->defaultTab() : $this->board->tabBySlug($this->tab);

        if (! $tab instanceof BoardTab && $this->tab !== '') {
            $tab = $this->board->defaultTab();
            $this->tab = '';
            $this->notice = $tab instanceof BoardTab
                ? 'The tab you had open was removed, so this is '.$tab->name.'.'
                : null;
        }

        return $this->resolvedTab = $tab;
    }

    /**
     * The tab on screen for an action that needs one — every action that
     * acts on a column does. 404 on a board with no tabs.
     */
    private function requireActiveTab(): BoardTab
    {
        $tab = $this->activeTab();

        abort_unless($tab instanceof BoardTab, 404);

        return $tab;
    }

    private function rememberKey(): string
    {
        return 'board-tab.'.$this->board->getKey();
    }

    // -----------------------------------------------------------------
    // Drag and drop
    // -----------------------------------------------------------------

    /**
     * Persist a card drop.
     *
     * Called by x-sort with the dragged ticket id and its new index; the
     * destination column id is baked into each column's handler, so this is
     * always told where the card landed.
     *
     * All three arguments come from the browser and none is trusted:
     *   - the ticket is re-fetched through the visibility scope, so a customer
     *     cannot move a ticket they are not allowed to see;
     *   - the policy decides whether this user may move it at all;
     *   - the column is looked up within the tab on screen, so a foreign
     *     column id resolves to nothing rather than to another board — or to
     *     another tab, which MoveTicket refuses on its own as well.
     */
    public function moveTicket(int $ticketId, int $position, int $columnId, MoveTicket $mover, TicketFinder $finder): void
    {
        $ticket = $finder->query($this->board, auth()->user())
            ->whereKey($ticketId)
            ->first();

        abort_unless($ticket instanceof Ticket, 404);

        $this->authorize('move', $ticket);

        $column = $this->requireActiveTab()->columns()->whereKey($columnId)->first();

        abort_unless($column instanceof BoardColumn, 404);

        try {
            $mover->handle($ticket, $column, max(0, $position), auth()->user());
        } catch (RuntimeException $exception) {
            // The re-render puts the card back where it really is.
            $this->dispatch('dialog', type: 'error', title: 'The ticket was not moved', body: $exception->getMessage());
        }
    }

    /**
     * Persist a card dropped on another tab in the strip.
     *
     * Same distrust as moveTicket(): the ticket comes through the visibility
     * scope, TicketPolicy::move decides, and the tab is resolved within this
     * board. Where it lands is App\Support\TabColumnMapping's decision, made in
     * MoveTicketToTab — the same as the Tab select on the ticket page.
     */
    public function moveTicketToTab(int $ticketId, int $tabId, MoveTicketToTab $mover, TicketFinder $finder): void
    {
        $ticket = $finder->query($this->board, auth()->user())
            ->whereKey($ticketId)
            ->first();

        abort_unless($ticket instanceof Ticket, 404);

        $this->authorize('move', $ticket);

        $tab = $this->tabOnBoard($tabId);

        if ((int) BoardColumn::query()->whereKey($ticket->board_column_id)->value('board_tab_id') === (int) $tab->getKey()) {
            return;
        }

        try {
            $column = $mover->handle($ticket, $tab, auth()->user());
        } catch (RuntimeException $exception) {
            $this->dispatch('dialog', type: 'error', title: 'The ticket was not moved', body: $exception->getMessage());

            return;
        }

        $column->setRelation('tab', $tab);
        $ticket->setRelation('board', $this->board);

        $this->notice = $ticket->key().' moved to '.TicketPlace::column($column, true).'.';
    }

    // -----------------------------------------------------------------
    // Quick add
    // -----------------------------------------------------------------

    public function startQuickAdd(int $columnId, AttachmentStorage $storage): void
    {
        $this->authorize('create', [Ticket::class, $this->board]);

        // Only a column of the tab on screen: that is where the card is drawn.
        abort_unless($this->requireActiveTab()->columns()->whereKey($columnId)->exists(), 404);

        // Opening a card on another column abandons the one that was open, so
        // anything dropped on it goes too. Carrying the files across would put
        // a screenshot on a ticket nobody dropped it on.
        $this->discardDraftUploads($storage);

        $this->quickAddColumnId = $columnId;
        $this->resetQuickAdd();
        $this->resetValidation();
    }

    public function cancelQuickAdd(AttachmentStorage $storage): void
    {
        // Taken down now rather than left for the pruner. Somebody who presses
        // Cancel has said what they want to happen to the screenshot they just
        // dropped, and a file that outlives the form that holds it is a file
        // nothing on screen can reach.
        $this->discardDraftUploads($storage);

        $this->quickAddColumnId = null;
        $this->resetQuickAdd();
        $this->resetValidation();
    }

    /**
     * Store files dropped on the open card.
     *
     * The same validation as every other upload in the product, read from the
     * same configuration — a quick add is a faster way to file a ticket, not a
     * looser one. Images are the case this exists for, but the allow-list is
     * not narrowed to them: dropping the PDF a customer emailed onto a new card
     * is the same gesture and the same intent.
     */
    public function updatedQuickAddUploads(AttachmentStorage $storage): void
    {
        $this->authorize('create', [Ticket::class, $this->board]);

        $this->validate([
            'quickAddUploads' => ['array', 'max:'.config('attachments.max_per_owner')],
            'quickAddUploads.*' => [
                'file',
                'max:'.config('attachments.max_size_kb'),
                'mimes:'.implode(',', (array) config('attachments.allowed_mimes')),
            ],
        ], attributes: ['quickAddUploads.*' => 'file']);

        $this->storeDraftUploads($this->quickAddUploads, $storage);

        // uploadMultiple appends, so without this a second drop would re-store
        // everything from the first.
        $this->reset('quickAddUploads');
    }

    /**
     * Take a file back off the card before the ticket is filed.
     */
    public function removeQuickAddUpload(int $attachmentId, AttachmentStorage $storage): void
    {
        $this->authorize('create', [Ticket::class, $this->board]);

        $draft = $this->draftAttachments()->firstWhere('id', $attachmentId);

        if (! $draft instanceof Attachment) {
            return;
        }

        $storage->delete($draft);

        $this->draftAttachmentIds = array_values(array_filter(
            $this->draftAttachmentIds,
            fn (int $id): bool => $id !== $attachmentId,
        ));
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
     * ticket lands in the first column of the tab, unassigned, with no labels
     * and visible to them, however this form was filled in or forged. Nothing
     * is branched on the role here, so the two cannot fall out of step.
     */
    public function quickAdd(CreateTicket $createTicket, AdoptDraftAttachments $adopt): void
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

        $tab = $this->requireActiveTab();

        // The column the card was opened on, still in the tab on screen. If
        // somebody removed it meanwhile, say so rather than filing the ticket
        // somewhere nobody chose.
        if (! $tab->columns()->whereKey((int) $validated['quickAddColumnId'])->exists()) {
            $this->addError('quickAddTitle', 'That column was removed while you were typing. Nothing was created.');

            return;
        }

        $ticket = $createTicket->handle($this->board, [
            'title' => $validated['quickAddTitle'],
            'board_tab_id' => $tab->getKey(),
            'board_column_id' => $validated['quickAddColumnId'],
            'type' => $validated['quickAddType'] ?: null,
            'priority' => $validated['quickAddPriority'] ?: null,
            'assignee_id' => $validated['quickAddAssigneeId'] ?: null,
            'due_date' => $validated['quickAddDueDate'] ?: null,
            'label_ids' => $validated['quickAddLabelIds'],
            'customer_visible' => $this->quickAddCustomerVisible,
        ], auth()->user());

        /*
         * The dropped files become this ticket's attachments, and each one is
         * written to its timeline exactly as a file added from the ticket page
         * would be. A card that arrives with two screenshots should read, a
         * week later, as a card somebody attached two screenshots to.
         */
        foreach ($this->adoptDraftUploads($ticket, $adopt) as $attachment) {
            app(TicketActivity::class)->record($ticket, TicketEventType::AttachmentChanged, [
                'action' => 'added',
                'filename' => $attachment->filename,
            ], auth()->user());
        }

        // The form stays open on the same column, cleared and ready: adding
        // several cards in a row is the whole point of quick add.
        $this->resetQuickAdd();

        $this->dispatch('ticket-created');
    }

    /**
     * Clear the form back to its defaults, leaving the column selection alone.
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
        $this->quickAddCustomerVisible = $this->board->newTicketsVisibleToCustomers();
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

        $active = $this->activeTab();
        $tabs = $this->board->tabs;

        $columns = $active?->columns()->get() ?? new Collection;

        // One query for the tab on screen, grouped in PHP. Per-column queries
        // would cost a round trip per column plus their eager loads.
        $tickets = $active instanceof BoardTab
            ? $finder->forBoard($this->board, $user, $filters, $active)
            : new Collection;

        /** @var Collection<int, Collection<int, Ticket>> $byColumn */
        $byColumn = $tickets->groupBy('board_column_id');

        $canConfigureBoard = $access->canManageBoardContent($user, $this->board);

        return view('livewire.boards.show', [
            'tabs' => $tabs,
            'activeTab' => $active,
            // Matching tickets per tab, by the same scope and filters as the
            // cards — so a customer's counts leave out internal work too.
            'tabCounts' => $finder->countsByTab($this->board, $user, $filters),
            // One tab and nothing to manage is no strip at all: a board that
            // was never split reads exactly as it always has.
            'showTabStrip' => $tabs->count() > 1 || $canConfigureBoard,
            'tabParam' => $this->tab !== '' ? $this->tab : null,
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
            // Drag-and-drop is a delivery-team action; the same rule is
            // enforced per ticket by TicketPolicy::move when a drop arrives.
            'canReorder' => $user->isStaff(),
            'canConfigureBoard' => $canConfigureBoard,
            'canManageBoard' => $user->can('update', $this->board),
            'canSeeInternal' => $access->canSeeInternalContent($user),

            // Files already dropped on the open card. Only ever non-empty while
            // a quick add is open, so the board pays nothing for this when it
            // is not.
            'quickAddAttachments' => $this->quickAddColumnId === null
                ? new Collection
                : $this->draftAttachments(),
            'maxAttachmentMb' => round(((int) config('attachments.max_size_kb')) / 1024, 1),
            ...$this->deleteTabPanel(),
        ])->title($this->board->name);
    }
}
