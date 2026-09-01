<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Actions\AI\HandleAiRunFailure;
use App\Actions\AI\ProcessAiResult;
use App\Enums\AiRunMode;
use App\Enums\CommentStream;
use App\Enums\TicketEventType;
use App\Jobs\ExecuteAiRunJob;
use App\Livewire\Tickets\Components\AiRuns as AiRunsPanel;
use App\Livewire\Tickets\Components\Comments;
use App\Livewire\Tickets\Show as TicketShow;
use App\Models\AiRun;
use App\Models\Comment;
use App\Models\TicketEvent;
use App\Services\AI\AiRunReader;
use App\Services\AI\AiRunWorkspace;
use App\Services\AI\ApplyModeRunner;
use App\Services\AI\TicketAnalysisService;
use App\Services\CommentReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The absolute rule of this phase: no AI output ever reaches a customer.
 *
 * Not the analysis, not a code snippet, not a diff, not an error, not a pull
 * request URL — and not the *fact* that a run happened at all. A customer who
 * learns their request was machine-triaged has learnt something internal even if
 * they never read a word of it.
 *
 * Every test here uses a **customer-visible** ticket on a board the customer
 * belongs to, which is the hardest case: the ticket itself is readable, so
 * nothing but the AI-specific rules stands between them and the output.
 *
 * A failure in this file is a release blocker.
 */
class AiVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_cannot_read_the_analysis_note_on_their_own_ticket(): void
    {
        $provider = $this->fakeAiProvider();
        $provider->willReturn('The auth refactor almost certainly broke this. See AuthServiceProvider.');

        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $this->enableAutomaticAiRuns($board, AiRunMode::Suggest);

        $ticket = $this->ticketOn($board, $customer, ['title' => 'Cannot log in']);

        $this->assertTrue($ticket->customer_visible, 'The hard case: the ticket itself is readable.');

        $this->runJobs();

        // Written, and internal.
        $note = Comment::query()->sole();
        $this->assertSame(CommentStream::Internal, $note->stream);

        // Invisible in SQL.
        $this->assertSame(0, Comment::query()->visibleTo($customer)->count());

        // Invisible through the reader.
        $this->assertTrue(
            app(CommentReader::class)
                ->forStream($ticket, $customer, CommentStream::Internal)
                ->isEmpty()
        );

        // Invisible in the count, which would itself be a leak.
        $this->assertArrayNotHasKey(
            CommentStream::Internal->value,
            app(CommentReader::class)->countsByStream($ticket, $customer)
        );
    }

    public function test_the_analysis_text_never_appears_in_a_customers_rendered_ticket_page(): void
    {
        $provider = $this->fakeAiProvider();
        $provider->willReturn('SECRET-ANALYSIS-MARKER: rewrite the billing cron.');

        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $this->enableAutomaticAiRuns($board, AiRunMode::Suggest);

        $ticket = $this->ticketOn($board, $customer);
        $this->runJobs();

        // The whole page, as the customer's browser would receive it.
        Livewire::actingAs($customer)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->assertDontSee('SECRET-ANALYSIS-MARKER');

        Livewire::actingAs($customer)
            ->test(Comments::class, ['ticket' => $ticket])
            ->assertDontSee('SECRET-ANALYSIS-MARKER');

        // And the team can see it, so the test is not passing for the wrong
        // reason.
        Livewire::actingAs($team)
            ->test(Comments::class, ['ticket' => $ticket])
            ->assertSee('SECRET-ANALYSIS-MARKER');
    }

    public function test_a_customer_cannot_read_ai_runs_at_all(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $ticket = $this->ticketOn($board, $customer);

        AiRun::factory()->forTicket($ticket)->manual($team)->completed()->create([
            'pull_request_url' => 'https://github.com/acme/platform/pull/9',
        ]);

        // The scope refuses customers outright rather than filtering rows —
        // there is no flag anybody can set to make one visible.
        $this->assertSame(0, AiRun::query()->visibleTo($customer)->count());
        $this->assertTrue(app(AiRunReader::class)->forTicket($ticket, $customer)->isEmpty());
        $this->assertFalse(app(AiRunReader::class)->hasActiveRun($ticket, $customer));
        $this->assertTrue(app(AiRunReader::class)->forBoard($board, $customer)->isEmpty());

        // Staff on the same board can, so the scope is not simply broken.
        $this->assertSame(1, AiRun::query()->visibleTo($team)->count());
    }

    public function test_a_customer_cannot_open_the_ai_panel_on_a_ticket_they_can_read(): void
    {
        $this->fakeAiProvider();

        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $ticket = $this->ticketOn($board, $customer);

        // 404, not 403: a 403 would confirm the feature is used on their ticket.
        Livewire::actingAs($customer)
            ->test(AiRunsPanel::class, ['ticket' => $ticket])
            ->assertNotFound();
    }

    public function test_a_customer_cannot_trigger_a_run_on_their_own_ticket(): void
    {
        $this->fakeAiProvider();

        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $ticket = $this->ticketOn($board, $customer);

        // For this version, customers do not trigger internal AI runs.
        $this->assertFalse($customer->can('create', [AiRun::class, $ticket, AiRunMode::Suggest]));
        $this->assertFalse($customer->can('create', [AiRun::class, $ticket, AiRunMode::Apply]));
        $this->assertFalse($customer->can('viewAny', [AiRun::class, $ticket]));
    }

    public function test_a_customer_cannot_cancel_or_view_a_run(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $ticket = $this->ticketOn($board, $customer);

        $run = AiRun::factory()->forTicket($ticket)->manual($team)->create();

        $this->assertFalse($customer->can('view', $run));
        $this->assertFalse($customer->can('cancel', $run));

        $this->assertTrue($team->can('view', $run));
        $this->assertTrue($team->can('cancel', $run));
    }

    public function test_a_customer_never_sees_an_ai_timeline_event(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $this->enableAutomaticAiRuns($board, AiRunMode::Suggest);

        $ticket = $this->ticketOn($board, $customer);
        $this->runJobs();

        $aiEventTypes = [
            TicketEventType::AiRunQueued->value,
            TicketEventType::AiRunCompleted->value,
            TicketEventType::AiRunFailed->value,
            TicketEventType::AiRunSkipped->value,
        ];

        // Present in the table…
        $this->assertGreaterThan(
            0,
            TicketEvent::query()->where('ticket_id', $ticket->id)->whereIn('type', $aiEventTypes)->count()
        );

        // …and never in the customer's timeline.
        $this->assertSame(
            0,
            TicketEvent::query()
                ->readableBy($customer)
                ->where('ticket_id', $ticket->id)
                ->whereIn('type', $aiEventTypes)
                ->count()
        );
    }

    public function test_an_apply_mode_pull_request_url_never_reaches_a_customer(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $ticket = $this->ticketOn($board, $customer);

        AiRun::factory()->forTicket($ticket)->manual($team)->apply()->completed()->create([
            'pull_request_url' => 'https://github.com/acme/platform/pull/1234',
            'branch_name' => 'ai/AQD-1-abc',
        ]);

        Livewire::actingAs($customer)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->assertDontSee('pull/1234')
            ->assertDontSee('ai/AQD-1-abc');
    }

    public function test_a_deactivated_staff_member_loses_access_to_ai_runs(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        AiRun::factory()->forTicket($ticket)->manual($team)->create();

        $this->assertSame(1, AiRun::query()->visibleTo($team)->count());

        $team->deactivated_at = now();
        $team->save();

        // canSeeInternalContent() requires an active account, and the scope
        // asks that question rather than asking about the role.
        $this->assertSame(0, AiRun::query()->visibleTo($team->refresh())->count());
    }

    public function test_a_staff_member_of_another_board_cannot_read_this_boards_runs(): void
    {
        $this->fakeAiProvider();

        $mine = $this->teamMember();
        $theirs = $this->teamMember();

        $board = $this->boardWithColumns([$mine]);
        $ticket = $this->ticketOn($board, $mine);

        AiRun::factory()->forTicket($ticket)->manual($mine)->create();

        // Board membership still applies on top of the staff rule.
        $this->assertSame(0, AiRun::query()->visibleTo($theirs)->count());
        $this->assertSame(1, AiRun::query()->visibleTo($mine)->count());
    }

    public function test_an_unauthenticated_reader_sees_no_ai_runs(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        AiRun::factory()->forTicket($ticket)->manual($team)->create();

        $this->assertSame(0, AiRun::query()->visibleTo(null)->count());
    }

    /**
     * Drain the sync queue by executing every queued run.
     *
     * The test environment uses the sync driver, so a run dispatched during
     * ticket creation has already executed by the time control returns. This
     * exists for the cases that create runs another way.
     */
    private function runJobs(): void
    {
        foreach (AiRun::query()->whereIn('status', ['queued'])->pluck('id') as $id) {
            (new ExecuteAiRunJob((int) $id))->handle(
                app(TicketAnalysisService::class),
                app(ApplyModeRunner::class),
                app(ProcessAiResult::class),
                app(HandleAiRunFailure::class),
                app(AiRunWorkspace::class),
            );
        }
    }
}
