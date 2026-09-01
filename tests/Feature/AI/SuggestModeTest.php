<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Actions\AI\CreateAiRun;
use App\Actions\AI\HandleAiRunFailure;
use App\Actions\AI\ProcessAiResult;
use App\Actions\AI\UpdateBoardAiSettings;
use App\Enums\AiRunMode;
use App\Enums\AiRunStatus;
use App\Enums\AiRunTrigger;
use App\Enums\CommentStream;
use App\Enums\TicketEventType;
use App\Jobs\ExecuteAiRunJob;
use App\Livewire\Tickets\Components\Comments;
use App\Models\AiRun;
use App\Models\Comment;
use App\Models\TicketEvent;
use App\Services\AI\AiRunWorkspace;
use App\Services\AI\ApplyModeRunner;
use App\Services\AI\TicketAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Suggest mode, end to end, with the provider faked.
 *
 * The job is executed directly rather than through the queue so the assertions
 * are about the run's own behaviour and not about the queue's.
 */
class SuggestModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_completed_run_posts_its_analysis_as_an_internal_note(): void
    {
        $provider = $this->fakeAiProvider();
        $provider->willReturn("## Summary\nThe export query drops the VAT column.");

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $run = AiRun::factory()->forTicket($ticket)->manual($team)->create();

        (new ExecuteAiRunJob($run->id))->handle(
            app(TicketAnalysisService::class),
            app(ApplyModeRunner::class),
            app(ProcessAiResult::class),
            app(HandleAiRunFailure::class),
            app(AiRunWorkspace::class),
        );

        $comment = Comment::query()->sole();

        // The single most important assertion in this phase.
        $this->assertSame(CommentStream::Internal, $comment->stream);
        $this->assertStringContainsString('drops the VAT column', $comment->body_md);

        // Attributed to the workspace, not to a person.
        $this->assertNull($comment->author_id);

        $run->refresh();
        $this->assertSame(AiRunStatus::Completed, $run->status);
        $this->assertSame($comment->id, $run->result_comment_id);
    }

    public function test_the_note_says_whether_the_repository_was_actually_read(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->repositoryOn($board);
        $ticket = $this->ticketOn($board, $team);

        $run = app(CreateAiRun::class)
            ->handle($ticket, AiRunMode::Suggest, AiRunTrigger::Manual, $team);

        $this->runJob($run);

        $comment = Comment::query()->sole();

        // Cloning is off by default, so the note must not imply the code was
        // read. A confident assessment produced from the ticket text alone
        // otherwise reads exactly like one produced from the repository.
        $this->assertStringContainsString('did NOT read the repository', $comment->body_md);
        $this->assertFalse((bool) $run->refresh()->meta('repository_checked_out'));
    }

    public function test_token_counts_and_cost_are_recorded(): void
    {
        $provider = $this->fakeAiProvider();
        $provider->inputTokens = 10_000;
        $provider->outputTokens = 2_000;
        $provider->model = 'claude-opus-5';

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $run = AiRun::factory()->forTicket($ticket)->manual($team)->create();
        $this->runJob($run);

        $run->refresh();

        $this->assertSame(10_000, $run->tokens_input);
        $this->assertSame(2_000, $run->tokens_output);

        // 10k input at $5/M plus 2k output at $25/M = $0.05 + $0.05.
        $this->assertSame('0.100000', $run->estimated_cost);
        $this->assertNotNull($run->duration_ms);
        $this->assertSame('claude-opus-5', $run->model);
    }

    public function test_an_unreported_token_count_stores_null_rather_than_zero(): void
    {
        $provider = $this->fakeAiProvider();
        $provider->willReportNoUsage();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $run = AiRun::factory()->forTicket($ticket)->manual($team)->create();
        $this->runJob($run);

        $run->refresh();

        // An invented figure in a cost report is worse than an honest blank.
        $this->assertNull($run->tokens_input);
        $this->assertNull($run->tokens_output);
        $this->assertNull($run->estimated_cost);
        $this->assertNull($run->totalTokens());
    }

    public function test_an_unpriced_model_stores_no_cost(): void
    {
        $provider = $this->fakeAiProvider();
        $provider->model = 'claude-something-nobody-has-priced';

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $run = AiRun::factory()->forTicket($ticket)->manual($team)->create();
        $this->runJob($run);

        $run->refresh();

        $this->assertNotNull($run->tokens_input);
        $this->assertNull($run->estimated_cost);
        $this->assertFalse((bool) $run->meta('cost_priced'));
    }

    public function test_the_prompt_carries_the_boards_project_context_and_custom_instructions(): void
    {
        $provider = $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        app(UpdateBoardAiSettings::class)->handle($board, [
            'project_context' => 'Laravel 12 monolith, MySQL, deployed on Railway.',
            'custom_system_prompt' => 'Always mention the migration implications.',
        ]);

        $ticket = $this->ticketOn($board->refresh(), $team);

        $run = AiRun::factory()->forTicket($ticket)->manual($team)->create();
        $this->runJob($run);

        $payload = $provider->lastPayload();

        $this->assertStringContainsString('deployed on Railway', $payload);
        $this->assertStringContainsString('migration implications', $payload);
    }

    public function test_the_prompt_carries_both_comment_streams_of_the_ticket(): void
    {
        $provider = $this->fakeAiProvider();

        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $ticket = $this->ticketOn($board, $customer, ['title' => 'Export broken']);

        $this->commentOn($ticket, $customer, 'It happens every Monday.', CommentStream::Customer);
        $this->commentOn($ticket, $team, 'Probably the weekly cron.', CommentStream::Internal);

        $run = AiRun::factory()->forTicket($ticket)->manual($team)->create();
        $this->runJob($run);

        $payload = $provider->lastPayload();

        // The team's own reasoning is the most valuable context there is, and
        // the answer goes straight back into that same private thread.
        $this->assertStringContainsString('every Monday', $payload);
        $this->assertStringContainsString('weekly cron', $payload);
    }

    public function test_the_note_is_attributed_to_the_workspace_rather_than_a_person(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember(['name' => 'Grace']);
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $run = AiRun::factory()->forTicket($ticket)->manual($team)->create();
        $this->runJob($run);

        // An authorless note is not the same thing as a note by a deleted user,
        // and the thread has to say which it is — otherwise a generated
        // assessment reads as somebody's opinion.
        Livewire::actingAs($team)
            ->test(Comments::class, ['ticket' => $ticket])
            ->assertSee('Workspace AI')
            ->assertSee('Generated')
            ->assertDontSee('Removed user');

        // …and the run is what makes it so: the caption comes from the
        // ai_runs → result_comment_id link, not from a guess about null authors.
        $this->assertSame($run->refresh()->result_comment_id, Comment::query()->sole()->id);
    }

    public function test_a_completed_run_records_an_internal_only_timeline_event(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $run = AiRun::factory()->forTicket($ticket)->manual($team)->create();
        $this->runJob($run);

        $event = TicketEvent::query()
            ->where('ticket_id', $ticket->id)
            ->where('type', TicketEventType::AiRunCompleted->value)
            ->sole();

        $this->assertTrue($event->type->isInternalOnly());
    }

    public function test_the_status_moves_queued_to_running_to_completed(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $run = AiRun::factory()->forTicket($ticket)->manual($team)->create();

        $this->assertSame(AiRunStatus::Queued, $run->status);

        $this->runJob($run);

        $run->refresh();

        $this->assertSame(AiRunStatus::Completed, $run->status);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
    }

    public function test_a_run_already_taken_by_another_worker_is_not_executed_twice(): void
    {
        $provider = $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        // Already claimed: another worker moved it off `queued`.
        $run = AiRun::factory()->forTicket($ticket)->manual($team)->running()->create();

        $this->runJob($run);

        // The claim is a conditional UPDATE, so this job finds nothing to do.
        $this->assertSame(0, $provider->calls);
        $this->assertSame(0, Comment::query()->count());
    }

    public function test_a_run_deleted_with_its_ticket_leaves_the_job_with_nothing_to_do(): void
    {
        $provider = $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $run = AiRun::factory()->forTicket($ticket)->manual($team)->create();
        $runId = $run->id;

        // A queued run whose ticket is deleted goes with it (cascade), so the
        // worker must find nothing and return quietly rather than throwing —
        // an exception here would retry three times and then fail a job over a
        // ticket somebody legitimately deleted.
        $ticket->delete();

        $this->assertSame(0, AiRun::query()->whereKey($runId)->count());

        (new ExecuteAiRunJob($runId))->handle(
            app(TicketAnalysisService::class),
            app(ApplyModeRunner::class),
            app(ProcessAiResult::class),
            app(HandleAiRunFailure::class),
            app(AiRunWorkspace::class),
        );

        $this->assertSame(0, $provider->calls);
    }

    /**
     * Execute the job in-process, with the container's dependencies.
     */
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
