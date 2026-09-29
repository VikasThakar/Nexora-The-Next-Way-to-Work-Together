<?php

declare(strict_types=1);

namespace App\Actions\Tickets;

use App\Enums\TicketEventType;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AttachmentStorage;
use App\Services\TicketActivity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Put files on a ticket at the moment it is created.
 *
 * Three surfaces create tickets with files already in hand — the full form,
 * the board's quick add, and the assistant acting on a conversation — and
 * each of them arrives at the same two questions: how does a file become an
 * attachment on a ticket that did not exist a moment ago, and how does a
 * screenshot end up *visible* in the ticket rather than listed by name at
 * the bottom of it. This action is the one answer, so the three cannot
 * drift apart.
 *
 * The visibility half is Markdown. A ticket's description is the thing
 * people read, so an image is written into it as `![name](url)` — rendered
 * inline by App\Support\Markdown — and anything else as a link. The URL is
 * always route('attachments.show'), which re-authorizes against the ticket
 * on every fetch; see App\Http\Controllers\AttachmentController.
 */
class AttachFilesToTicket
{
    public function __construct(
        private readonly AttachmentStorage $storage,
        private readonly TicketActivity $activity,
    ) {}

    /**
     * Store uploaded files against the ticket.
     *
     * @param  iterable<int, UploadedFile>  $files
     * @return Collection<int, Attachment>
     */
    public function uploads(Ticket $ticket, iterable $files, User $actor): Collection
    {
        $ticket->loadMissing('board');

        $stored = new Collection;

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $attachment = $this->storage->store($file, $ticket, $ticket->board, $actor);

            $this->record($ticket, $attachment, $actor);

            $stored->push($attachment);
        }

        return $stored;
    }

    /**
     * Copy attachments that belong to something else — an assistant
     * conversation — onto the ticket.
     *
     * @param  iterable<int, Attachment>  $sources
     * @return Collection<int, Attachment>
     */
    public function copies(Ticket $ticket, iterable $sources, User $actor): Collection
    {
        $ticket->loadMissing('board');

        $stored = new Collection;

        foreach ($sources as $source) {
            if (! $source instanceof Attachment) {
                continue;
            }

            $attachment = $this->storage->copy($source, $ticket, $ticket->board, $actor);

            $this->record($ticket, $attachment, $actor);

            $stored->push($attachment);
        }

        return $stored;
    }

    /**
     * The Markdown that shows these files inline.
     *
     * Images and videos as embeds, so they render where a reader looks,
     * anything else as a link. Only files not already referenced in the text
     * are included, so a screenshot the editor placed mid-paragraph is not
     * repeated at the end.
     *
     * @param  iterable<int, Attachment>  $attachments
     */
    public function inlineMarkdown(iterable $attachments, string $existing = ''): string
    {
        $lines = [];

        foreach ($attachments as $attachment) {
            if (! $attachment instanceof Attachment) {
                continue;
            }

            $url = route('attachments.show', $attachment);

            if ($existing !== '' && str_contains($existing, $url)) {
                continue;
            }

            $name = $this->markdownLabel($attachment->filename);

            $lines[] = $attachment->isImage()
                ? '!['.$name.']('.$url.')'
                : '['.$name.']('.$url.')';
        }

        return implode("\n\n", $lines);
    }

    /**
     * Append the inline references to the ticket's description.
     *
     * Saved quietly: the ticket was created a moment ago and the observer has
     * already announced it, so a second "updated" round of broadcasts and
     * notifications for the same act of creation would be noise.
     *
     * @param  iterable<int, Attachment>  $attachments
     */
    public function embed(Ticket $ticket, iterable $attachments): void
    {
        $current = (string) $ticket->description_md;
        $markdown = $this->inlineMarkdown($attachments, $current);

        if ($markdown === '') {
            return;
        }

        $ticket->description_md = trim($current) === ''
            ? $markdown
            : rtrim($current)."\n\n".$markdown;

        $ticket->saveQuietly();
    }

    private function record(Ticket $ticket, Attachment $attachment, User $actor): void
    {
        $this->activity->record($ticket, TicketEventType::AttachmentChanged, [
            'action' => 'added',
            'filename' => $attachment->filename,
        ], $actor);
    }

    /**
     * A filename that is safe inside a Markdown link label.
     */
    private function markdownLabel(string $filename): string
    {
        $label = str_replace(['[', ']', '\\'], ['(', ')', ''], $filename);

        return trim($label) === '' ? 'attachment' : $label;
    }
}
