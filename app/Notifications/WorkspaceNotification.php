<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Base class for every in-app notification.
 *
 * One rule, and it is the reason this base class exists at all:
 *
 *   A stored notification contains identifiers and nothing else. No ticket
 *   title, no comment body, no board name, no excerpt.
 *
 * Recipients are filtered by policy before a notification is written
 * (App\Services\NotificationDispatcher), so the row only exists for somebody
 * who was allowed to receive it. But authorization is not frozen at that
 * moment: a ticket can be flipped back to internal, a customer can be removed
 * from a board, a comment can be deleted. A payload holding "Rotate the
 * production credentials" would keep showing that line in the bell long after
 * the ticket stopped being readable.
 *
 * Because the payload is identifiers only, App\Services\NotificationReader has
 * to re-read the subject through the ordinary visibility scope to render
 * anything at all — and drops the notification when it cannot. Staleness fails
 * closed instead of leaking.
 *
 * Deliberately not queued: writing a row is cheap, and a notification that
 * silently stops arriving because a worker died is worse than a few
 * milliseconds in the request.
 */
abstract class WorkspaceNotification extends Notification
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Identifiers only. See the class comment.
     *
     * @return array<string, mixed>
     */
    abstract public function toDatabase(object $notifiable): array;
}
