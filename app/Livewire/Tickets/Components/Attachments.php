<?php

declare(strict_types=1);

namespace App\Livewire\Tickets\Components;

use App\Enums\TicketEventType;
use App\Livewire\Attachments\Panel;
use App\Models\Attachment;
use App\Models\Board;
use App\Models\Ticket;
use App\Services\TicketActivity;
use Illuminate\Database\Eloquent\Model;

/**
 * Files on a ticket.
 *
 * Everything except "which record owns these files" lives in the shared panel;
 * the one thing a ticket adds is that attaching and removing a file is written
 * to the ticket's history.
 */
class Attachments extends Panel
{
    public Ticket $ticket;

    public function mount(Ticket $ticket): void
    {
        $this->authorize('view', $ticket);

        $this->ticket = $ticket;
    }

    protected function owner(): Model
    {
        return $this->ticket;
    }

    protected function board(): Board
    {
        $this->ticket->loadMissing('board');

        return $this->ticket->board;
    }

    protected function recordChange(string $action, Attachment|string $attachment): void
    {
        app(TicketActivity::class)->record($this->ticket, TicketEventType::AttachmentChanged, [
            'action' => $action,
            'filename' => $attachment instanceof Attachment ? $attachment->filename : $attachment,
        ], auth()->user());
    }
}
