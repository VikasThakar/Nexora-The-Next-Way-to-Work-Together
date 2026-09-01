<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Enums\AiRunMode;
use App\Enums\AiRunStatus;
use App\Enums\AiRunTrigger;
use App\Jobs\ExecuteAiRunJob;
use App\Livewire\Tickets\Components\AiRuns;
use App\Models\AiRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Manual runs: who may start one, and what happens when they do.
 *
 * Driven through the Livewire component rather than the action, because the
 * property being tested is that the *screen* authorizes — an action that
 * authorizes correctly behind a component that never calls it would pass a
 * direct test and fail in production.
 */
class ManualRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_team_member_can_start_a_manual_suggest_run(): void
    {
        Queue::fake();
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(AiRuns::class, ['ticket' => $ticket])
            ->set('mode', AiRunMode::Suggest->value)
            ->call('run')
            ->assertHasNoErrors();

        $run = AiRun::query()->sole();

        $this->assertSame(AiRunTrigger::Manual, $run->trigger_source);
        $this->assertSame($team->id, $run->triggered_by_id);
        $this->assertSame(AiRunStatus::Queued, $run->status);

        Queue::assertPushed(ExecuteAiRunJob::class);
    }

    public function test_an_administrator_can_start_a_manual_run_on_a_board_they_are_not_a_member_of(): void
    {
        Queue::fake();
        $this->fakeAiProvider();

        $admin = $this->admin();
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        // Administrators bypass board membership everywhere else; AI is no
        // different, and the policy composes that rule rather than restating it.
        Livewire::actingAs($admin)
            ->test(AiRuns::class, ['ticket' => $ticket])
            ->call('run')
            ->assertHasNoErrors();

        $this->assertSame(1, AiRun::query()->count());
    }

    public function test_apply_mode_asks_for_confirmation_before_queueing(): void
    {
        Queue::fake();
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->repositoryOn($board);
        $ticket = $this->ticketOn($board->refresh(), $team);

        $component = Livewire::actingAs($team)
            ->test(AiRuns::class, ['ticket' => $ticket])
            ->set('mode', AiRunMode::Apply->value)
            ->call('run');

        // Opening a pull request in the team's repository should not look like
        // posting a note.
        $component->assertSet('confirmingApply', true);
        $this->assertSame(0, AiRun::query()->count());

        $component->call('run');

        $this->assertSame(1, AiRun::query()->count());
        $this->assertSame(AiRunMode::Apply, AiRun::query()->sole()->mode);
    }

    public function test_a_queued_run_can_be_cancelled(): void
    {
        Queue::fake();
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $run = AiRun::factory()->forTicket($ticket)->manual($team)->create();

        Livewire::actingAs($team)
            ->test(AiRuns::class, ['ticket' => $ticket])
            ->call('cancel', $run->id);

        $this->assertSame(AiRunStatus::Cancelled, $run->refresh()->status);
    }

    public function test_a_running_run_cannot_be_cancelled(): void
    {
        Queue::fake();
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $run = AiRun::factory()->forTicket($ticket)->manual($team)->running()->create();

        Livewire::actingAs($team)
            ->test(AiRuns::class, ['ticket' => $ticket])
            ->call('cancel', $run->id)
            ->assertForbidden();

        $this->assertSame(AiRunStatus::Running, $run->refresh()->status);
    }

    public function test_a_run_on_another_board_cannot_be_cancelled_through_this_ticket(): void
    {
        Queue::fake();
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $mine = $this->boardWithColumns([$team]);
        $theirs = $this->boardWithColumns([$team]);

        $myTicket = $this->ticketOn($mine, $team);
        $theirTicket = $this->ticketOn($theirs, $team);

        $foreign = AiRun::factory()->forTicket($theirTicket)->manual($team)->create();

        // The id is resolved within this board, so a swapped one 404s rather
        // than reaching across.
        Livewire::actingAs($team)
            ->test(AiRuns::class, ['ticket' => $myTicket])
            ->call('cancel', $foreign->id)
            ->assertNotFound();

        $this->assertSame(AiRunStatus::Queued, $foreign->refresh()->status);
    }

    public function test_a_second_run_is_refused_while_one_is_still_in_flight(): void
    {
        Queue::fake();
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        AiRun::factory()->forTicket($ticket)->manual($team)->running()->create();

        Livewire::actingAs($team)
            ->test(AiRuns::class, ['ticket' => $ticket])
            ->call('run');

        // The courtesy check; the job's claim is the correctness one.
        $this->assertSame(1, AiRun::query()->count());
    }

    public function test_an_invalid_mode_is_rejected(): void
    {
        Queue::fake();
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(AiRuns::class, ['ticket' => $ticket])
            // `off` is a board setting, never a run.
            ->set('mode', 'off')
            ->call('run')
            ->assertHasErrors('mode');

        $this->assertSame(0, AiRun::query()->count());
    }

    public function test_a_manual_run_records_who_asked_for_it(): void
    {
        Queue::fake();
        $this->fakeAiProvider();

        $team = $this->teamMember(['name' => 'Ada']);
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(AiRuns::class, ['ticket' => $ticket])
            ->call('run');

        $run = AiRun::query()->with('triggeredBy')->sole();

        $this->assertSame('Ada', $run->triggeredBy?->name);
    }
}
