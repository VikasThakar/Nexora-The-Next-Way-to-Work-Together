<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | GitHub integration
    |--------------------------------------------------------------------------
    |
    | One home for everything GitHub, used by two features that look unrelated
    | but share a credential:
    |
    |   outbound   apply mode pushes a branch and opens a draft pull request
    |              (App\Services\GitHub\PullRequestClient).
    |   inbound    GitHub tells us about branches, commits and pull requests
    |              that mention a ticket key
    |              (App\Http\Controllers\GitHubWebhookController).
    |
    | Both are off until credentials exist, and both fail loudly rather than
    | pretending: with no token the pull-request client reports itself
    | unconfigured, and with no webhook secret the endpoint rejects every
    | delivery rather than trusting unsigned input.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | A fine-grained personal access token or GitHub App installation token.
    | It needs exactly two permissions on the repositories in scope:
    |
    |   contents: write        push a branch
    |   pull_requests: write   open a pull request
    |
    | It is deliberately never given merge rights. PullRequestClient has no
    | merge method at all, so apply mode could not merge even if the token
    | allowed it — but a token that cannot is one less thing to reason about.
    |
    | Read here and by nothing else. It never reaches Blade, Livewire,
    | JavaScript or a log line: every error message that could carry it is
    | rebuilt from the HTTP status instead of forwarded.
    |
    */

    'token' => env('GITHUB_TOKEN'),

    'api_url' => rtrim((string) env('GITHUB_API_URL', 'https://api.github.com'), '/'),

    'request_timeout' => (int) env('GITHUB_REQUEST_TIMEOUT', 20),

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | The shared secret configured on the GitHub webhook. Every delivery is
    | signed with it as `X-Hub-Signature-256`, and the endpoint verifies that
    | signature against the raw request body before the payload is parsed.
    |
    | With no secret set the endpoint refuses every delivery. That is the only
    | safe default: a webhook endpoint that accepts unsigned input is an
    | unauthenticated write endpoint into ticket history, and "we will add the
    | secret later" is exactly how one ships.
    |
    */

    'webhook' => [

        'secret' => env('GITHUB_WEBHOOK_SECRET'),

        // Events the processor understands. Anything else is acknowledged with
        // a 202 and ignored — GitHub retries on a non-2xx, and retrying a
        // delivery we will never act on helps nobody.
        'events' => [
            'push',
            'create',
            'pull_request',
            'check_suite',
            'status',
        ],

        /*
         * How long a delivery id is remembered for de-duplication.
         *
         * GitHub redelivers on timeout and on manual replay, and a redelivery
         * carries the same `X-GitHub-Delivery`. Rows older than this are
         * pruned by the workspace:prune command; the unique index is what
         * actually prevents double processing.
         */
        'retention_days' => (int) env('GITHUB_WEBHOOK_RETENTION_DAYS', 30),

        /*
         * Requests per minute accepted from the webhook endpoint.
         *
         * Generous, because a busy monorepo genuinely delivers in bursts, but
         * bounded so an unsigned flood cannot fill the queue. Rejected requests
         * never reach signature verification and never touch the database.
         */
        'rate_limit' => (int) env('GITHUB_WEBHOOK_RATE_LIMIT', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Webhook processing is queued, always. GitHub expects a webhook to answer
    | within ten seconds and disables an endpoint that repeatedly does not; the
    | controller therefore verifies, records and dispatches, and does no
    | parsing, no ticket lookup and no writing of its own.
    |
    | The `default` queue rather than a dedicated one: this work is measured in
    | milliseconds, unlike an AI run, and giving it its own queue would mean
    | another worker flag to forget.
    |
    */

    'queue' => [
        'connection' => env('GITHUB_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
        'name' => env('GITHUB_QUEUE', 'default'),
        'tries' => (int) env('GITHUB_JOB_TRIES', 3),
        'timeout' => (int) env('GITHUB_JOB_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Linking
    |--------------------------------------------------------------------------
    |
    | How many ticket references one push or pull request may create links for.
    | A branch merge can carry hundreds of commits, each mentioning a key; the
    | cap stops one delivery writing an unbounded number of rows.
    |
    */

    'max_references_per_delivery' => (int) env('GITHUB_MAX_REFERENCES', 50),

    // Commits linked per push. A force-push of a long branch is still one
    // delivery, and the last few commits are the interesting ones.
    'max_commits_per_push' => (int) env('GITHUB_MAX_COMMITS', 20),

];
