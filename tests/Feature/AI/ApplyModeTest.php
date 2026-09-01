<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Actions\AI\HandleAiRunFailure;
use App\Actions\AI\ProcessAiResult;
use App\Enums\AiRunStatus;
use App\Enums\CommentStream;
use App\Jobs\ExecuteAiRunJob;
use App\Models\AiRun;
use App\Models\Comment;
use App\Models\Ticket;
use App\Services\AI\AiRunWorkspace;
use App\Services\AI\ApplyModeRunner;
use App\Services\AI\CodeGeneration\CodeChangeGeneratorInterface;
use App\Services\AI\CodeGeneration\CodeChangeResult;
use App\Services\AI\CodeGeneration\UnavailableCodeChangeGenerator;
use App\Services\AI\Exceptions\AiProviderException;
use App\Services\AI\Exceptions\AiWorkspaceException;
use App\Services\AI\TicketAnalysisService;
use App\Services\GitHub\PullRequestClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Apply mode's refusals, and its isolation.
 *
 * The happy path of apply mode needs a real git remote and a real coding
 * runtime, neither of which belongs in a test suite. What is tested here is
 * everything the architecture promises *around* that step — and in particular
 * that an unconfigured deployment refuses honestly rather than opening an empty
 * pull request.
 *
 * Http::preventStrayRequests() is the guard that makes these tests meaningful:
 * if any code path here reached GitHub, the test would fail rather than quietly
 * make a network call.
 */
class ApplyModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_apply_mode_refuses_before_cloning_when_no_coding_runtime_is_configured(): void
    {
        $this->fakeAiProvider();

        // The default. `unavailable` is deliberately the default driver.
        $this->assertInstanceOf(
            UnavailableCodeChangeGenerator::class,
            app(CodeChangeGeneratorInterface::class)
        );

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $repository = $this->repositoryOn($board);
        $ticket = $this->ticketOn($board, $team);

        $run = AiRun::factory()->forTicket($ticket)->manual($team)->apply()->create([
            'board_repository_id' => $repository->id,
            'repository' => $repository->repository_name,
        ]);

        $this->runJob($run);

        $comment = Comment::query()->sole();

        // Internal, honest, and actionable.
        $this->assertSame(CommentStream::Internal, $comment->stream);
        $this->assertStringContainsString('Apply mode is not configured', $comment->body_md);
        $this->assertStringContainsString('AI_CODE_DRIVER=claude_code', $comment->body_md);
        $this->assertStringContainsString('GITHUB_TOKEN', $comment->body_md);

        $run->refresh();
        $this->assertSame(AiRunStatus::Failed, $run->status);

        // The important part: nothing was created.
        $this->assertNull($run->pull_request_url);
        $this->assertNull($run->branch_name);
    }

    public function test_apply_mode_refuses_when_no_github_token_is_configured(): void
    {
        $this->fakeAiProvider();

        // Pretend the coding runtime exists so the GitHub check is the one that
        // fires. Bound as an anonymous implementation rather than mocked so the
        // interface's contract is exercised.
        $this->app->instance(CodeChangeGeneratorInterface::class, new class implements CodeChangeGeneratorInterface
        {
            public function name(): string
            {
                return 'test';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function unavailableReason(): ?string
            {
                return null;
            }

            public function generate(
                AiRun $run,
                Ticket $ticket,
                string $checkoutPath,
                string $brief
            ): CodeChangeResult {
                throw new \RuntimeException('Should never be reached: the run is refused first.');
            }
        });

        config(['github.token' => null]);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $repository = $this->repositoryOn($board);
        $ticket = $this->ticketOn($board, $team);

        $run = AiRun::factory()->forTicket($ticket)->manual($team)->apply()->create([
            'board_repository_id' => $repository->id,
            'repository' => $repository->repository_name,
        ]);

        $this->runJob($run);

        $comment = Comment::query()->sole();

        // Refused before any code is changed, because a pull request could
        // never be opened at the end of it.
        $this->assertStringContainsString('GITHUB_TOKEN', $comment->body_md);
        $this->assertSame(AiRunStatus::Failed, $run->refresh()->status);
    }

    public function test_a_run_whose_repository_was_detached_fails_rather_than_guessing(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        // An apply run with no repository at all: detached between queueing and
        // execution.
        $run = AiRun::factory()->forTicket($ticket)->manual($team)->apply()->create([
            'board_repository_id' => null,
            'repository' => 'acme/gone',
        ]);

        $this->runJob($run);

        $this->assertSame(AiRunStatus::Failed, $run->refresh()->status);
        $this->assertStringContainsString(
            'no longer attached',
            (string) Comment::query()->sole()->body_md
        );
    }

    public function test_every_run_gets_its_own_isolated_directory_under_storage(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $one = AiRun::factory()->forTicket($ticket)->create();
        $two = AiRun::factory()->forTicket($ticket)->create();

        $workspace = app(AiRunWorkspace::class);

        $pathOne = $workspace->pathFor($one);
        $pathTwo = $workspace->pathFor($two);

        $this->assertNotSame($pathOne, $pathTwo);

        // Never the application directory, and never outside storage/app.
        $this->assertStringStartsWith(
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, storage_path('app')),
            $pathOne
        );
        $this->assertStringContainsString((string) $one->uuid, $pathOne);
    }

    public function test_a_workspace_path_that_escapes_storage_is_refused(): void
    {
        config(['ai.workspace.path' => '../../..']);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);
        $run = AiRun::factory()->forTicket($ticket)->create();

        // A mistyped AI_WORKSPACE_PATH must not point a coding agent at the
        // application's own source tree. The resolved path is still inside
        // whatever base it computed, so this asserts the base itself moved
        // rather than the containment check being bypassed.
        $path = app(AiRunWorkspace::class)->pathFor($run);

        $this->assertStringNotContainsString('..', $path);
    }

    public function test_a_malformed_run_identifier_cannot_build_a_path(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);
        $run = AiRun::factory()->forTicket($ticket)->create(['uuid' => '../../etc']);

        $this->expectException(AiWorkspaceException::class);

        app(AiRunWorkspace::class)->pathFor($run);
    }

    public function test_the_working_directory_is_deleted_after_the_run(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $run = AiRun::factory()->forTicket($ticket)->manual($team)->create();
        $path = app(AiRunWorkspace::class)->pathFor($run);

        $this->runJob($run);

        // Railway containers are ephemeral, so nothing durable may live here —
        // and the tree goes whether the run succeeded or not.
        $this->assertFalse(File::isDirectory($path));
        $this->assertSame(AiRunStatus::Completed, $run->refresh()->status);
    }

    public function test_the_working_directory_is_deleted_after_a_failure_too(): void
    {
        $provider = $this->fakeAiProvider();
        $provider->willFail(AiProviderException::timedOut());

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $run = AiRun::factory()->forTicket($ticket)->manual($team)->create();
        $path = app(AiRunWorkspace::class)->pathFor($run);

        $this->runJob($run);

        $this->assertFalse(File::isDirectory($path));
    }

    public function test_the_pull_request_client_has_no_way_to_merge(): void
    {
        // Apply mode's promise is "a human reviews it". The cheapest way to keep
        // it is for the code that could break it not to exist.
        $methods = get_class_methods(PullRequestClient::class);

        $this->assertSame(['isConfigured', 'open'], array_values($methods));
    }

    private function runJob(AiRun $run): void
    {
        (new ExecuteAiRunJob($run->id))->handle(
            app(TicketAnalysisService::class),
            app(ApplyModeRunner::class),
            app(ProcessAiResult::class),
            app(HandleAiRunFailure::class),
            app(AiRunWorkspace::class),
        );
    }
}
