<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Comment;
use App\Models\User;

/**
 * "You were mentioned."
 *
 * Only ever sent to somebody the mention actually resolved to, and mentions
 * only resolve against people allowed to read the stream the comment was
 * written in — so a customer cannot be mentioned into an internal note. See
 * App\Services\MentionParser::candidates().
 */
class MentionedInComment extends WorkspaceNotification
{
    public const TYPE = 'mentioned';

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
