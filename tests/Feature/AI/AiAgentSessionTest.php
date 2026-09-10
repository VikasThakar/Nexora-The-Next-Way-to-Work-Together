<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Enums\AiRunMode;
use App\Enums\AiRunStage;
use App\Enums\AiRunStatus;
use App\Livewire\Tickets\Components\AiRuns;
use App\Models\AiRun;
use App\Services\AI\AiRunStageRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The coding session's lifecycle, on screen.
 *
 * A run takes minutes — a clone, a coding runtime, a test suite, a push — and
 * before this the panel said "Running" for all of it. These tests are about the
 * two properties that make progress reporting worth having and safe to have:
 *
 *   it is visible      each step is recorded as it starts, so somebody watching
 *                      can tell a stuck clone from a long test suite;
 *   it is not state    the status always wins. A worker killed mid-clone leaves
 *                      a stale `preparing` in the metadata, and a run whose
 *                      status says failed must not go on claiming to be
 *                      preparing.
 *
 * The second is the one that would bite. Progress can be wrong; the lifecycle
 * cannot, and stage() is where that ordering is enforced.
 */
class AiAgentSessionTest extends TestCase
{
    use RefreshDatabase;

    private function runOn(AiRunMode $mode, AiRunStatus $status, array $metadata = []): AiRun
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Needs work']);

