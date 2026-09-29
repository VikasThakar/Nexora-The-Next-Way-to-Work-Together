<?php

declare(strict_types=1);

namespace App\Livewire\Tickets;

use App\Actions\Tickets\AttachFilesToTicket;
use App\Actions\Tickets\CreateTicket;
use App\Enums\TicketPriority;
use App\Enums\TicketType;
use App\Livewire\Concerns\EditsRichText;
use App\Models\Board;
use App\Models\Ticket;
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
    }

    public function togglePreview(): void
    {
        $this->previewing = ! $this->previewing;
    }

    /**
     * The board files are filed under once the ticket exists.
     */
    protected function uploadBoard(): Board
    {
        return $this->board;
    }

    /**
     * There is no ticket to attach to yet, so files dropped into the editor
     * are staged — see EditsRichText::$stagedUploads — and stored against the
     * ticket the moment save() has created it.
     */
    protected function stagesUploads(): bool
    {
        return true;
    }

    public function save(CreateTicket $createTicket, RichText $rich, AttachFilesToTicket $attach)
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
         * The files that were waiting for this ticket to exist.
         *
         * Stored now, against the new ticket, and the description rewritten
         * from the editor's preview URLs to the authorized attachment route.
         * Saved quietly, because the observer has just announced the
         * creation and this is part of the same act.
         */
        if ($this->stagedUploads !== []) {
            $ticket->description_md = $this->attachStaged($ticket, (string) $validated['descriptionMd'], $attach);

            $ticket->saveQuietly();
        }

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
            'firstColumnName' => $this->board->columns()->ordered()->value('name'),
            'canAttachInEditor' => $this->canAttachInEditor(),
            'stagedFiles' => $this->stagedFiles(),
        ])->title('New ticket');
    }
}
