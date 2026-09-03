<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Models\Comment;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\BoardBroadcaster;

/**
 * Edit the body of a comment.
 *
 * Only the body. A comment can never change stream: moving an internal note
 * into the customer conversation would publish words that were written in
 * private, on the assumption that nobody outside the team would read them.
 * If the team wants to say something to the customer, they write it to the
 * customer.
 *
 * `edited_at` is set on the first real change and drives the "edited"
 * indicator, so a reader can tell that what they are looking at is not what was
 * originally posted.
 */
class UpdateComment
{
    public function __construct(
        private readonly BoardBroadcaster $broadcaster,
        private readonly ActivityLogger $activity,
    ) {}

    public function handle(Comment $comment, string $body, User $actor): Comment
    {
        $body = trim($body);

        if ($body === '' || $body === $comment->body_md) {
            return $comment;
        }

        $comment->body_md = $body;
        $comment->edited_at = now();

        $comment->save();

        // After the early return above, so a save that changed nothing — or one
        // that was rejected for being empty — does not appear in the feed as an
        // edit that never happened.
        $this->activity->commentEdited($comment, null, $actor);

        $this->broadcaster->commentPosted($comment);

        return $comment;
    }
}
