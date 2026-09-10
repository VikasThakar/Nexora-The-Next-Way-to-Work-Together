<?php

declare(strict_types=1);

use App\Enums\AiChatMode;
use App\Enums\AiProvider;
use App\Enums\AiRunMode;

/*
|--------------------------------------------------------------------------
| The model catalogue
|--------------------------------------------------------------------------
|
| Every model this deployment is willing to send a request to, with the facts
| the model picker shows. Built as a variable rather than written inline so the
| rest of this file can derive from it: `model.allowed` below is this list's
| labels, and nothing else in the application maintains a second copy.
|
| Each entry carries:
|
|   label           what a person sees.
|   provider        which AiProvider case serves it. A model is never offered
|                   for a provider that does not serve it — see
|                   App\Support\AiModelCatalogue::forProvider().
|   context_window  input tokens, or null for "not stated here". Null renders as
|                   nothing at all in the picker; a guess would render as fact.
|   vision          whether the model accepts images. An attached picture is
|                   only ever sent to a model whose entry says true; for any
|                   other model the attachment card says the image cannot be
|                   analysed rather than sending it and hoping. Absent is false.
|   category        a short deployment-authored descriptor — "Balanced", "Deep
|                   reasoning". It is editorial shorthand for the team choosing
|                   a model, NOT a claim about a benchmark.
|
| The Anthropic figures are the published ones for these model ids. The OpenAI
| block is deliberately thin: this deployment has no first-party source for
| those numbers, so it states none. Confirm the ids against the account that
| holds the key before switching a board to one — AI_OPENAI_MODELS overrides
| the list without a code change — and add pricing under `pricing` below if you
| want cost estimates for them. An unpriced model reports no cost rather than a
| plausible-looking one.
*/

$openAiModelIds = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('AI_OPENAI_MODELS', 'gpt-5.1,gpt-5-mini'))
)));

/*
 * Which of those ids accept an image.
 *
 * Opt-in rather than assumed. Most current OpenAI chat models do take images,
 * but "most" is not a capability: naming an id here that turns out not to
 * would fail the whole request rather than only the picture. Left empty, an
 * attached image is described to the model in words and the card says the
 * model cannot see it — which is true, and recoverable by switching model.
 */
$openAiVisionModelIds = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('AI_OPENAI_VISION_MODELS', ''))
)));

$models = [
    'claude-opus-5' => [
        'label' => 'Claude Opus 5',
        'provider' => AiProvider::ANTHROPIC,
        'context_window' => 1000000,
        'vision' => true,
        'category' => 'Deep reasoning',
    ],
    'claude-sonnet-5' => [
        'label' => 'Claude Sonnet 5',
        'provider' => AiProvider::ANTHROPIC,
        'context_window' => 1000000,
        'vision' => true,
        'category' => 'Balanced',
    ],
    'claude-haiku-4-5' => [
        'label' => 'Claude Haiku 4.5',
        'provider' => AiProvider::ANTHROPIC,
        'context_window' => 200000,
        'vision' => true,
        'category' => 'Fast and cheap',
    ],
];

