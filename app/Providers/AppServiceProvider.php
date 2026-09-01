<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\AiRun;
use App\Models\Attachment;
use App\Models\Board;
use App\Models\Comment;
use App\Models\DocPage;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\Channels\SlackWebhookChannel;
use App\Observers\CommentObserver;
use App\Observers\TicketObserver;
use App\Policies\AiRunPolicy;
use App\Policies\AttachmentPolicy;
use App\Policies\BoardPolicy;
use App\Policies\CommentPolicy;
use App\Policies\DocPagePolicy;
use App\Policies\TicketPolicy;
use App\Policies\UserPolicy;
use App\Services\AI\AiProviderInterface;
use App\Services\AI\ClaudeService;
use App\Services\AI\CodeGeneration\ClaudeCodeGenerator;
use App\Services\AI\CodeGeneration\CodeChangeGeneratorInterface;
use App\Services\AI\CodeGeneration\UnavailableCodeChangeGenerator;
use App\Services\BoardAccess;
use App\Services\SMS\ElksSmsProvider;
use App\Services\SMS\LogSmsProvider;
use App\Services\SMS\SmsProviderInterface;
use App\Services\SMS\UnavailableSmsProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton so per-request membership lookups are memoised once per user.
        $this->app->singleton(BoardAccess::class);

        $this->registerAiServices();
        $this->registerSmsProvider();
    }

    /**
     * Bind the SMS abstraction.
     *
     * Same shape and same reasoning as the AI provider binding: business logic
     * never names 46elks, tests bind a fake and therefore cannot reach the
     * network, and the credentials are read in exactly one class.
     *
     * The honest default is the *default*. Anything unrecognised — including an
     * empty value or a typo in a deployment variable — resolves to the
     * implementation that refuses and says what is missing, rather than to one
     * that silently discards alerts while appearing to work.
     */
    private function registerSmsProvider(): void
    {
        $this->app->singleton(SmsProviderInterface::class, function (): SmsProviderInterface {
            return match ((string) config('sms.driver')) {
                '46elks' => new ElksSmsProvider,
                'log' => new LogSmsProvider,
                default => new UnavailableSmsProvider,
            };
        });
    }

    /**
     * Bind the AI abstraction layer.
     *
     * Two interfaces, and both exist so the business logic never names a vendor:
     *
     *   AiProviderInterface           the language model. Bound to ClaudeService,
     *                                 which is the only class in the application
     *                                 that imports the Anthropic SDK or reads
     *                                 the API key. Tests bind a fake here and
     *                                 therefore cannot reach the network.
     *   CodeChangeGeneratorInterface  the runtime that edits files in apply
     *                                 mode. Selected by config so the honest
     *                                 default is the *default*: with no runtime
     *                                 configured, an apply run refuses with a
     *                                 message naming what to set, rather than
     *                                 opening an empty pull request.
     *
     * Singletons because ClaudeService builds its HTTP client lazily and holds
     * it, so one worker process makes one client rather than one per run.
     */
    private function registerAiServices(): void
    {
        $this->app->singleton(AiProviderInterface::class, ClaudeService::class);

        $this->app->singleton(CodeChangeGeneratorInterface::class, function (): CodeChangeGeneratorInterface {
            return match ((string) config('ai.code_generation.driver')) {
                'claude_code' => new ClaudeCodeGenerator,
                // Anything unrecognised, including an empty value, resolves to
                // the refusing implementation. A typo in a deployment variable
                // must not silently disable a safety step.
                default => new UnavailableCodeChangeGenerator,
            };
        });
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureAuthorization();
        $this->configurePasswords();
        $this->configureUrls();
        $this->configureRateLimiting();
        $this->configureNotificationChannels();
        $this->assertAttachmentStorageIsDurable();

        Vite::prefetch(concurrency: 3);
    }

    private function configureModels(): void
    {
        // Outside production, fail loudly on N+1 queries, missing attributes
        // and silently discarded mass-assignment values.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Short, stable aliases in the `attachable_type` column. Without this
        // the fully qualified class name is stored, and moving a model between
        // namespaces would orphan every attachment pointing at it.
        //
        // enforceMorphMap (rather than morphMap) also means an attachment
        // pointing at a type nobody has registered fails loudly instead of
        // resolving to something unchecked.
        Relation::enforceMorphMap([
            'ticket' => Ticket::class,
            'comment' => Comment::class,
            'doc_page' => DocPage::class,

            // `notifications.notifiable_type`. Enforcement is all-or-nothing,
            // so every model that appears in a morph column has to be named,
            // not only the attachable ones.
            'user' => User::class,
        ]);

        // Every ticket change is announced to the realtime channels and, where
        // relevant, to the notification bell. Registered here rather than with
        // an attribute so the full set of observers is visible in one place.
        Ticket::observe(TicketObserver::class);

        // Announces a customer's reply to the board's Slack channel, and
        // nothing else — the in-app fan-out lives in PostComment, which knows
        // about mentions and participants.
        Comment::observe(CommentObserver::class);
    }

    /**
     * Refuse to run in production with ephemeral attachment storage.
     *
     * The container filesystem on Railway is replaced on every deploy, so files
     * written to the local disk disappear at the next release. Failing at boot
     * is far better than discovering it when a customer reopens a ticket and
     * their attachment is gone.
     */
    private function assertAttachmentStorageIsDurable(): void
    {
        if (! $this->app->isProduction()) {
            return;
        }

        $disk = (string) config('attachments.disk');

        if (! in_array($disk, (array) config('attachments.durable_disks', []), true)) {
            throw new RuntimeException(
                "Attachment storage is configured to use the [{$disk}] disk, which does not survive a deploy. "
                .'Set FILESYSTEM_DISK (or ATTACHMENT_DISK) to an S3-compatible disk in production.'
            );
        }
    }

    private function configureAuthorization(): void
    {
        // Registered explicitly rather than relying on convention discovery so
        // that renaming a model can never silently drop its policy.
        Gate::policy(Board::class, BoardPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Ticket::class, TicketPolicy::class);
        Gate::policy(Comment::class, CommentPolicy::class);
        Gate::policy(DocPage::class, DocPagePolicy::class);
        Gate::policy(Attachment::class, AttachmentPolicy::class);
        Gate::policy(AiRun::class, AiRunPolicy::class);

        // Coarse gates used by navigation and route groups. Record-level
        // decisions always go through a policy, never through these.
        Gate::define('administer-workspace', fn (User $user): bool => $user->canAdministerWorkspace());
        Gate::define('view-internal-content', fn (User $user): bool => $user->canSeeInternalContent());
    }

    private function configurePasswords(): void
    {
        Password::defaults(function (): Password {
            $rule = Password::min(12)->letters()->numbers();

            return $this->app->isProduction()
                ? $rule->mixedCase()->uncompromised()
                : $rule;
        });
    }

    /**
     * Custom notification channels.
     *
     * Named `slack_webhook` rather than `slack` on purpose: Laravel reserves
     * `slack` for laravel/slack-notification-channel, which speaks the Slack
     * Web API with a bot token. That is a different integration with different
     * setup, and a name collision would make it impossible to add later without
     * silently rerouting every existing notification.
     */
    private function configureNotificationChannels(): void
    {
        Notification::extend(
            'slack_webhook',
            fn ($app): SlackWebhookChannel => $app->make(SlackWebhookChannel::class),
        );
    }

    /**
     * Named rate limiters.
     *
     * Only the webhook endpoint needs one today, and it needs one badly: it is
     * the single unauthenticated route that can enqueue work, so without a
     * limit an unsigned flood would fill the queue table before a single
     * signature was checked. Keyed on the caller's IP rather than globally, so
     * one noisy sender cannot starve a legitimate one.
     *
     * The limit is deliberately generous — a monorepo merge really does deliver
     * in bursts — and is a ceiling against abuse, not a shaping mechanism.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('github-webhooks', function (Request $request): Limit {
            return Limit::perMinute((int) config('github.webhook.rate_limit', 300))
                ->by($request->ip() ?? 'unknown');
        });
    }

    private function configureUrls(): void
    {
        // Railway terminates TLS at the edge; without this, generated URLs
        // (password reset links in particular) would be http://.
        if ($this->app->isProduction() || str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
