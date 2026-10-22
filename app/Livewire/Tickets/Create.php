<?php

declare(strict_types=1);

namespace App\Livewire\Tickets;

use App\Actions\Attachments\AdoptDraftAttachments;
use App\Actions\Tickets\CreateTicket;
use App\Enums\TicketPriority;
use App\Enums\TicketType;
use App\Livewire\Concerns\EditsRichText;
use App\Models\Attachment;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\BoardTab;
use App\Models\Ticket;
use App\Services\AttachmentStorage;
use App\Support\Markdown;
use App\Support\RichText\RichText;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Full ticket creation form.
 *
 * Customers get a deliberately smaller form: a title, a description, a
 * priority and a due date. Column, assignee, estimate, labels and visibility
 * are not offered, and — more importantly — CreateTicket ignores them for a
 * customer even if they are submitted anyway. The form shape is convenience;
 * the action is the rule.
 *
 * On a board split into tabs the form starts in the tab the board was showing
 * (?tab=<slug> from its New ticket button) and offers a Tab choice to
 * everyone — customers see tab names, as they see column names. A customer's
 * request then starts in that tab's first column.
 */
#[Layout('layouts.app')]
class Create extends Component
{
    use EditsRichText;

    public Board $board;

    public string $title = '';

    public string $descriptionMd = '';

    public bool $previewing = false;

    public string $type = '';

    public string $priority = '';

    /** The tab the ticket goes in, by slug; '' is the board's default tab. */
    #[Url(as: 'tab', except: '')]
    public string $tab = '';

    public string $columnId = '';

    public string $assigneeId = '';

    public string $estimate = '';

    public string $dueDate = '';

    public bool $customerVisible = false;

    /** @var array<int, int> */
    public array $selectedLabelIds = [];

    public function mount(Board $board): void
    {
        $this->authorize('create', [Ticket::class, $board]);

        $this->board = $board;
        $this->type = TicketType::default()->value;
        $this->priority = TicketPriority::default()->value;
        $this->tab = $this->normalisedTab($this->tab);
        $this->columnId = (string) ($this->selectedTab()?->firstColumn()?->getKey() ?? '');

        // Ticked unless the board says otherwise: work on a board is usually
        // the customer's work. Unticking it is how a ticket stays internal.
        $this->customerVisible = $board->newTicketsVisibleToCustomers();
    }

    public function togglePreview(): void
    {
        $this->previewing = ! $this->previewing;
    }

    /**
     * A different tab: its columns are different, so the column choice starts
     * again from that tab's first column.
     */
    public function updatedTab(string $value): void
    {
        $this->tab = $this->normalisedTab($value);
        $this->columnId = (string) ($this->selectedTab()?->firstColumn()?->getKey() ?? '');
    }

    /**
     * The tab chosen, resolved within this board — the default tab for
     * anything else.
     */
    private function selectedTab(): ?BoardTab
    {
        return $this->board->tabBySlug($this->tab) ?? $this->board->defaultTab();
    }

    /**
     * '' for the default tab (so the URL stays plain), the slug for any other
     * of this board's tabs, and '' for a slug that is not one of them.
     */
    private function normalisedTab(string $slug): string
    {
        $tab = $this->board->tabBySlug($slug);

        return $tab instanceof BoardTab && ! $tab->is($this->board->defaultTab()) ? (string) $tab->slug : '';
    }

    /**
     * The board files dropped into this form are filed under.
     */
    protected function uploadBoard(): Board
    {
        return $this->board;
    }

    /**
     * Files may be dropped here before the ticket exists.
     *
     * There is no owner to authorize against yet, so what stands in its place is
     * the check this component already makes on mount and again on save: this
     * person may create a ticket on this board. Somebody who cannot file a
     * ticket here never reaches a form that would take their screenshot.
     *
     * Re-asked rather than remembered from mount(), because a Livewire action is
     * a fresh request and board membership can be revoked between the two.
     */
    protected function allowsDraftUploads(): bool
    {
        return auth()->user()?->can('create', [Ticket::class, $this->board]) === true;
    }

    /**
     * Drop a file that was attached before the ticket was filed.
     *
     * Only reachable while the form is still open, which is the window in which
     * a draft exists at all. The trait re-resolves the id against this uploader
     * and this board, so a tampered one removes nothing.
     */
    public function removeDraftAttachment(int $attachmentId, AttachmentStorage $storage): void
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