foreach ($openAiModelIds as $openAiModelId) {
    $models[$openAiModelId] = [
        'label' => $openAiModelId,
        'provider' => 'openai',
        'context_window' => null,
        'vision' => in_array($openAiModelId, $openAiVisionModelIds, true),
        'category' => null,
    ];
}

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

    'openai' => [
        /*
         * Read from the environment, exactly as the Anthropic key is, and
         * outranked by a key stored through the global AI settings screen. See
         * App\Services\AI\AiCredentialVault for the full precedence order.
         */
        'api_key' => env('OPENAI_API_KEY'),

        // Override only for a proxy, an Azure deployment or a compatible
        // gateway. The path (/chat/completions) is appended by the adapter.
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),

        // Optional; sent as OpenAI-Organization / OpenAI-Project when set.
        'organization' => env('OPENAI_ORGANIZATION'),
        'project' => env('OPENAI_PROJECT'),

        'timeout' => (float) env('AI_REQUEST_TIMEOUT', 300),

        'max_retries' => (int) env('AI_MAX_RETRIES', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider selection
    |--------------------------------------------------------------------------
    |
    | Which vendor answers when nothing overrides it. This is the bottom of the
    | inheritance chain — the global settings row overrides it, and a board may
    | override that — so it is the answer for a workspace nobody has configured
    | rather than a hard-coded choice.
    |
    */

    'provider' => [
        'default' => env('AI_PROVIDER', AiProvider::ANTHROPIC),
    ],

    /*
    |--------------------------------------------------------------------------
    | Capability modes
    |--------------------------------------------------------------------------
    |
    | App\Enums\AiCapabilityMode: observer | operator | agent. The security dial
    | described in that enum.
    |
    | The shipped default is `agent`, and that is a compatibility decision worth
    | stating plainly: it is precisely the set of capabilities this product had
    | before modes existed, so enabling this feature removes nothing from a
    | workspace that was already running automatic or apply-mode runs. Tighten
    | it here, or on the global AI settings screen, or per board.
    |
    | A fresh deployment that wants the cautious posture should set
    | AI_DEFAULT_MODE=operator (proposals, confirmed by a person, no unattended
    | work) or =observer (answers only).
    |
    */

    'modes' => [
        'default' => env('AI_DEFAULT_MODE', 'agent'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Session and token limits
    |--------------------------------------------------------------------------
    |
    | Context management, and the cost control that comes with it. A long
    | conversation costs more per question than a short one, because the whole
    | transcript is re-sent every time; these are the ceilings that make
    | somebody start a fresh session instead of accumulating for ever.
    |
    | Both are defaults for the global settings row, which is what is actually
    | enforced (see App\Services\AI\AiSessionManager). Zero means unlimited, and
    | is written as zero rather than null so the settings form has one shape.
    |
    */

    'limits' => [
        // Total tokens one assistant session may spend before it must be
        // replaced with a new one.
        'session_tokens' => (int) env('AI_SESSION_TOKEN_LIMIT', 400000),

        // Total tokens one person may spend across all sessions in a day.
        'daily_user_tokens' => (int) env('AI_DAILY_USER_TOKEN_LIMIT', 2000000),

        // How many previous sessions the panel lists.
        'session_history' => (int) env('AI_SESSION_HISTORY', 25),
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

        /*
         * Every catalogue model, as id => label.
         *
         * Derived from the catalogue at the top of this file rather than
         * maintained separately, so a model cannot be offered by the picker and
         * refused by validation. Callers that need the provider or the context
         * window read `ai.models` through App\Support\AiModelCatalogue instead.
         */
        'allowed' => array_map(static fn (array $model): string => $model['label'], $models),

        'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 16000),

        // low | medium | high | xhigh | max. Sent as output_config.effort.
        'effort' => env('AI_EFFORT', 'high'),
    ],

    /*
    |--------------------------------------------------------------------------
    | The catalogue itself
    |--------------------------------------------------------------------------
    |
    | Read through App\Support\AiModelCatalogue, never directly, so that the
    | coercion — an unknown id, a model whose provider was switched off — has
    | one home. See the block at the top of this file for what each entry means.
    |
    */

    'models' => $models,

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
        'model' => null,                 // null = inherit; see the override note below
        'custom_system_prompt' => null,
        'project_context' => null,
        'primary_repository' => null,    // null = the board_repositories row flagged primary
        'daily_auto_run_cap' => null,    // null = config('ai.caps.daily_auto_runs')

        /*
         * Overrides, and the reason they are all null.
         *
         * Null means "inherit", and inheriting is the default for every one of
         * them: global AI settings are the workspace's answer, and a board only
         * departs from it deliberately. That is what lets the settings screen
         * show "Inherited from global" as a real state rather than as a guess
         * about whether a stored value happens to match.
         *
         * `provider_override` and `model` are a pair with one rule between
         * them: a model the effective provider does not serve is ignored
         * rather than sent. `model` is the board's model override and predates
         * this block, which is why it sits above rather than here — one stored
         * key, read as a raw override by the resolver and as a coerced
         * effective value by everything that only wants an answer. See
         * App\Support\AiConfiguration.
         *
         * `credentials` may hold this board's own encrypted provider keys, for
         * a project billed to its own account. It is the exception rather than
         * the norm — a board that only wants a different model inherits the
         * global key, and no secret is duplicated.
         */
        'provider_override' => null,
        'capability_mode' => null,
        'session_token_limit' => null,
        'credentials' => [],
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
        /*
         * What a conversation is for, and what it runs on.
         *
         * The three settings offered beside Send — see App\Enums\AiChatMode.
         * `default` is what a new conversation starts in, and it is Reading so
         * that the setting somebody gets by not choosing is the one that
         * changes nothing.
         *
         * Each mode names a model, because the two jobs have different shapes:
         * looking things up and summarising is bounded and mostly retrieval,
         * while drafting a ticket somebody will act on is the turn worth
         * spending on. The ids below must appear in the catalogue at the top of
         * this file, and are still resolved against the effective provider
         * before a request is sent — a workspace running on OpenAI gets that
         * provider's default rather than an Anthropic id it cannot serve.
         *
         * A blank model means "leave the conversation on whatever the
         * configuration already resolved", which is the honest behaviour for a
         * deployment that does not want this mapping.
         */
        'modes' => [
            'default' => env('AI_CHAT_DEFAULT_MODE', AiChatMode::READING),

            'reading' => [
                'model' => env('AI_CHAT_READING_MODEL', 'claude-sonnet-5'),
            ],

            'writing' => [
                'model' => env('AI_CHAT_WRITING_MODEL', 'claude-opus-5'),
            ],

            // Everything includes writing, so it is held to writing's model.
            'everything' => [
                'model' => env('AI_CHAT_EVERYTHING_MODEL', 'claude-opus-5'),
            ],
        ],

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

    /*
    |--------------------------------------------------------------------------
    | Spoken conversation
    |--------------------------------------------------------------------------
    |
    | Voice is speech-to-text, the ordinary assistant, and text-to-speech —
    | three steps rather than a fourth kind of session. That is what lets a
    | spoken question inherit sessions, attachments, capability modes, the audit
    | trail and the customer boundary without any of them being taught about
    | voice. See App\Services\AI\Voice\VoiceProviderInterface.
    |
    | NO CREDENTIAL IS CONFIGURED HERE. The provider reads the workspace's
    | existing OpenAI key through App\Services\AI\AiCredentialVault, which is
    | the only place in this application that decrypts one. A deployment that
    | has already configured OpenAI has voice; one that has not sees the control
    | disabled with a sentence naming the remedy, and nothing pretends to
    | listen.
    |
    | `driver` exists so a second vendor is a new class rather than an edit to
    | the panel. Anything unrecognised — including a typo — resolves to the
    | implementation that refuses and explains.
    |
    */

    'voice' => [

        'enabled' => (bool) env('AI_VOICE_ENABLED', true),

        'driver' => env('AI_VOICE_DRIVER', 'openai'),

        // Speech in. The same model family the attachment transcriber uses,
        // configured separately because a spoken turn and an uploaded
        // recording are different workloads: one is latency-critical.
        'transcription_model' => env('AI_VOICE_TRANSCRIPTION_MODEL', 'whisper-1'),

        // Speech out.
        'speech_model' => env('AI_VOICE_SPEECH_MODEL', 'gpt-4o-mini-tts'),

        /*
         * The voices offered in the panel, comma separated.
         *
         * An allow-list, not a suggestion: the chosen value is interpolated
         * into a vendor request and comes from a browser, so anything not on
         * this list becomes the default. Left empty, only the default is
         * offered and the picker does not appear — which is the honest
         * behaviour for a deployment that has not decided which voices its
         * vendor serves.
         */
        'voices' => env('AI_VOICE_VOICES', 'alloy,echo,fable,onyx,nova,shimmer'),

        'default_voice' => env('AI_VOICE_DEFAULT', 'alloy'),

        // Seconds. Lower than the chat timeout on purpose: a spoken turn that
        // takes two minutes to come back has already failed as a conversation.
        'timeout' => (float) env('AI_VOICE_TIMEOUT', 120),
    ],
    /*
    |--------------------------------------------------------------------------
    | The retrieval layer
    |--------------------------------------------------------------------------
    |
    | What the assistant may look up while answering, and how hard it is
    | allowed to try. See App\Services\AI\Tools\AiToolRunner for the loop and
    | App\Services\AI\Tools\AiToolRegistry for the list.
    |
    | These are cost and latency controls, not security controls. Security is
    | the readers each tool calls with the asking person as the viewer, and
    | availableTo() on each tool; turning `enabled` off narrows what the
    | assistant can answer, and grants nobody anything.
    |
    | `enabled` off is a supported posture rather than a kill switch for a bug:
    | a deployment that wants the cheaper, shallower assistant it had before
    | tools existed gets it by setting AI_TOOLS_ENABLED=false, and the context
    | block still carries the board roll-up.
    |
    */

    'tools' => [

        'enabled' => (bool) env('AI_TOOLS_ENABLED', true),

        /*
         * How many times the model may go round the loop before it has to
         * answer. Four is enough for "find the ticket, read it, check the pull
         * request, check the runbook"; a model still looking things up after
         * that is exploring rather than answering, and every round is a billed
         * request.
         */
        'max_rounds' => (int) env('AI_TOOLS_MAX_ROUNDS', 4),

        /*
         * Lookups in one round. A model that asks for twelve at once has
         * usually decided to enumerate the workspace.
         */
        'max_calls_per_round' => (int) env('AI_TOOLS_MAX_CALLS', 6),

        /*
         * Total characters of tool material one question may consume.
         *
         * Each tool already bounds its own output; this bounds the sum, which
         * is the number that actually protects the context window. When it
         * runs out the model is told so and asked to answer from what it has,
         * rather than being cut off mid-answer.
         */
        'max_result_characters' => (int) env('AI_TOOLS_MAX_CHARACTERS', 60000),
    ],
    /*
    |--------------------------------------------------------------------------
    | Chat attachments
    |--------------------------------------------------------------------------
    |
    | What may be attached to an assistant conversation, and how much of it
    | reaches the model.
    |
    | These limits are deliberately their own, not a reuse of
    | config('attachments'). A file attached to a ticket is stored and served;
    | a file attached to the assistant is stored, *read*, and its contents put
    | in front of a language model. The second is a wider blast radius, so it
    | gets a narrower allow-list — no archives, no Office macros-enabled
    | formats — and a smaller size ceiling.
    |
    | The storage disk is not configured here. Attachments go through
    | App\Services\AttachmentStorage, which reads config('attachments.disk'),
    | so there is exactly one answer to "where do uploaded files live" in this
    | application and one production durability check guarding it.
    |
    */
    'attachments' => [

        'enabled' => (bool) env('AI_ATTACHMENTS_ENABLED', true),

        /*
         * Per-file ceiling, and how many may hang off one conversation.
         *
         * 20 MB rather than the ticket limit's 10: a scanned PDF or a short
         * recording is legitimately larger than a screenshot, and the file is
         * read once at upload rather than on every question. The per-session
         * count is what stops a conversation accumulating an unbounded context.
         */
        'max_size_kb' => (int) env('AI_ATTACHMENT_MAX_SIZE_KB', 20480),

        'max_per_session' => (int) env('AI_ATTACHMENT_MAX_PER_SESSION', 10),

        /*
         * The upload allow-list, by extension, grouped by how the file will be
         * read. Anything not named here is refused before it is stored.
         *
         * Two absences are on purpose:
         *
         *   svg    is a document that can carry script, not a picture. It is
         *          allowed as a ticket attachment (where it is only ever served
         *          as a download) but not here, where an "image" is decoded and
         *          sent to a vision model.
         *   zip    would be a container whose contents nothing has validated.
         *
         * The keys are App\Enums\AiAttachmentKind values. A new format is a new
         * extension in the right group plus, if it needs one, a processor —
         * nothing else in the application changes.
         */
        'kinds' => [
            'text' => ['txt', 'log', 'json', 'yml', 'yaml'],
            'markdown' => ['md', 'markdown'],
            'csv' => ['csv', 'tsv'],
            'pdf' => ['pdf'],
            'image' => ['png', 'jpg', 'jpeg', 'gif', 'webp'],
            'audio' => ['mp3', 'm4a', 'wav', 'ogg', 'webm', 'mp4'],
            'document' => ['docx', 'xlsx', 'pptx'],
        ],

        /*
         * MIME types accepted for each kind, checked against the *detected*
         * type of the stored bytes rather than the browser's claim.
         *
         * Both checks have to pass: a file called `notes.txt` whose bytes are a
         * PDF is refused, and so is a PDF renamed to `.png`. Neither check is
         * sufficient alone — an extension is chosen by the uploader, and a
         * detected type says what something is but not what the uploader
         * claimed it was.
         *
         * A leading `text/` entry matches any text subtype, because content
         * detection legitimately answers `text/plain` for a CSV, a log and a
         * Markdown file alike.
         */
        'mimes' => [
            'text' => ['text/', 'application/json', 'application/x-yaml', 'application/yaml'],
            'markdown' => ['text/'],
            'csv' => ['text/', 'application/csv', 'application/vnd.ms-excel'],
            'pdf' => ['application/pdf'],
            'image' => ['image/png', 'image/jpeg', 'image/gif', 'image/webp'],
            'audio' => ['audio/', 'video/mp4', 'video/webm'],
            'document' => [
                'application/zip',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            ],
        ],

        /*
         * How much extracted text may reach the model.
         *
         * `per_file_characters` caps one document; `total_characters` caps
         * every attachment in a conversation put together, which is the figure
         * that actually protects the context window. Both are characters rather
         * than tokens because characters are what we can count exactly — the
         * token figure shown in the UI is derived from these and labelled an
         * estimate.
         *
         * Truncation is always announced in the prompt. A model that is handed
         * the first half of a document and not told is a model that will
         * confidently summarise the whole of it.
         */
        'context' => [
            'per_file_characters' => (int) env('AI_ATTACHMENT_FILE_CHARS', 40000),
            'total_characters' => (int) env('AI_ATTACHMENT_TOTAL_CHARS', 120000),

            /*
             * How much of a document survives into its digest.
             *
             * A digest is what an attachment from an *earlier* turn of the same
             * conversation contributes, instead of its whole text — see
             * App\Services\AI\Attachments\AiAttachmentContext for why re-sending
             * it every turn is the thing this exists to avoid.
             */
            'digest_characters' => (int) env('AI_ATTACHMENT_DIGEST_CHARS', 1200),

            /*
             * A rough characters-per-token ratio, used only to *display* an
             * estimate and to spend the budget above. Never recorded as usage:
             * the token counts in `ai_usage_records` come from the provider's
             * own response and nowhere else.
             */
            'characters_per_token' => (int) env('AI_ATTACHMENT_CHARS_PER_TOKEN', 4),
        ],

        /*
         * How much of a spreadsheet is put in front of the model.
         *
         * Rows are sent as data and the profile (column types, ranges,
         * outliers) is computed from *all* of them by
         * App\Services\AI\Attachments\CsvAnalyser — so a question about an
         * average is answered from every row even when only the first 300 are
         * quoted. The prompt says which is which, because a model that thinks
         * it has seen every row will state a total it cannot know.
         */
        'csv' => [
            'max_rows_in_prompt' => (int) env('AI_ATTACHMENT_CSV_ROWS', 300),
            'max_columns' => (int) env('AI_ATTACHMENT_CSV_COLUMNS', 40),
            'sample_values' => (int) env('AI_ATTACHMENT_CSV_SAMPLES', 5),
        ],

        /*
         * Images, as they are sent to a vision model.
         *
         * Re-encoded rather than forwarded: the stored bytes are whatever
         * somebody uploaded, and handing them to a provider verbatim makes the
         * provider's decoder our attack surface. GD reads the file, resizes it
         * within the box below and writes a fresh JPEG or PNG, so what leaves
         * this application is a file this application generated.
         *
         * The box is the point at which more pixels stop buying accuracy and
         * start buying tokens.
         */
        'image' => [
            'max_dimension' => (int) env('AI_ATTACHMENT_IMAGE_MAX_DIMENSION', 1568),
            'jpeg_quality' => (int) env('AI_ATTACHMENT_IMAGE_QUALITY', 82),
        ],

        /*
         * Audio transcription.
         *
         * Not part of the language-model call: an audio file has to become text
         * before there is anything to reason about, and that is a separate
         * endpoint with a separate model. It uses the OpenAI credential this
         * workspace already holds — the same one the OpenAI chat provider uses,
         * resolved through App\Services\AI\AiCredentialVault — so there is no
         * second secret to store.
         *
         * With no OpenAI key configured, an audio upload is stored and its card
         * says transcription is not configured and who can configure it. It
         * does not fail, and it does not silently do nothing.
         */
        'audio' => [
            'enabled' => (bool) env('AI_ATTACHMENT_AUDIO_ENABLED', true),
            'model' => env('AI_TRANSCRIPTION_MODEL', 'whisper-1'),
            'timeout' => (int) env('AI_TRANSCRIPTION_TIMEOUT', 180),
        ],
    ],

];