        return AiRun::factory()->for($ticket)->create([
            'board_id' => $board->getKey(),
            'mode' => $mode,
            'status' => $status,
            'metadata' => $metadata,
        ]);
    }

    // -----------------------------------------------------------------
    // Deriving the stage
    // -----------------------------------------------------------------

    public function test_a_queued_run_reports_queued_whatever_its_metadata_says(): void
    {
        $run = $this->runOn(AiRunMode::Apply, AiRunStatus::Queued, ['stage' => 'coding']);

        $this->assertSame(AiRunStage::Queued, $run->stage());
    }

    public function test_a_running_run_reports_the_stage_it_recorded(): void
    {
        $run = $this->runOn(AiRunMode::Apply, AiRunStatus::Running, ['stage' => 'testing']);

        $this->assertSame(AiRunStage::Testing, $run->stage());
    }

    /**
     * Claimed but not yet at a step.
     *
     * Analysing is the honest answer for the first moment of any run, and it is
     * the only step a suggest run has.
     */
    public function test_a_running_run_with_nothing_recorded_reports_analysing(): void
    {
        $run = $this->runOn(AiRunMode::Suggest, AiRunStatus::Running);

        $this->assertSame(AiRunStage::Analysing, $run->stage());
    }

    public function test_the_status_beats_stale_progress(): void
    {
        $failed = $this->runOn(AiRunMode::Apply, AiRunStatus::Failed, ['stage' => 'preparing']);

        // The lifecycle wins…
        $this->assertSame(AiRunStage::Failed, $failed->stage());

        // …and the stale value is still useful, as the step it died on.
        $this->assertSame(AiRunStage::Preparing, $failed->stoppedAt());

        $completed = $this->runOn(AiRunMode::Apply, AiRunStatus::Completed, ['stage' => 'coding']);

        $this->assertSame(AiRunStage::Finished, $completed->stage());
        $this->assertNull($completed->stoppedAt());
    }

    public function test_a_cancelled_run_is_not_reported_as_still_working(): void
    {
        $run = $this->runOn(AiRunMode::Apply, AiRunStatus::Cancelled, ['stage' => 'coding']);

        $this->assertSame(AiRunStage::Failed, $run->stage());
    }

    public function test_the_pipeline_differs_by_mode(): void
    {
        $apply = AiRunStage::pipelineFor(AiRunMode::Apply);
        $suggest = AiRunStage::pipelineFor(AiRunMode::Suggest);

        $this->assertSame(
            [
                AiRunStage::Preparing,
                AiRunStage::Analysing,
                AiRunStage::Coding,
                AiRunStage::Testing,
                AiRunStage::PullRequest,
            ],
            $apply,
        );

        // Not a truncated apply pipeline: reading a ticket and writing an
        // opinion is the whole of what a suggest run does, and five greyed-out
        // steps would imply otherwise.
        $this->assertSame([AiRunStage::Analysing], $suggest);
    }

    // -----------------------------------------------------------------
    // Recording it
    // -----------------------------------------------------------------

    public function test_recording_a_stage_leaves_everything_else_alone(): void
    {
        $run = $this->runOn(AiRunMode::Apply, AiRunStatus::Running, ['workspace_retained' => true]);

        $before = $run->updated_at;

        app(AiRunStageRecorder::class)->record($run, AiRunStage::Coding, ['branch' => 'ai/AQD-42-abc']);

        $run->refresh();

        $this->assertSame('coding', $run->metadata['stage']);
        $this->assertSame('ai/AQD-42-abc', $run->metadata['branch']);
        $this->assertNotNull($run->metadata['stage_at']);

        // The existing diagnostics survive, and the status is untouched: this
        // is commentary on the lifecycle, not part of it.
        $this->assertTrue($run->metadata['workspace_retained']);
        $this->assertSame(AiRunStatus::Running, $run->status);
        $this->assertEquals($before, $run->updated_at);
    }

    /**
     * Progress reporting must never break the work it reports on.
     *
     * An audit row matters; the pull request matters more. Simulated by
     * dropping the table, which is the bluntest version of a locked one.
     */
    public function test_a_failure_to_record_progress_is_swallowed(): void
    {
        $run = $this->runOn(AiRunMode::Apply, AiRunStatus::Running);

        Schema::drop('ai_runs');

        app(AiRunStageRecorder::class)->record($run, AiRunStage::Coding);

        // No exception. That is the whole assertion.
        $this->assertTrue(true);
    }

    // -----------------------------------------------------------------
    // What the panel shows
    // -----------------------------------------------------------------

    public function test_the_panel_shows_the_step_in_flight(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Needs work']);

        AiRun::factory()->for($ticket)->create([
            'board_id' => $board->getKey(),
            'mode' => AiRunMode::Apply,
            'status' => AiRunStatus::Running,
            'branch_name' => 'ai/AQD-42-abc1234',
            'repository' => 'aqueduct/platform',
            'metadata' => [
                'stage' => 'testing',
                'changed_files' => ['app/Services/Vat.php', 'tests/Feature/VatTest.php'],
            ],
        ]);

        $html = Livewire::actingAs($team)
            ->test(AiRuns::class, ['ticket' => $ticket])
            ->html();

        $flat = trim((string) preg_replace('/\s+/', ' ', $html));

        // The pipeline, with the current step named.
        $this->assertStringContainsString('Preparing', $flat);
        $this->assertStringContainsString('Coding', $flat);
        $this->assertStringContainsString('Testing', $flat);
        $this->assertStringContainsString('Pull request', $flat);

        // What it produced so far.
        $this->assertStringContainsString('ai/AQD-42-abc1234', $flat);
        $this->assertStringContainsString('aqueduct/platform', $flat);
        $this->assertStringContainsString('2 files changed', $flat);
        $this->assertStringContainsString('app/Services/Vat.php', $flat);
    }

    public function test_the_panel_shows_which_tests_ran_and_whether_they_passed(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Needs work']);

        AiRun::factory()->for($ticket)->create([
            'board_id' => $board->getKey(),
            'mode' => AiRunMode::Apply,
            'status' => AiRunStatus::Failed,
            'metadata' => [
                'stage' => 'testing',
                'validation' => [
                    ['command' => 'composer test', 'passed' => false],
                    ['command' => 'vendor/bin/pint --test', 'passed' => true],
                ],
            ],
        ]);

        $html = Livewire::actingAs($team)
            ->test(AiRuns::class, ['ticket' => $ticket])
            ->html();

        $flat = trim((string) preg_replace('/\s+/', ' ', $html));

        // A failed command is shown rather than hidden: validation runs before
        // the push, so a red command means no pull request was opened.
        $this->assertStringContainsString('composer test', $flat);
        $this->assertStringContainsString('vendor/bin/pint --test', $flat);

        // And the failure names the step it stopped on.
        $this->assertStringContainsString('Stopped while testing', $flat);
    }

    public function test_a_suggest_run_shows_one_step_rather_than_a_code_pipeline(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Needs work']);

        AiRun::factory()->for($ticket)->create([
            'board_id' => $board->getKey(),
            'mode' => AiRunMode::Suggest,
            'status' => AiRunStatus::Running,
        ]);

        $html = Livewire::actingAs($team)
            ->test(AiRuns::class, ['ticket' => $ticket])
            ->html();

        $flat = trim((string) preg_replace('/\s+/', ' ', $html));

        $this->assertStringContainsString('Analysing', $flat);
        $this->assertStringNotContainsString('Pull request', $flat);
    }

    /**
     * The whole panel, including the timeline, is invisible to a customer.
     *
     * Not a consequence of the timeline: AiRun rows refuse customers in SQL and
     * the component 404s on mount. Asserted here anyway, because the timeline
     * is new markup carrying branch names and commit-adjacent detail.
     */
    public function test_a_customer_cannot_see_a_session_timeline(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $ticket = $this->ticketOn($board, $customer, ['title' => 'My bug']);

        AiRun::factory()->for($ticket)->create([
            'board_id' => $board->getKey(),
            'mode' => AiRunMode::Apply,
            'status' => AiRunStatus::Running,
            'branch_name' => 'ai/secret-branch-name',
            'metadata' => ['stage' => 'coding'],
        ]);

        // Refused in SQL, so a list, a count and a lookup all return
        // nothing whatever a screen does.
        $this->assertSame(0, AiRun::query()->visibleTo($customer)->count());

        // And refused by the policy the panel authorizes on mount and on
        // every render, as 404 rather than 403: the existence of the
        // feature on their ticket is itself internal.
        $this->assertFalse($customer->can('viewAny', [AiRun::class, $ticket]));

        // The staff panel does render it, so the assertions above are not
        // passing because the markup is missing for everybody.
        $html = Livewire::actingAs($team)
            ->test(AiRuns::class, ['ticket' => $ticket])
            ->html();

        $this->assertStringContainsString('ai/secret-branch-name', $html);
    }
}
