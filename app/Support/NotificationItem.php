<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * One row of the notification bell, ready to render.
 *
 * Every field here was built from a live record that the viewer was re-checked
 * against a moment ago (App\Services\NotificationReader). Nothing in it came
 * out of the stored notification payload except the identifiers used to look
 * those records up.
 */
class NotificationItem
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $message,
        public readonly string $ticketKey,
        public readonly string $url,
        public readonly ?string $actorName,
        public readonly Carbon $createdAt,
        public readonly bool $unread,
        public readonly bool $internal,
    ) {}
}
