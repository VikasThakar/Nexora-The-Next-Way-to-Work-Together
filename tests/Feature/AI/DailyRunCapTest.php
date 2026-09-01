<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Actions\AI\CreateAiRun;
use App\Actions\AI\Exceptions\AiRunRefused;
use App\Enums\AiRunMode;
use App\Enums\AiRunStatus;
use App\Enums\AiRunTrigger;
use App\Enums\TicketEventType;
use App\Jobs\ExecuteAiRunJob;
use App\Models\AiRun;
use App\Models\TicketEvent;
use App\Services\AI\AiRunCap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The daily cap: the only thing standing between automation and a runaway bill.
 *
 * The asymmetry between automatic and manual runs is the design, so it is what
 * these tests pin: automatic runs are capped absolutely and nobody bypasses
 * them, because nobody chose them.
 */
class DailyRunCapTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_automatic_run_is_refused_once_the_boards_cap_is_reached(): void
    {
        Queue::fake();
        $this->fakeAiProvider();

        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $this->enableAutomaticAiRuns($board, AiRunMode::Suggest, dailyCap: 2);

        $this->ticketOn($board, $customer, ['title' => 'One']);
        $this->ticketOn($board, $customer, ['title' => 'Two']);
        $this->ticketOn($board, $customer, ['title' => 'Three']);

        // Three tickets, two runs. All three tickets exist.
        $this->assertSame(3, $board->tickets()->count());
        $this->assertSame(2, AiRun::query()->count());
    }

    public function test_the_default_cap_is_twenty(): void
    {
        $board = $this->boardWithColumns();

        // Configurable, but this is the documented default.
        $this->assertSame(20, app(AiRunCap::class)->automaticLimit($board));
        $this->assertSame(20, (int) config('ai.caps.daily_auto_runs'));
    }

    public function test_a_blocked_automatic_run_records_a_meaningful_event(): void
    {
        Queue::fake();
        $this->fakeAiProvider();

        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $this->enableAutomaticAiRuns($board, AiRunMode::Suggest, dailyCap: 0);

        $ticket = $this->ticketOn($board, $customer);

        $event = TicketEvent::query()
            ->where('ticket_id', $ticket->id)
            ->where('type', TicketEventType::AiRunSkipped->value)
            ->sole();

        // The team can see that the cap bit, rather than wondering why nothing
        // happened — and the customer cannot.
        $this->assertNotNull($event->payload['reason'] ?? null);
        $this->assertSame(0, $event->payload['cap']['limit']);
        $this->assertTrue($event->type->isInternalOnly());
    }

    public function test_a_cap_of_zero_stops_automation_without_losing_the_settings(): void
    {
        Queue::fake();
        $this->fakeAiProvider();

        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $this->enableAutomaticAiRuns($board, AiRunMode::Apply, dailyCap: 0);
        $this->repositoryOn($board);

        $this->ticketOn($board->refresh(), $customer);

        $this->assertSame(0, AiRun::query()->count());

        // The mode is still there, so raising the cap tomorrow resumes exactly
        // what was configured.
        $this->assertSame(AiRunMode::Apply, $board->refresh()->aiSettings()->autoRunMode);
    }

    public function test_a_cancelled_run_does_not_count_against_the_cap(): void
    {
        Queue::fake();
        $this->fakeAiProvider();

        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $ticket = $this->ticketOn($board, $customer);

        AiRun::factory()->forTicket($ticket)->automatic()->create([
            'status' => AiRunStatus::Cancelled,
        ]);

        // It never reached the provider, so holding it against the day would
        // punish somebody for changing their mind.
        $this->assertSame(0, app(AiRunCap::class)->usedToday($board, AiRunTrigger::Automatic));
    }

    public function test_a_failed_run_does_count_against_the_cap(): void
    {
        Queue::fake();
        $this->fakeAiProvider();

        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $ticket = $this->ticketOn($board, $customer);

        AiRun::factory()->forTicket($ticket)->automatic()->failed()->create();

        // It cost tokens. Not counting it would make the cap bypassable by
        // arranging to fail.
        $this->assertSame(1, app(AiRunCap::class)->usedToday($board, AiRunTrigger::Automatic));
    }

    public function test_runs_from_yesterday_do_not_count(): void
    {
        Queue::fake();
        $this->fakeAiProvider();

        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $ticket = $this->ticketOn($board, $customer);

        AiRun::factory()->forTicket($ticket)->automatic()->completed()->create([
            'created_at' => now()->subDay(),
        ]);

        $this->assertSame(0, app(AiRunCap::class)->usedToday($board, AiRunTrigger::Automatic));
    }

    public function test_the_cap_is_per_board(): void
    {
        Queue::fake();
        $this->fakeAiProvider();

        $customer = $this->customer();
        $quiet = $this->boardWithColumns([$customer]);
        $busy = $this->boardWithColumns([$customer]);

        $this->enableAutomaticAiRuns($quiet, AiRunMode::Suggest, dailyCap: 1);
        $this->enableAutomaticAiRuns($busy, AiRunMode::Suggest, dailyCap: 1);

        $busyTicket = $this->ticketOn($busy, $customer);
        AiRun::factory()->forTicket($busyTicket)->automatic()->completed()->count(5)->create();

        // The busy board is over its cap; the quiet one is unaffected.
        $this->ticketOn($quiet, $customer);
        $this->assertSame(1, $quiet->aiRuns()->count());

        $this->ticketOn($busy, $customer);
        $this->assertSame(6, $busy->aiRuns()->count());
    }

    public function test_an_administrator_cannot_bypass_the_automatic_cap(): void
    {
        Queue::fake();
        $this->fakeAiProvider();

        $admin = $this->admin();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer, $admin]);
        $this->enableAutomaticAiRuns($board, AiRunMode::Suggest, dailyCap: 0);

        $ticket = $this->ticketOn($board, $customer);

        // Even asked for explicitly, by an administrator: the cap on automatic
        // runs has no bypass, because nobody chose those runs.
        $this->expectException(AiRunRefused::class);

        app(CreateAiRun::class)->handle($ticket, AiRunMode::Suggest, AiRunTrigger::Automatic, $admin);
    }

    public function test_a_manual_run_is_not_blocked_by_the_automatic_cap(): void
    {
        Queue::fake();
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $this->enableAutomaticAiRuns($board, AiRunMode::Suggest, dailyCap: 0);

        $ticket = $this->ticketOn($board, $customer);

        // A member of staff pressing a button knows what it costs.
        $run = app(CreateAiRun::class)->handle($ticket, AiRunMode::Suggest, AiRunTrigger::Manual, $team);

        $this->assertSame(AiRunTrigger::Manual, $run->trigger_source);
        Queue::assertPushed(ExecuteAiRunJob::class);
    }

    public function test_a_manual_run_is_blocked_by_its_own_cap(): void
    {
        Queue::fake();
        $this->fakeAiProvider();
        config([
            'ai.caps.daily_manual_runs' => 1,
            'ai.caps.admins_bypass_manual_cap' => true,
        ]);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        AiRun::factory()->forTicket($ticket)->manual($team)->completed()->create();

        $this->expectException(AiRunRefused::class);

        app(CreateAiRun::class)->handle($ticket, AiRunMode::Suggest, AiRunTrigger::Manual, $team);
    }

    public function test_an_administrator_may_bypass_the_manual_cap_when_configured(): void
    {
        Queue::fake();
        $this->fakeAiProvider();
        config([
            'ai.caps.daily_manual_runs' => 1,
            'ai.caps.admins_bypass_manual_cap' => true,
        ]);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $ticket = $this->ticketOn($board, $admin);

        AiRun::factory()->forTicket($ticket)->manual($admin)->completed()->create();

        $run = app(CreateAiRun::class)->handle($ticket, AiRunMode::Suggest, AiRunTrigger::Manual, $admin);

        // Recorded as a bypass, so the exception to the rule is visible.
        $this->assertTrue((bool) $run->meta('cap.bypassed'));
    }

    public function test_an_administrator_cannot_bypass_the_manual_cap_when_the_deployment_forbids_it(): void
    {
        Queue::fake();
        $this->fakeAiProvider();
        config([
            'ai.caps.daily_manual_runs' => 1,
            'ai.caps.admins_bypass_manual_cap' => false,
        ]);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $ticket = $this->ticketOn($board, $admin);

        AiRun::factory()->forTicket($ticket)->manual($admin)->completed()->create();

        $this->expectException(AiRunRefused::class);

        app(CreateAiRun::class)->handle($ticket, AiRunMode::Suggest, AiRunTrigger::Manual, $admin);
    }

    public function test_a_board_cap_is_clamped_to_the_deployment_ceiling(): void
    {
        config(['ai.caps.max_daily_auto_runs' => 50]);

        $board = $this->boardWithColumns();

        $this->enableAutomaticAiRuns($board, AiRunMode::Suggest, dailyCap: 9999);

        // A hand-edited or crafted value cannot buy a board unlimited spend.
        $this->assertSame(50, $board->refresh()->aiSettings()->dailyAutoRunCap);
    }
}
