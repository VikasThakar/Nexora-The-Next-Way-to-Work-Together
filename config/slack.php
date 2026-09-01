<?php

declare(strict_types=1);

use App\Enums\NotificationEvent;

return [

    /*
    |--------------------------------------------------------------------------
    | Slack notifications
    |--------------------------------------------------------------------------
    |
    | Configured per board rather than per workspace, because that is how the
    | product is used: each customer's board belongs to a different delivery
    | team with a different channel, and a single workspace-wide webhook would
    | put every customer's tickets in one room.
    |
    | The webhook URL therefore lives on the board (encrypted — see
    | App\Support\BoardSlackSettings), not here. What lives here is the
    | behaviour that must not differ per board: whether the integration is
    | available at all, how long to wait, and how hard to retry.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | Off disables delivery everywhere, whatever any board is configured to
    | say. Useful for a staging deployment restored from a production database
    | dump, which would otherwise start posting into the real team's channel
    | within minutes of booting.
    |
    */

    'enabled' => (bool) env('SLACK_NOTIFICATIONS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Board defaults
    |--------------------------------------------------------------------------
    |
    | What a board that has never opened the integrations screen does. Nothing:
    | a board with no webhook URL cannot post anyway, and defaulting the events
    | to "on" means the first team that pastes a URL immediately gets every
    | event rather than choosing.
    |
    | Muting is per event, and per board, and the whole board can be muted with
    | one switch — see BoardSlackSettings::wants().
    |
    */

    'board_defaults' => [
        'enabled' => false,
        'events' => [
            NotificationEvent::CustomerTicketRaised->value => true,
            NotificationEvent::CustomerCommentPosted->value => true,
            NotificationEvent::AiRunFinished->value => false,
            NotificationEvent::TicketMovedToDone->value => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | Queued, always. A Slack outage must never make posting a comment slow, and
    | an incoming webhook that hangs must never hold a database transaction
    | open. See App\Notifications\Channels\SlackWebhookChannel.
    |
    */

    'queue' => [
        'connection' => env('SLACK_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
        'name' => env('SLACK_QUEUE', 'default'),
    ],

    // Short: Slack answers an incoming webhook in well under a second, and a
    // long timeout only means a worker is blocked longer during an outage.
    'timeout' => (int) env('SLACK_TIMEOUT', 8),

    'tries' => (int) env('SLACK_TRIES', 3),

    // Seconds between attempts. Slack rate-limits incoming webhooks per
    // channel, so the gaps widen rather than hammering.
    'backoff' => [10, 60, 180],

];
