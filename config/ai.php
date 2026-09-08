<?php

declare(strict_types=1);

use App\Enums\AiRunMode;

return [

    /*
    |--------------------------------------------------------------------------
    | Provider credentials
    |--------------------------------------------------------------------------
    |
    | The API key is read from the environment and nowhere else. It is never
    | passed to a Blade view, a Livewire property, the browser or a log line:
    | App\Services\AI\ClaudeService is the only class that touches the value,
    | and it hands it straight to the SDK client.
    |
    | `enabled` is the master switch. With no key configured the application
    | still boots and every other feature works; AI runs are refused with a
    | recorded reason rather than failing halfway through.
    |
    */

    'enabled' => (bool) env('AI_ENABLED', true),

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),

        // Override only for a proxy or a compatible gateway.
        'base_url' => env('ANTHROPIC_BASE_URL'),

        // Seconds. The SDK delegates the timeout to the transport, so this is
        // applied to the HTTP client ClaudeService builds.
        'timeout' => (float) env('AI_REQUEST_TIMEOUT', 300),

        'max_retries' => (int) env('AI_MAX_RETRIES', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | `default` is used when a board has not chosen one. `allowed` is the list
    | offered in the board settings screen and validated on save, so a crafted
    | request cannot point a board at an arbitrary string.
    |
    */

    'model' => [
        'default' => env('AI_MODEL', 'claude-opus-5'),

        'allowed' => [
            'claude-opus-5' => 'Claude Opus 5',
            'claude-sonnet-5' => 'Claude Sonnet 5',
            'claude-haiku-4-5' => 'Claude Haiku 4.5',
        ],

        'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 16000),

        // low | medium | high | xhigh | max. Sent as output_config.effort.
        'effort' => env('AI_EFFORT', 'high'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pricing
    |--------------------------------------------------------------------------
    |
    | US dollars per million tokens, per model. This is the ONLY place prices
    | live: App\Services\AI\CostCalculationService reads this table and nothing
    | else in the application knows a rate.
    |
    | A model that is not listed produces a null cost rather than a guess. An
    | invented number in a cost report is worse than an honest blank.
    |
    */

    'pricing' => [
        'currency' => 'USD',

        'per_million_tokens' => [
            'claude-fable-5' => ['input' => 10.00, 'output' => 50.00],
            'claude-opus-5' => ['input' => 5.00, 'output' => 25.00],
            'claude-opus-4-8' => ['input' => 5.00, 'output' => 25.00],
            'claude-opus-4-7' => ['input' => 5.00, 'output' => 25.00],
            'claude-opus-4-6' => ['input' => 5.00, 'output' => 25.00],
            'claude-sonnet-5' => ['input' => 2.00, 'output' => 10.00],
            'claude-sonnet-4-6' => ['input' => 3.00, 'output' => 15.00],
            'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | AI runs take minutes, so they get their own queue: a worker chewing
    | through a repository clone must never delay a broadcast or a notification.
    | Run a worker with `--queue=ai` (see the deployment notes in README.md).
    |
    */

    'queue' => [
        'connection' => env('AI_QUEUE_CONNECTION'),
        'name' => env('AI_QUEUE', 'ai'),
        'tries' => (int) env('AI_JOB_TRIES', 3),

        // Seconds. Must exceed the request timeout above plus clone and test
        // time, or a long apply run is killed mid-flight and retried.
        'timeout' => (int) env('AI_JOB_TIMEOUT', 900),

        // Seconds between attempts.
        'backoff' => [30, 120],
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily run caps
    |--------------------------------------------------------------------------
    |
    | Automatic runs are the runaway-cost risk: one customer filing twenty
    | tickets in an afternoon triggers twenty runs without anybody deciding to.
    | The per-board cap is the real control; these are the fallback default and
    | the ceiling a board setting is clamped to.
    |
    */

    'caps' => [
        'daily_auto_runs' => (int) env('AI_DAILY_AUTO_RUN_CAP', 20),

        // Upper bound a board may configure for itself.
        'max_daily_auto_runs' => (int) env('AI_MAX_DAILY_AUTO_RUN_CAP', 500),

        // A manual run is a deliberate human act, so it is capped far more
        // loosely - but it is still capped.
        'daily_manual_runs' => (int) env('AI_DAILY_MANUAL_RUN_CAP', 100),

        // May an administrator exceed the manual cap? Never the automatic one:
        // nobody decided to start those.
        'admins_bypass_manual_cap' => (bool) env('AI_ADMINS_BYPASS_CAP', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Board defaults
    |--------------------------------------------------------------------------
    |
    | Fallbacks for the per-board AI settings stored under `boards.settings.ai`.
    | Read through App\Support\BoardAiSettings, never directly.
    |
    | auto_run_mode defaults to `off`. Nothing costs money, clones a repository
    | or writes a note until somebody switches it on for a specific board.
    |
    */

    'board_defaults' => [
        'auto_run_enabled' => false,
        'auto_run_mode' => AiRunMode::OFF,
        'model' => null,                 // null = config('ai.model.default')
        'custom_system_prompt' => null,
        'project_context' => null,
        'primary_repository' => null,    // null = the board_repositories row flagged primary
        'daily_auto_run_cap' => null,    // null = config('ai.caps.daily_auto_runs')
    ],

    /*
    |--------------------------------------------------------------------------
    | Isolated working directories
    |--------------------------------------------------------------------------
    |
    | Every run that touches a repository gets its own directory, named after
    | the run's UUID, and it is deleted when the run ends however it ends.
    |
    | Railway containers are ephemeral, so nothing here is state: run metadata,
    | results, PR URLs and errors live in `ai_runs`. This is scratch space.
    |
    */

    'workspace' => [
        // Relative to storage/app unless an absolute path is given.
        'path' => env('AI_WORKSPACE_PATH', 'ai-runs'),

        // Debugging aid for a self-hosted worker with a persistent disk. Leave
        // false on Railway: the container is replaced anyway.
        'keep_on_failure' => (bool) env('AI_KEEP_FAILED_WORKSPACES', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Repositories
    |--------------------------------------------------------------------------
    |
    | Cloning is opt-in. With it off, suggest mode still runs and reasons from
    | the configured repository metadata and the ticket alone; the run records
    | that the working tree was unavailable rather than pretending it read the
    | code.
    |
    */

    'repository' => [
        'clone_enabled' => (bool) env('AI_REPOSITORY_CLONE_ENABLED', false),

        'git_binary' => env('AI_GIT_BINARY', 'git'),

        // Seconds for any single git invocation.
        'git_timeout' => (int) env('AI_GIT_TIMEOUT', 300),

        // Shallow by default: history is rarely what the model needs, and a
        // deep clone of a large repository will not finish inside a job.
        'clone_depth' => (int) env('AI_CLONE_DEPTH', 1),

        // How much of the tree is summarised into the prompt.
        'max_context_files' => (int) env('AI_MAX_CONTEXT_FILES', 400),

        'max_context_bytes' => (int) env('AI_MAX_CONTEXT_BYTES', 60000),

        'branch_prefix' => env('AI_BRANCH_PREFIX', 'ai/'),

        'commit_author_name' => env('AI_COMMIT_AUTHOR_NAME', 'Workspace AI'),

        'commit_author_email' => env('AI_COMMIT_AUTHOR_EMAIL', 'ai@example.invalid'),

        // Branches apply mode must never commit to or push over, whatever a
        // board is configured to say.
        'protected_branches' => ['main', 'master', 'production', 'release', 'develop'],
    ],

    /*
    |--------------------------------------------------------------------------
    | GitHub
    |--------------------------------------------------------------------------
    |
    | Lives in config/github.php, not here.
    |
    | Apply mode pushes a branch and opens a pull request with the same token
    | the webhook integration is configured alongside, and one credential
    | defined in two config files is one credential that will eventually be
    | rotated in one of them. Read it as config('github.token').
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Apply mode code generation
    |--------------------------------------------------------------------------
    |
    | Writing code into a checkout needs an agentic coding runtime, which is a
    | separate executable rather than an HTTP call. `driver` selects it:
    |
    |   unavailable   the default. An apply run fails immediately with an
    |                 internal note naming exactly what is missing. Nothing is
    |                 faked and no empty pull request is opened.
    |   claude_code   runs the Claude Code CLI inside the isolated checkout.
    |                 Requires the binary on PATH (or AI_CLAUDE_CODE_BINARY)
    |                 and ANTHROPIC_API_KEY in the worker's environment.
    |
    */

    'code_generation' => [
        'driver' => env('AI_CODE_DRIVER', 'unavailable'),

        'claude_code' => [
            'binary' => env('AI_CLAUDE_CODE_BINARY', 'claude'),
            'timeout' => (int) env('AI_CODE_TIMEOUT', 1800),
            'extra_arguments' => array_values(array_filter(
                explode(' ', (string) env('AI_CLAUDE_CODE_ARGS', ''))
            )),
        ],

        // Run inside the checkout before committing, pipe-separated. Empty
        // means no validation; a non-zero exit fails the run rather than
        // opening a pull request nobody trusts.
        'validation_commands' => array_values(array_filter(array_map(
            'trim',
            explode('|', (string) env('AI_VALIDATION_COMMANDS', ''))
        ))),

        'validation_timeout' => (int) env('AI_VALIDATION_TIMEOUT', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Workspace AI chat
    |--------------------------------------------------------------------------
    */

    'chat' => [
        // How many stored turns are replayed as conversation history.
        'history_limit' => (int) env('AI_CHAT_HISTORY', 20),

        'max_output_tokens' => (int) env('AI_CHAT_MAX_OUTPUT_TOKENS', 4000),

        // How much board context is assembled per question. Every one of these
        // is read through the asking user's own visibility scope.
        'context' => [
            'tickets' => (int) env('AI_CHAT_CONTEXT_TICKETS', 60),
            'doc_pages' => (int) env('AI_CHAT_CONTEXT_PAGES', 40),
            'activity' => (int) env('AI_CHAT_CONTEXT_EVENTS', 40),

            /*
             * The "All workspace" context, which spans boards rather than
             * detailing one.
             *
             * Much smaller per board than the figures above, on purpose:
             * breadth multiplied by the depth of a single-board context would
             * spend the whole window on material the question probably does not
             * need. Titles only, no descriptions, no documentation, no history —
             * see App\Services\AI\WorkspaceContextBuilder.
             */
            'workspace' => [
                'boards' => (int) env('AI_CHAT_CONTEXT_BOARDS', 12),
                'tickets_per_board' => (int) env('AI_CHAT_CONTEXT_BOARD_TICKETS', 6),
            ],
        ],

        /*
         * How long a streaming answer may hold its HTTP request open, in
         * seconds.
         *
         * Streaming keeps one request alive for the length of the answer, so
         * the request needs a longer limit than php.ini's global
         * max_execution_time — which stays low deliberately, because it is what
         * bounds every *other* request. Applied with set_time_limit() only on
         * the streaming path.
         *
         * Must not be lower than ai.anthropic.timeout, or the request is killed
         * while the provider is still within its own allowance.
         */
        'stream_time_limit' => (int) env('AI_CHAT_STREAM_TIME_LIMIT', 360),
    ],

];
