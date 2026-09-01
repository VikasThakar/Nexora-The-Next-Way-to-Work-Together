<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | SMS alerts
    |--------------------------------------------------------------------------
    |
    | An SMS is the most intrusive notification this application can send: it
    | reaches a person who is not at a computer, possibly asleep, and it costs
    | money per message. So it has exactly one trigger — a critical ticket being
    | raised — and it is off until somebody deliberately turns it on.
    |
    */

    'enabled' => (bool) env('SMS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Provider
    |--------------------------------------------------------------------------
    |
    | Which implementation of App\Services\SMS\SmsProviderInterface to use:
    |
    |   unavailable   the default. Every send is refused with a message naming
    |                 exactly what to configure, and is recorded as failed. No
    |                 message is claimed to have been sent.
    |   log           writes the message to the application log and records it
    |                 with status `logged`, never `sent`. For local development.
    |                 It is deliberately a distinct status: a dashboard that
    |                 counted these as delivered would be lying about whether an
    |                 on-call engineer was actually reached.
    |   46elks        the real provider. Requires SMS_46ELKS_USERNAME,
    |                 SMS_46ELKS_PASSWORD and a sender in SMS_FROM.
    |
    | An unrecognised value resolves to `unavailable`, so a typo in a deployment
    | variable cannot silently disable alerting while appearing to work.
    |
    */

    'driver' => env('SMS_DRIVER', 'unavailable'),

    /*
    |--------------------------------------------------------------------------
    | Sender
    |--------------------------------------------------------------------------
    |
    | An alphanumeric sender ID (max 11 characters, no spaces) or an E.164
    | number. Alphanumeric senders cannot receive replies, which is right for an
    | alert — a reply to an alert should be a person opening the ticket, not a
    | text message nobody reads.
    |
    | Country rules vary: several networks reject alphanumeric senders outright.
    | Check with the provider for the countries the on-call numbers are in.
    |
    */

    'from' => env('SMS_FROM', 'Aqueduct'),

    /*
    |--------------------------------------------------------------------------
    | 46elks
    |--------------------------------------------------------------------------
    |
    | https://46elks.com/docs/send-sms — the API takes HTTP basic auth with the
    | API username and password from the 46elks dashboard, and a form-encoded
    | body of `from`, `to` and `message`.
    |
    | Both credentials are read here and nowhere else, and never appear in a log
    | line or an error message: ElksSmsProvider rebuilds its failure text from
    | the HTTP status rather than forwarding the response body, which on an auth
    | failure can echo the username back.
    |
    */

    '46elks' => [
        'username' => env('SMS_46ELKS_USERNAME'),
        'password' => env('SMS_46ELKS_PASSWORD'),
        'endpoint' => rtrim((string) env('SMS_46ELKS_ENDPOINT', 'https://api.46elks.com/a1/sms'), '/'),
        'timeout' => (int) env('SMS_TIMEOUT', 15),

        /*
         * Ask 46elks to tell us what happened to each message.
         *
         * Left unset by default because it requires the application to be
         * publicly reachable, and a delivery-report URL that 404s produces
         * retries at the provider rather than errors here. Set it to the
         * absolute URL of a receiving endpoint if delivery receipts are wanted;
         * this application does not currently expose one.
         */
        'delivery_report_url' => env('SMS_46ELKS_DLR_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recipients
    |--------------------------------------------------------------------------
    |
    | Workspace-wide fallback numbers, in E.164 form (+46701234567), comma
    | separated. A board can override these with its own on-call list on the
    | integrations screen; this list is used when a board has not.
    |
    | Numbers rather than users on purpose. On-call is a rota, not a role, and
    | the person carrying the phone this week is often not the person whose
    | account raised the board.
    |
    */

    'recipients' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SMS_ALERT_RECIPIENTS', ''))
    ))),

    // A hard ceiling per alert, whatever a board is configured with. An alert
    // that texts thirty people is a phone tree, not an alert, and it is also
    // thirty times the cost of a mistake.
    'max_recipients' => (int) env('SMS_MAX_RECIPIENTS', 5),

    /*
    |--------------------------------------------------------------------------
    | Duplicate suppression
    |--------------------------------------------------------------------------
    |
    | One alert per ticket per number, enforced by a unique index on
    | `sms_messages` rather than by a cache entry — the guarantee has to survive
    | a cache flush and a race between two workers, and only the database can
    | promise that.
    |
    | The window below is a second, cheaper guard for repeated *board-level*
    | noise: if a script files forty critical tickets in a minute, the on-call
    | phone should ring once, not forty times. Zero disables it.
    |
    */

    'board_cooldown_seconds' => (int) env('SMS_BOARD_COOLDOWN', 300),

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    */

    'queue' => [
        'connection' => env('SMS_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
        'name' => env('SMS_QUEUE', 'default'),
        'tries' => (int) env('SMS_TRIES', 3),
    ],

    // Widening gaps. A provider outage is usually minutes, and an alert that
    // arrives four minutes late is still an alert.
    'backoff' => [15, 60, 180],

    // Messages longer than this are truncated before sending. An SMS is billed
    // per 160-character segment, and a ticket title pasted whole can be several.
    'max_length' => (int) env('SMS_MAX_LENGTH', 300),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How long the delivery records are kept, in days, before `workspace:prune`
    | removes them.
    |
    | Each row holds a phone number, which is personal data, so the table has a
    | retention window rather than growing for ever. Ninety days keeps the
    | evidence useful for the question it exists to answer — "was the on-call
    | rota reached about that incident?" — without keeping it indefinitely.
    |
    | Note the interaction with duplicate suppression: the unique index only
    | prevents a repeat alert while the original row still exists. That is
    | correct at any sane window, since an alert about a ticket raised three
    | months ago is not a duplicate of anything.
    |
    */

    'retention_days' => (int) env('SMS_RETENTION_DAYS', 90),

];