    public function save(CreateTicket $createTicket, RichText $rich, AdoptDraftAttachments $adopt)
    {
        $this->authorize('create', [Ticket::class, $this->board]);

        // Converted before validation, so the length limit applies to what
        // will be stored rather than to the editor's much longer HTML.
        $this->descriptionMd = $this->markdownFromEditor($rich, $this->descriptionMd);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:200'],
            'descriptionMd' => ['nullable', 'string', 'max:20000'],
            'type' => ['required', Rule::enum(TicketType::class)],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'columnId' => ['nullable', 'integer'],
            'assigneeId' => ['nullable', 'integer'],
            'estimate' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'dueDate' => ['nullable', 'date'],
            'selectedLabelIds' => ['array'],
            'selectedLabelIds.*' => ['integer'],
        ], attributes: ['descriptionMd' => 'description']);

        $tab = $this->selectedTab();

        // A column is honoured only when it is in the chosen tab; otherwise
        // CreateTicket puts the ticket in that tab's first column.
        $columnId = $validated['columnId'] !== null && $validated['columnId'] !== ''
            && $tab instanceof BoardTab
            && BoardColumn::query()->where('board_tab_id', $tab->getKey())->whereKey((int) $validated['columnId'])->exists()
                ? $validated['columnId']
                : null;

        $ticket = $createTicket->handle($this->board, [
            'title' => $validated['title'],
            'description_md' => $validated['descriptionMd'],
            'type' => $validated['type'],
            'priority' => $validated['priority'],
            'board_tab_id' => $tab?->getKey(),
            'board_column_id' => $columnId,
            'assignee_id' => $validated['assigneeId'] ?: null,
            'estimate' => $validated['estimate'] ?: null,
            'due_date' => $validated['dueDate'] ?: null,
            'customer_visible' => $this->customerVisible,
            'label_ids' => $this->selectedLabelIds,
        ], auth()->user());

        /*
         * The files that were dropped in while this form was open now have the
         * ticket they were always meant for.
         *
         * After creation rather than inside it: CreateTicket is called from the
         * board, from this form and from a confirmed AI proposal, and only this
         * one has drafts to hand over. Giving the action an attachment argument
         * would make every caller think about files, and make a failed adoption
         * able to roll back a filed ticket.
         *
         * Any inline image already points at route('attachments.show') for
         * these rows, so the description needs no rewriting — adoption changes
         * who may read the file, not where it lives.
         */
        $this->adoptDraftUploads($ticket, $adopt);

        session()->flash('status', $ticket->key().' created.');

        return $this->redirect(
            route('tickets.show', ['board' => $this->board, 'number' => $ticket->number]),
            navigate: true
        );
    }

    public function render(Markdown $markdown, RichText $rich)
    {
        $user = auth()->user();
        $tabs = $this->board->tabs()->get();
        $selectedTab = $this->selectedTab();
        $columns = $selectedTab?->columns()->get() ?? collect();

        return view('livewire.tickets.create', [
            'previewHtml' => $markdown->toHtml($this->descriptionMd),
            'editorHtml' => $this->editorHtml($rich),
            'tabs' => $tabs,
            'selectedTab' => $selectedTab,
            'showTabs' => $tabs->count() > 1,
            'columns' => $columns,
            'boardLabels' => $this->board->labels()->ordered()->get(),
            'assignableMembers' => $this->board->assignableMembers()->orderBy('name')->get(),
            'priorityOptions' => TicketPriority::ordered(),
            'typeOptions' => TicketType::ordered(),
            // Everything below the fold is staff-only. The action enforces the
            // same split, so hiding it here is presentation, not security.
            'isStaff' => $user->isStaff(),
            // Who a ticked box shows the ticket to, said beside the box.
            'customerCount' => $user->isStaff() ? $this->board->activeCustomerCount() : 0,
            // "Backend API › To Do" on a board split into tabs.
            'firstColumnName' => $selectedTab instanceof BoardTab && $columns->isNotEmpty()
                ? ($tabs->count() > 1 ? $selectedTab->name.' › ' : '').$columns->first()->name
                : null,
            'cancelUrl' => route('boards.show', array_filter(['board' => $this->board, 'tab' => $this->tab])),
            // Files already dropped in. Images embedded in the description show
            // there too; this list is what makes a PDF or a log file visible,
            // and what gives any of them a way back out before the ticket exists.
            'draftAttachments' => $this->draftAttachments(),
            'canAttachInEditor' => $this->canAttachInEditor(),
        ])->title('New ticket');
    }
}
