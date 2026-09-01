<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Actions\AI\HandleAiRunFailure;
use App\Actions\AI\ProcessAiResult;
use App\Enums\AiRunStatus;
use App\Enums\CommentStream;
use App\Enums\TicketEventType;
use App\Jobs\ExecuteAiRunJob;
use App\Models\AiRun;
use App\Models\Comment;
use App\Models\TicketEvent;
use App\Services\AI\AiRunWorkspace;
use App\Services\AI\ApplyModeRunner;
use App\Services\AI\Exceptions\AiProviderException;
use App\Services\AI\TicketAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What happens when a run goes wrong.
 *
 * The property that matters: a failure is never silent. Somebody switched
 * automation on expecting a note, and silence is indistinguishable from "the
 * model had nothing to add".
 */
class RunFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_run_creates_an_internal_note(): void
    {
        $provider = $this->fakeAiProvider();
        $provider->willFail(AiProviderException::unauthorized());

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $run = AiRun::factory()->forTicket($ticket)->manual($team)->create();

        $this->runJob($run);

        $comment = Comment::query()->sole();

        // Internal, always. An AI error names infrastructure and missing
        // credentials, so it is if anything more sensitive than a success.
        $this->assertSame(CommentStream::Internal, $comment->stream);
        $this->assertStringContainsString('failed', $comment->body_md);
        $this->assertStringContainsString('ANTHROPIC_API_KEY', $comment->body_md);
        $this->assertNull($comment->author_id);

        $run->refresh();
        $this->assertSame(AiRunStatus::Failed, $run->status);
        $this->assertSame($comment->id, $run->result_comment_id);
        $this->assertNotNull($run->error_message);
        $this->assertNotNull($run->finished_at);
    }

    public function test_a_failure_note_is_not_visible_to_a_customer(): void
    {
        $provider = $this->fakeAiProvider();
        $provider->willFail(AiProviderException::timedOut());

        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        // A customer-visible ticket, so the *ticket* is readable and only the
        // stream stands between the customer and the error.
        $ticket = $this->ticketOn($board, $customer);
        $this->assertTrue($ticket->customer_visible);

        $run = AiRun::factory()->forTicket($ticket)->automatic()->create();
        $this->runJob($run);

        $this->assertSame(1, Comment::query()->count());

        $this->assertSame(
            0,
            Comment::query()->visibleTo($customer)->where('ticket_id', $ticket->id)->count()
        );
    }

    public function test_a_failed_run_records_an_internal_only_timeline_event(): void
    {
        $provider = $this->fakeAiProvider();
        $provider->willFail(AiProviderException::overloaded());

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $run = AiRun::factory()->forTicket($ticket)->manual($team)->create();
        $this->runJob($run);

        $event = TicketEvent::query()
            ->where('ticket_id', $ticket->id)
            ->where('type', TicketEventType::AiRunFailed->value)
            ->sole();

        $this->assertTrue($event->type->isInternalOnly());
    }

    public function test_a_provider_refusal_is_written_up_as_a_refusal_not_as_an_assessment(): void
    {
        $provider = $this->fakeAiProvider();

        // ClaudeService turns a `refusal` stop reason into this exception rather
        // than posting whatever text came back: a decline is a considered answer
        // but it is not a result, and posting it as an assessment would be a lie.
        $provider->willFail(AiProviderException::refused('cyber'));

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $run = AiRun::factory()->forTicket($ticket)->manual($team)->create();
        $this->runJob($run);

        $comment = Comment::query()->sole();

        $this->assertSame(CommentStream::Internal, $comment->stream);
        $this->assertStringContainsString('declined to answer', $comment->body_md);
        $this->assertSame(AiRunStatus::Failed, $run->refresh()->status);
    }

    public function test_an_intermediate_attempt_records_the_reason_without_writing_a_note(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $run = AiRun::factory()->forTicket($ticket)->manual($team)->running()->create();

        app(HandleAiRunFailure::class)->handle($run, 'Rate limited.', willRetry: true);

        // Three attempts must not produce three notes.
        $this->assertSame(0, Comment::query()->count());

        $run->refresh();
        $this->assertSame(AiRunStatus::Running, $run->status);
        $this->assertSame('Rate limited.', $run->error_message);
        $this->assertSame('Rate limited.', $run->meta('last_attempt_error'));
    }

    public function test_the_failed_hook_writes_a_note_when_the_worker_dies_mid_run(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        // Claimed, then the worker was killed: handle() never returned.
        $run = AiRun::factory()->forTicket($ticket)->manual($team)->running()->create();

        (new ExecuteAiRunJob($run->id))->failed(new \RuntimeException('Worker timed out.'));

        $run->refresh();

        $this->assertSame(AiRunStatus::Failed, $run->status);
        $this->assertSame(CommentStream::Internal, Comment::query()->sole()->stream);
    }

    public function test_the_failed_hook_does_nothing_for_a_run_that_already_finished(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $run = AiRun::factory()->forTicket($ticket)->manual($team)->completed()->create();

        (new ExecuteAiRunJob($run->id))->failed(new \RuntimeException('Late failure.'));

        // A successful run must not be rewritten as a failure by a late hook.
        $this->assertSame(AiRunStatus::Completed, $run->refresh()->status);
        $this->assertSame(0, Comment::query()->count());
    }

    public function test_a_failure_handler_that_cannot_write_does_not_throw(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $run = AiRun::factory()->forTicket($ticket)->manual($team)->create();

        // Deliberately absurd, to prove the handler swallows rather than
        // propagates: a failure handler that fails would lose the diagnosis.
        app(HandleAiRunFailure::class)->handle($run, str_repeat('x', 100_000));

        $this->assertSame(AiRunStatus::Failed, $run->refresh()->status);
    }

    public function test_a_completed_run_and_a_failed_run_both_leave_exactly_one_note(): void
    {
        $provider = $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $good = AiRun::factory()->forTicket($ticket)->manual($team)->create();
        $this->runJob($good);

        $provider->willFail(AiProviderException::rateLimited());

        $bad = AiRun::factory()->forTicket($ticket)->manual($team)->create();
        $this->runJob($bad);

        $this->assertSame(2, Comment::query()->count());
        $this->assertSame(
            2,
            Comment::query()->where('stream', CommentStream::Internal->value)->count()
        );
    }

    public function test_a_result_is_never_written_to_the_customer_stream_even_if_asked(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $run = AiRun::factory()->forTicket($ticket)->manual($team)->create();

        // ProcessAiResult takes no stream parameter at all: there is no value a
        // caller can pass to publish an assessment to a customer. This test
        // exists to fail loudly if that ever becomes configurable.
        app(ProcessAiResult::class)->handle(
            run: $run,
            ticket: $ticket,
            body: 'Anything at all.',
            inputTokens: 1,
            outputTokens: 1,
            model: 'claude-opus-5',
            durationMs: 10,
        );

        $this->assertSame(CommentStream::Internal, Comment::query()->sole()->stream);
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
