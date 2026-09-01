<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Notifications\Slack\SlackMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Delivers a notification to a Slack incoming webhook.
 *
 * A custom channel rather than laravel/slack-notification-channel, because that
 * package speaks the Slack Web API with a bot token — a different integration
 * with different setup, different scopes and a workspace-wide app to install.
 * Incoming webhooks are one URL per channel and nothing else, which is the
 * right shape for "each board posts to its own room".
 *
 * Failure handling, which is the interesting part:
 *
 *   Nothing here is allowed to break the workflow that triggered it. That is
 *   already guaranteed structurally — the notification is queued, so a Slack
 *   outage happens minutes later in a worker, not inside the request that
 *   posted a comment. This class therefore does *not* swallow errors: it
 *   throws, so the queue retries with the configured backoff.
 *
 *   A 4xx is different from a 5xx and is treated differently. A revoked or
 *   mistyped webhook URL returns 404 or 403 for ever, so retrying it three
 *   times only delays the log line that tells somebody to fix it. Those are
 *   logged and dropped; 5xx and timeouts are retried.
 *
 *   The URL is a credential (see BoardSlackSettings) and never appears in a log
 *   line. What is logged is the board, the status and a fixed reason.
 */
class SlackWebhookChannel
{
    /**
     * @param  AnonymousNotifiable|object  $notifiable
     */
    public function send($notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSlackWebhook')) {
            return;
        }

        $url = $notifiable->routeNotificationFor('slack_webhook', $notification);

        if (! is_string($url) || $url === '') {
            return;
        }

        $message = $notification->toSlackWebhook($notifiable);

        if (! $message instanceof SlackMessage) {
            return;
        }

        try {
            $response = Http::timeout((int) config('slack.timeout', 8))
                ->asJson()
                ->post($url, $message->toArray());
        } catch (ConnectionException $exception) {
            // Network-level: worth retrying.
            throw new RuntimeException('Slack could not be reached.', previous: $exception);
        }

        if ($response->successful()) {
            return;
        }

        $status = $response->status();

        // Permanent. Retrying a revoked webhook is just three log lines instead
        // of one.
        if ($status >= 400 && $status < 500 && $status !== 429) {
            Log::warning('Slack rejected a notification; the webhook URL is probably no longer valid.', [
                'status' => $status,
                'notification' => $notification::class,
            ]);

            return;
        }

        // 429 and 5xx: transient, so let the queue back off and try again.
        throw new RuntimeException('Slack returned HTTP '.$status.'.');
    }
}
