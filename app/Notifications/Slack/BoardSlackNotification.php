<?php

declare(strict_types=1);

namespace App\Notifications\Slack;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Base for the four Slack announcements.
 *
 * Two decisions are shared by all of them and both are load-bearing.
 *
 * Queued, always. Posting to Slack is an outbound HTTP call to a third party,
 * and the workflows that trigger it — creating a ticket, posting a comment,
 * dragging a card — are ones a person is waiting on. An outage at Slack must
 * cost the workspace nothing, and because delivery happens in a worker minutes
 * later, it costs nothing.
 *
 * Constructed from scalars, never from models. Laravel's SerializesModels would
 * store a class name and an id and re-fetch on the worker, which fails outright
 * if the ticket was deleted in between — a notification about a deleted ticket
 * should be a slightly stale message, not a failed job that retries three times
 * and then alerts somebody. Passing the handful of strings the message actually
 * renders also means the payload cannot grow into a copy of the record, and the
 * worker performs no queries at all.
 */
abstract class BoardSlackNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onConnection(config('slack.queue.connection'));
        $this->onQueue(config('slack.queue.name'));
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['slack_webhook'];
    }

    public function tries(): int
    {
        return max(1, (int) config('slack.tries', 3));
    }

    /**
     * Widening gaps rather than a fixed delay: Slack rate-limits incoming
     * webhooks per channel, and a burst that got throttled will still be
     * throttled ten seconds later.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        $backoff = (array) config('slack.backoff', [10, 60, 180]);

        return array_map(static fn ($seconds): int => max(1, (int) $seconds), $backoff);
    }

    abstract public function toSlackWebhook(object $notifiable): SlackMessage;
}
