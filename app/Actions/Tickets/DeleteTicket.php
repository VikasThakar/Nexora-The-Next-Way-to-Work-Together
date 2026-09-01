<?php

declare(strict_types=1);

namespace App\Actions\Tickets;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Permanently delete a ticket.
 *
 * Subtasks, labels, links, history and comments all cascade from the tickets
 * table. Attachments do not: the relationship is polymorphic, so there is no
 * foreign key for the database to follow, and their rows have to be removed
 * here — both the ticket's own files and any attached to its comments, whose
 * owners are about to disappear.
 *
 * The stored objects go only after the transaction commits, so a rollback
 * cannot leave a ticket pointing at files that no longer exist.
 *
 * The board's ticket counter is deliberately not rewound: AQD-7 stays retired,
 * so a future ticket cannot inherit its identity in old links and messages.
 */
class DeleteTicket
{
    public function handle(Ticket $ticket): void
    {
        // Comments are soft-deleted, so withTrashed() is needed to find the
        // files belonging to notes that were already removed from the thread.
        $commentIds = Comment::query()
            ->withTrashed()
            ->where('ticket_id', $ticket->getKey())
            ->pluck('id')
            ->all();

        $commentAttachments = Attachment::query()
            ->where('attachable_type', (new Comment)->getMorphClass())
            ->whereIn('attachable_id', $commentIds === [] ? [0] : $commentIds);

        $files = $ticket->attachments()
            ->get(['disk', 'path'])
            ->concat((clone $commentAttachments)->get(['disk', 'path']))
            ->map(fn ($attachment): array => ['disk' => $attachment->disk, 'path' => $attachment->path])
            ->all();

        DB::transaction(function () use ($ticket, $commentAttachments): void {
            $ticket->attachments()->delete();
            $commentAttachments->delete();

            $ticket->delete();
        });

        foreach ($files as $file) {
            Storage::disk($file['disk'])->delete($file['path']);
        }
    }
}
