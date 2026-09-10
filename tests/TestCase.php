<?php

declare(strict_types=1);

namespace Tests;

use App\Actions\AI\ManageBoardRepositories;
use App\Actions\AI\UpdateBoardAiSettings;
use App\Actions\AI\UpdateGlobalAiSettings;
use App\Actions\Boards\CreateDefaultColumns;
use App\Actions\Comments\PostComment;
use App\Actions\Docs\CreatePage;
use App\Actions\Docs\SetPageVisibility;
use App\Actions\Notifications\UpdateBoardIntegrations;
use App\Actions\Tickets\CreateTicket;
use App\Enums\AiCapabilityMode;
use App\Enums\AiProvider;
use App\Enums\AiRunMode;
use App\Enums\CommentStream;
use App\Enums\NotificationEvent;
use App\Enums\UserRole;
use App\Models\AiCredential;
use App\Models\AiGlobalSettings;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\BoardRepository;
use App\Models\Comment;
use App\Models\DocPage;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AI\AiConfigurationResolver;
use App\Services\AI\AiCredentialVault;
use App\Services\AI\AiProviderInterface;
use App\Services\GitHub\WebhookSignature;
use App\Services\SMS\SmsProviderInterface;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeAiProvider;
use Tests\Support\FakeSmsProvider;
use Tests\Support\UnreachableAiProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * Frames streamed by a Livewire component during this test.
     *
     * Filled by the output handler installed in setUp(); read through
     * streamedOutput().
     */
    private string $streamedOutput = '';

    /**
     * The output-buffer depth before this test installed its own handler.
     *
     * PHPUnit wraps each test in a buffer of its own, so tearDown() must unwind
     * only as far as this — closing PHPUnit's buffer makes every test "risky".
     */
    private int $outputBufferDepth = 0;

    /**
     * Make the suite independent of whoever's `.env` it happens to run against.
     *
     * The test suite loads the developer's `.env` (there is no `.env.testing`),
     * so without this the behaviour of every AI test would depend on whether
     * that particular machine has a working ANTHROPIC_API_KEY — passing locally
     * for one person, failing on CI, and quietly making billed API calls for
     * anybody who reached an AI path without faking it first.
     *
     * Two lines fix that, and both fail loudly rather than silently:
     *
     *   the credential is blanked, so "is AI configured?" has the same answer
     *   everywhere and `fakeAiProvider()` is what turns it on;
     *
     *   the provider is bound to one that throws, so a test that reaches an AI
     *   path without faking it gets an explanatory failure instead of a
     *   forty-second network call. See Tests\Support\UnreachableAiProvider.
     *
     * The SMS provider needs no equivalent: its default driver is already the
     * one that refuses, and `smsOn()` is what binds the fake.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.anthropic.api_key' => null]);

        $this->app->instance(AiProviderInterface::class, new UnreachableAiProvider);

        /*
         * Capture Livewire's streamed frames instead of printing them.
         *
         * Livewire streams by echoing a JSON frame per fragment and flushing —
         * correct in a real request, and unreadable in a test runner, where one
         * streamed answer prints a dozen frames between the test names.
         *
         * A handler that returns '' is what makes this work: ob_flush() passes
         * the buffer to the handler and sends on whatever it returns, so
         * returning nothing swallows the frame while still letting the test see
         * it through streamedOutput(). A plain ob_start() would not do — the
         * flush would print the frames and empty the buffer before a test could
         * read it.
         *
         * PHPUnit's own printer writes to php://stdout directly rather than
         * through PHP's output buffering, so failure reporting is unaffected.
         */
        $this->streamedOutput = '';
        $this->outputBufferDepth = ob_get_level();

        ob_start(function (string $chunk): string {
            $this->streamedOutput .= $chunk;

            return '';
        });
    }

    protected function tearDown(): void
    {
        while (ob_get_level() > $this->outputBufferDepth) {
            ob_end_clean();
        }

        parent::tearDown();
    }

    /**
     * Everything the component streamed during this test.
     *
     * The raw JSON frames, so an assertion can check what actually went on the
     * wire — which is the only way to prove streamed content was escaped, given
     * that Livewire's client assigns it with innerHTML.
     */
    protected function streamedOutput(): string
    {
        // Frames written since the last flush are still sitting in the buffer.
        if (ob_get_level() > 0) {
            ob_flush();
        }

        return $this->streamedOutput;
    }

    protected function admin(array $attributes = []): User
    {
        return User::factory()->admin()->create($attributes);
    }

    protected function teamMember(array $attributes = []): User
    {
        return User::factory()->team()->create($attributes);
    }

    protected function customer(array $attributes = []): User
    {
        return User::factory()->customer()->create($attributes);
    }

    protected function userWithRole(UserRole $role, array $attributes = []): User
    {
        return User::factory()->role($role)->create($attributes);
    }

    /**
     * A board with the given users already added as members.
     */
    protected function boardWithMembers(array $members = [], array $attributes = []): Board
    {
        $board = Board::factory()->create($attributes);

        if ($members !== []) {
            $board->members()->syncWithoutDetaching(
                collect($members)->map->getKey()->all()
            );
        }

        return $board;
    }

    /**
     * Satisfy the `password.confirm` middleware guarding administrator routes.
     */
    protected function withConfirmedPassword(): static
    {
        return $this->withSession(['auth.password_confirmed_at' => time()]);
    }

    /**
     * A board with the standard Backlog…Done columns, as CreateBoard would
     * produce, plus the given members.
     *
     * Board::factory() alone makes a board with no columns, which is a state
     * the application never creates and which tickets cannot live in.
     *
     * @param  array<int, User>  $members
     * @param  array<string, mixed>  $attributes
     */
    protected function boardWithColumns(array $members = [], array $attributes = []): Board
    {
        $board = $this->boardWithMembers($members, $attributes);

        app(CreateDefaultColumns::class)->handle($board);

        return $board->refresh();
    }

    /**
     * Create a ticket through the real action, so the customer rules, the
     * number allocation and the history all behave as they do in production.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function ticketOn(Board $board, User $author, array $attributes = []): Ticket
    {
        return app(CreateTicket::class)->handle(
            $board,
            array_merge(['title' => 'Sample ticket'], $attributes),
            $author
        );
    }

    protected function columnNamed(Board $board, string $name): BoardColumn
    {
        return $board->columns()->where('name', $name)->sole();
    }

    /**
     * Post a comment through the real action, so the stream rules apply exactly
     * as they do in production — a customer's comment is forced into the
     * customer stream however this is called.
     */
    protected function commentOn(
        Ticket $ticket,
        User $author,
        string $body = 'A comment',
        CommentStream $stream = CommentStream::Internal,
    ): Comment {
        return app(PostComment::class)->handle($ticket, $body, $stream, $author);
    }

    /**
     * Create a documentation page. Internal until published, as the action
     * insists.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function docPageOn(Board $board, User $author, array $attributes = []): DocPage
    {
        return app(CreatePage::class)->handle(
            $board,
            array_merge(['title' => 'Sample page'], $attributes),
            $author
        );
    }

    /**
     * Create a page and publish it to customers in one step.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function publishedPageOn(Board $board, User $author, array $attributes = []): DocPage
    {
        $page = $this->docPageOn($board, $author, $attributes);

        app(SetPageVisibility::class)->handle($page, true, $author);

        return $page->refresh();
    }

    // ---------------------------------------------------------------------
    // AI
    // ---------------------------------------------------------------------

    /**
     * Replace the real provider with the fake, and configure a credential.
     *
     * The credential is fake and is never used by anything: the binding below
     * means no HTTP client is ever constructed. It is set because the pipeline
     * refuses to start a run without one — which is itself a tested behaviour,
     * so it has to be satisfiable.
     *
     * Returns the fake, so a test can steer what the model "says" and then
     * inspect exactly what was sent to it.
     */
    protected function fakeAiProvider(): FakeAiProvider
    {
        config([
            'ai.enabled' => true,
            'ai.anthropic.api_key' => 'test-key-not-real',
        ]);

        $fake = new FakeAiProvider;

        $this->app->instance(AiProviderInterface::class, $fake);

        return $fake;
    }

    /**
     * Set the workspace's AI capability mode.
     *
     * The shipped default is AI Agent — every capability the product had before
     * modes existed — so a test that wants a *restriction* has to ask for one.
     * That is deliberate: it means the tests that exercise apply mode and
     * automatic runs read exactly as they did, and the mode tests say out loud
     * which mode they are about.
     */
    protected function aiMode(AiCapabilityMode $mode): AiGlobalSettings
    {
        $settings = app(UpdateGlobalAiSettings::class)->handle([
            'capability_mode' => $mode->value,
        ]);

        // The resolver memoises the settings row per request, and a test is one
        // "request" from its point of view.
        app(AiConfigurationResolver::class)->flush();

        return $settings;
    }

    /**
     * Set one board's own capability mode, overriding the workspace's.
     */
    protected function boardAiMode(Board $board, ?AiCapabilityMode $mode): Board
    {
        app(UpdateBoardAiSettings::class)->handle($board, [
            'capability_mode' => $mode?->value ?? '',
        ]);

        app(AiConfigurationResolver::class)->flush();

        return $board->refresh();
    }

    /**
     * Store a workspace provider key through the real vault, encrypted.
     *
     * Distinct from fakeAiProvider(), which puts a value in config: this
     * exercises the database path, which is the one the settings screen writes
     * and the one that has to be encrypted at rest.
     */
    protected function storeAiKey(
        string $key = 'sk-ant-test-key-not-real-0000abcd',
        AiProvider $provider = AiProvider::Anthropic,
        ?User $actor = null,
    ): AiCredential {
        $credential = app(AiCredentialVault::class)->store($provider, $key, null, $actor);

        app(AiConfigurationResolver::class)->flush();

        return $credential;
    }

    /**
     * Switch a board's automatic runs on.
     *
     * Goes through the real settings action so the coercion and clamping in
     * BoardAiSettings apply exactly as they do in production.
     */
    protected function enableAutomaticAiRuns(
        Board $board,
        AiRunMode $mode = AiRunMode::Suggest,
        ?int $dailyCap = null,
    ): Board {
        app(UpdateBoardAiSettings::class)->handle($board, array_filter([
            'auto_run_enabled' => true,
            'auto_run_mode' => $mode->value,
            'daily_auto_run_cap' => $dailyCap,
        ], static fn ($value): bool => $value !== null));

        return $board->refresh();
    }

    /**
     * A repository attached to a board, primary by default.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function repositoryOn(Board $board, array $attributes = []): BoardRepository
    {
        return app(ManageBoardRepositories::class)->create($board, array_merge([
            'repository_name' => 'acme/platform',
            'repository_url' => 'https://github.com/acme/platform.git',
        ], $attributes));
    }

    // ---------------------------------------------------------------------
    // Integrations
    // ---------------------------------------------------------------------

    /**
     * Configure a Slack webhook on a board, through the real action.
     *
     * The URL goes through the same encryption and validation as a form
     * submission, so a test that asserts "nothing was posted" is asserting
     * against a board that really was configured.
     *
     * @param  array<int, NotificationEvent>|null  $events  null means every event
     */
    protected function slackOn(Board $board, ?array $events = null, string $url = 'https://hooks.slack.com/services/T000/B000/xxxx'): Board
    {
        $enabled = [];

        foreach (NotificationEvent::slackEvents() as $event) {
            $enabled[$event->value] = $events === null || in_array($event, $events, true);
        }

        app(UpdateBoardIntegrations::class)->updateSlack($board, [
            'enabled' => true,
            'webhook_url' => $url,
            'events' => $enabled,
        ]);

        return $board->refresh();
    }

    /**
     * Turn on SMS alerts for a board, and bind the fake provider.
     *
     * @param  array<int, string>  $recipients
     */
    protected function smsOn(Board $board, array $recipients = ['+46701234567']): FakeSmsProvider
    {
        config(['sms.enabled' => true]);

        $fake = new FakeSmsProvider;
        $this->app->instance(SmsProviderInterface::class, $fake);

        app(UpdateBoardIntegrations::class)->updateSms($board, [
            'enabled' => true,
            'recipients' => $recipients,
        ]);

        $board->refresh();

        return $fake;
    }

    /**
     * Sign a webhook body the way GitHub would.
     *
     * Signed with WebhookSignature itself rather than a hand-written
     * hash_hmac line, so a change to the algorithm or the header prefix breaks
     * these tests instead of letting them quietly agree with themselves.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: array<string, mixed>, 1: array<string, string>} body and headers
     */
    protected function signedGithubDelivery(string $event, array $payload, string $deliveryId = 'delivery-1'): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return [$payload, [
            'X-GitHub-Event' => $event,
            'X-GitHub-Delivery' => $deliveryId,
            'X-Hub-Signature-256' => app(WebhookSignature::class)->sign($body),
        ]];
    }
}
