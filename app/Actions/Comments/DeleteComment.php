<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Models\Comment;
use App\Models\User;
use App\Services\BoardBroadcaster;

/**
 * Remove a comment from the conversation.
 *
 * Soft delete, for two reasons. Notifications reference comments by id and must
 * still resolve — App\Services\NotificationReader drops any notification whose
 * subject it cannot read, and a hard delete would turn that into a silent hole
 * rather than a clean disappearance. And a deleted internal note is exactly the
 * kind of thing that gets asked about later.
 *
 * A soft-deleted comment vanishes everywhere a live one is read, because the
 * default Eloquent scope excludes it from Comment::visibleTo(). Its attachments
 * become unreachable at the same moment, without any extra work: the polymorphic
 * owner no longer resolves, and AttachmentPolicy denies anything whose owner it
 * cannot find.
 */
class DeleteComment
{
    public function __construct(private readonly BoardBroadcaster $broadcaster) {}

    public function handle(Comment $comment, User $actor): void
    {
        $comment->deleted_by_id = $actor->getKey();
        $comment->save();

        $comment->delete();

        $this->broadcaster->commentPosted($comment);
    }
}
