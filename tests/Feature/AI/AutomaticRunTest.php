<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Actions\AI\ManageBoardRepositories;
use App\Actions\AI\UpdateBoardAiSettings;
use App\Enums\AiRunMode;
use App\Enums\AiRunStatus;
use App\Enums\AiRunTrigger;
use App\Enums\TicketEventType;
use App\Jobs\ExecuteAiRunJob;
use App\Livewire\Tickets\Create as TicketCreate;
use App\Models\AiRun;
use App\Models\TicketEvent;
use App\Services\AI\Exceptions\AiProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Auto-run: when a customer ticket starts an AI run, and when it does not.
 *
 * These drive the real path — TicketObserver → TriggerAutomaticAiRun →
 * CreateAiRun — rather than calling the action directly, because the property
 * being tested is "creating a ticket has this effect", and an observer that is
 * never wired up would pass a direct-call test.
 */
class AutomaticRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_ticket_queues_an_ai_run_when_the_board_has_it_enabled(): void
    {
        Queue::fake();
        $this->fakeAiProvider();

        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $this->enableAutomaticAiRuns($board, AiRunMode::Suggest);

        $ticket = $this->ticketOn($board, $customer, ['title' => 'The export is missing a column']);

        $run = AiRun::query()->sole();

        $this->assertSame($ticket->id, $run->ticket_id);
        $this->assertSame($board->id, $run->board_id);
        $this->assertSame(AiRunMode::Suggest, $run->mode);
        $this->assertSame(AiRunTrigger::Automatic, $run->trigger_source);
        $this->assertSame(AiRunStatus::Queued, $run->status);

        Queue::assertPushed(ExecuteAiRunJob::class, fn (ExecuteAiRunJob $job): bool => $job->aiRunId === $run->id);
    }

    public function test_the_run_is_queued_on_the_dedicated_ai_queue(): void
    {
        Queue::fake();
        $this->fakeAiProvider();
        config(['ai.queue.name' => 'ai']);

        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $this->enableAutomaticAiRuns($board);

        $this->ticketOn($board, $customer);

        // A long AI run must not sit in front of a broadcast or a notification.
        Queue::assertPushedOn('ai', ExecuteAiRunJob::class);
    }

    public function test_no_run_is_queued_when_the_board_mode_is_off(): void
    {
        Queue::fake();
        $this->fakeAiProvider();

        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        // Deliberately not configured: the default must be off.
        $this->ticketOn($board, $customer);

        $this->assertSame(0, AiRun::query()->count());
        Queue::assertNotPushed(ExecuteAiRunJob::class);
    }

    public function test_no_run_is_queued_when_automation_is_disabled_even_though_a_mode_is_set(): void
    {
        Queue::fake();
        $this->fakeAiProvider();

        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        // Both switches have to agree; a mode alone is not enough.
        $this->enableAutomaticAiRuns($board, AiRunMode::Suggest);
        app(UpdateBoardAiSettings::class)->handle($board, ['auto_run_enabled' => false]);

        $this->ticketOn($board->refresh(), $customer);

        $this->assertSame(0, AiRun::query()->count());
    }

    public function test_a_staff_ticket_does_not_trigger_an_automatic_run(): void
    {
        Queue::fake();
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->enableAutomaticAiRuns($board);

        $this->ticketOn($board, $team);

        // Staff have a button. Automation exists for work arriving from outside.
        $this->assertSame(0, AiRun::query()->count());
    }

    public function test_queueing_a_run_records_an_internal_only_timeline_event(): void
    {
        Queue::fake();
        $this->fakeAiProvider();

        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $this->enableAutomaticAiRuns($board);

        $ticket = $this->ticketOn($board, $customer);

        $event = TicketEvent::query()
            ->where('ticket_id', $ticket->id)
            ->where('type', TicketEventType::AiRunQueued->value)
            ->sole();

        $this->assertTrue($event->type->isInternalOnly());

        // And the customer who raised the ticket cannot read it.
        $this->assertSame(
            0,
            TicketEvent::query()
                ->readableBy($customer)
                ->where('ticket_id', $ticket->id)
                ->where('type', TicketEventType::AiRunQueued->value)
                ->count()
        );
    }

    public function test_apply_mode_is_refused_when_the_board_has_no_identifiable_repository(): void
    {
        Queue::fake();
        $this->fakeAiProvider();

        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $this->enableAutomaticAiRuns($board, AiRunMode::Apply);

        // Two repositories, neither preferred: nothing to choose without
        // guessing, and guessing which repository to open a pull request
        // against is not a mistake worth making automatically.
        $this->repositoryOn($board, ['repository_name' => 'acme/one']);
        app(ManageBoardRepositories::class)->create($board, [
            'repository_name' => 'acme/two',
        ]);
        $board->repositories()->update(['is_primary' => false]);

        $this->ticketOn($board->refresh(), $customer);

        $this->assertSame(0, AiRun::query()->count());
        Queue::assertNotPushed(ExecuteAiRunJob::class);
    }

    public function test_apply_mode_runs_when_one_repository_is_primary(): void
    {
        Queue::fake();
        $this->fakeAiProvider();

        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $this->enableAutomaticAiRuns($board, AiRunMode::Apply);

        $repository = $this->repositoryOn($board);

        $this->ticketOn($board->refresh(), $customer);

        $run = AiRun::query()->sole();

        $this->assertSame(AiRunMode::Apply, $run->mode);
        $this->assertSame($repository->id, $run->board_repository_id);
        $this->assertSame('acme/platform', $run->repository);
    }

    public function test_ticket_creation_is_not_blocked_by_ai(): void
    {
        // No Queue::fake: the sync driver in the test environment would run the
        // job inline if the dispatch were not deferred, which is exactly the
        // coupling being ruled out here.
        $provider = $this->fakeAiProvider();

        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $this->enableAutomaticAiRuns($board);

        Queue::fake();

        Livewire::actingAs($customer)
            ->test(TicketCreate::class, ['board' => $board])
            ->set('title', 'Please add two more seats')
            ->call('save')
            ->assertHasNoErrors();

        // The ticket exists, a run is queued, and the provider was never called
        // during the request.
        $this->assertSame(1, $board->tickets()->count());
        $this->assertSame(1, AiRun::query()->count());
        $this->assertSame(0, $provider->calls);
    }

    public function test_a_provider_failure_never_prevents_a_ticket_being_created(): void
    {
        // No Queue::fake at all: the sync queue runs the job inline, and a
        // provider that throws must still leave the customer with their ticket.
        $provider = $this->fakeAiProvider();
        $provider->willFail(AiProviderException::rateLimited());

        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $this->enableAutomaticAiRuns($board);

        $ticket = $this->ticketOn($board, $customer, ['title' => 'Still needs to exist']);

        $this->assertTrue($ticket->exists);
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'title' => 'Still needs to exist']);

        // And the failure was recorded rather than lost.
        $this->assertSame(AiRunStatus::Failed, AiRun::query()->sole()->status);
    }

    public function test_a_missing_provider_credential_does_not_create_a_run(): void
    {
        Queue::fake();
        $this->fakeAiProvider();

        // A deployment with AI switched on but no key.
        config(['ai.anthropic.api_key' => null]);

        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $this->enableAutomaticAiRuns($board);

        $this->ticketOn($board, $customer);

        $this->assertSame(0, AiRun::query()->count());
    }
}
