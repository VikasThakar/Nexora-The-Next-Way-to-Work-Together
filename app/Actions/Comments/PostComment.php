<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Enums\CommentStream;
use App\Models\Comment;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AttachmentStorage;
use App\Services\BoardBroadcaster;
use App\Services\MentionParser;
use App\Services\NotificationDispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Post a comment into one of a ticket's two streams.
 *
 * This is where the stream rule lives — in the action rather than the form, so
 * it holds no matter which screen, console command or future API calls it:
 *
 *   - a comment written by a customer is always in the customer stream. A
 *     customer cannot create an internal note, and cannot create something they
 *     then cannot see, whatever the request said.
 *   - staff choose their stream explicitly. The default, when nothing is said,
 *     is internal — CommentStream::default() — so a code path that forgets to
 *     choose keeps quiet rather than publishing to a customer.
 *
 * Everything that follows a post — who gets notified, what is broadcast — is
 * derived from the stream that was actually stored, never from what the caller
 * asked for.
 */
class PostComment
{
    public function __construct(
        private readonly MentionParser $mentions,
        private readonly NotificationDispatcher $notifications,
        private readonly BoardBroadcaster $broadcaster,
        private readonly AttachmentStorage $storage,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @param  array<int, UploadedFile>  $files
     */
    public function handle(
        Ticket $ticket,
        string $body,
        CommentStream $stream,
        User $author,
        array $files = [],
    ): Comment {
        $ticket->loadMissing('board');

        // The customer boundary, applied before anything else looks at $stream.
        $stream = $author->isStaff() ? $stream : CommentStream::Customer;

        $comment = DB::transaction(function () use ($ticket, $body, $stream, $author, $files): Comment {
            $comment = new Comment(['body_md' => trim($body)]);

            // Identity and audience are assigned here, never mass assigned.
            $comment->ticket_id = $ticket->getKey();
            $comment->board_id = $ticket->board_id;
            $comment->stream = $stream;
            $comment->author_id = $author->getKey();

            $comment->save();

            foreach ($files as $file) {
                $this->storage->store($file, $comment, $ticket->board, $author);
            }

            /*
             * The workspace feed, inside the transaction with the comment.
             *
             * The body is never recorded — see ActivityLogger::commentPosted().
             * The stream that was actually stored is, because "replied to the
             * customer" and "added an internal note" are different events to
             * anybody reading a delivery feed, and because reading it from
             * $comment rather than from $stream means the customer rule above
             * has already been applied.
             */
            $this->activity->commentPosted($comment, $ticket, $author);

            return $comment;
        });

        // Mentions resolve only against people allowed to read this stream, so
        // a customer named in an internal note is not a candidate and cannot be
        // notified. See MentionParser::candidates().
        $mentioned = $this->mentions->resolve(
            $comment->body_md,
            $this->mentions->candidates($ticket->board, $stream)
        );

        $this->notifications->commentPosted($comment, $ticket, $mentioned, $author);

        $this->broadcaster->commentPosted($comment);

        return $comment;
    }
}
