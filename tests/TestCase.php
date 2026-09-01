<?php

declare(strict_types=1);

namespace Tests;

use App\Actions\AI\ManageBoardRepositories;
use App\Actions\AI\UpdateBoardAiSettings;
use App\Actions\Boards\CreateDefaultColumns;
use App\Actions\Comments\PostComment;
use App\Actions\Docs\CreatePage;
use App\Actions\Docs\SetPageVisibility;
use App\Actions\Notifications\UpdateBoardIntegrations;
use App\Actions\Tickets\CreateTicket;
use App\Enums\AiRunMode;
use App\Enums\CommentStream;
use App\Enums\NotificationEvent;
use App\Enums\UserRole;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\BoardRepository;
use App\Models\Comment;
use App\Models\DocPage;
use App\Models\Ticket;
use App\Models\User;
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
