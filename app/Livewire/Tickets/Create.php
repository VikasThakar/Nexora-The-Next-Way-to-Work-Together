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
use App\Models\Ticket;
use App\Services\AttachmentStorage;
use App\Support\Markdown;
use App\Support\RichText\RichText;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Full ticket creation form.
 *
 * Customers get a deliberately smaller form: a title, a description, a
 * priority and a due date. Column, assignee, estimate, labels and visibility
 * are not offered, and — more importantly — CreateTicket ignores them for a
 * customer even if they are submitted anyway. The form shape is convenience;
 * the action is the rule.
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
        $this->columnId = (string) ($board->columns()->ordered()->value('id') ?? '');

        // Ticked unless the board says otherwise: work on a board is usually
        // the customer's work. Unticking it is how a ticket stays internal.
        $this->customerVisible = $board->newTicketsVisibleToCustomers();
    }

    public function togglePreview(): void
    {
        $this->previewing = ! $this->previewing;
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

        $ticket = $createTicket->handle($this->board, [
            'title' => $validated['title'],
            'description_md' => $validated['descriptionMd'],
            'type' => $validated['type'],
            'priority' => $validated['priority'],
            'board_column_id' => $validated['columnId'] ?: null,
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

        return view('livewire.tickets.create', [
            'previewHtml' => $markdown->toHtml($this->descriptionMd),
            'editorHtml' => $this->editorHtml($rich),
            'columns' => $this->board->columns()->ordered()->get(),
            'boardLabels' => $this->board->labels()->ordered()->get(),
            'assignableMembers' => $this->board->assignableMembers()->orderBy('name')->get(),
            'priorityOptions' => TicketPriority::ordered(),
            'typeOptions' => TicketType::ordered(),
            // Everything below the fold is staff-only. The action enforces the
            // same split, so hiding it here is presentation, not security.
            'isStaff' => $user->isStaff(),
            // Who a ticked box shows the ticket to, said beside the box.
            'customerCount' => $user->isStaff() ? $this->board->activeCustomerCount() : 0,
            'firstColumnName' => $this->board->columns()->ordered()->value('name'),
            // Files already dropped in. Images embedded in the description show
            // there too; this list is what makes a PDF or a log file visible,
            // and what gives any of them a way back out before the ticket exists.
            'draftAttachments' => $this->draftAttachments(),
            'canAttachInEditor' => $this->canAttachInEditor(),
        ])->title('New ticket');
    }
}
