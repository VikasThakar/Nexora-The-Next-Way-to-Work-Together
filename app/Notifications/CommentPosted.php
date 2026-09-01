<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Comment;
use App\Models\User;

/**
 * "Somebody replied on a ticket you are involved in."
 *
 * The stream the comment was written in is not stored: it is read back from the
 * live comment, which the recipient must still be allowed to see.
 */
class CommentPosted extends WorkspaceNotification
{
    public const TYPE = 'comment_posted';

    public function __construct(
        private readonly Comment $comment,
        private readonly ?User $actor = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => self::TYPE,
            'board_id' => (int) $this->comment->board_id,
            'ticket_id' => (int) $this->comment->ticket_id,
            'comment_id' => (int) $this->comment->getKey(),
            'actor_id' => $this->actor?->getKey(),
        ];
    }
}
