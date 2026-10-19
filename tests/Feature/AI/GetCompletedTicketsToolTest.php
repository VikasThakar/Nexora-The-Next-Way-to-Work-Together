<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Actions\Tickets\MoveTicket;
use App\Enums\AiCapabilityMode;
use App\Models\Board;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AI\AiContextScope;
use App\Services\AI\AiSessionManager;
use App\Services\AI\Tools\AiToolContext;
use App\Services\AI\Tools\AiToolOutcome;
use App\Services\AI\Tools\AiToolRegistry;
use App\Services\BoardAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "What did we complete yesterday?" — the question the remote MCP server was
 * built to answer, and so the tool with the most precise definition to keep.
 *
 * Every test builds a history whose dates matter and then asks for a named
 * period, because the failure modes here are all quiet: a day cut in the wrong
 * timezone, a reopened ticket dropped, a ticket finished last month counted
 * because it happens to sit in Done today. Each of those returns a plausible
 * list.
 */
class GetCompletedTicketsToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_the_tickets_moved_to_done_yesterday(): void
    {
        [$team, $board] = $this->teamBoard();

        Carbon::setTestNow('2026-06-01 09:00:00');
        $finished = $this->ticketOn($board, $team, ['title' => 'ALPHA finished']);
        $untouched = $this->ticketOn($board, $team, ['title' => 'BRAVO untouched']);

        $this->doneAt($finished, $board, $team, '2026-06-09 14:00:00');

        Carbon::setTestNow('2026-06-10 12:00:00');

        $outcome = $this->ask($team, ['period' => 'yesterday']);

        $this->assertTrue($outcome->success);
        $this->assertStringContainsString('ALPHA finished', $outcome->text);
        $this->assertStringNotContainsString('BRAVO untouched', $outcome->text);
        $this->assertStringContainsString('Total: 1 completed ticket.', $outcome->text);
        $this->assertStringContainsString('Yesterday (2026-06-09T00:00:00+00:00 to 2026-06-09T23:59:59+00:00, timezone UTC)', $outcome->text);
        $this->assertStringContainsString('completed 2026-06-09T14:00:00+00:00', $outcome->text);
        $this->assertStringContainsString('moved into a column marked as done', $outcome->text);
    }

    public function test_yesterday_is_cut_at_midnight_in_the_reporting_timezone(): void
    {
        config(['workspace.board_defaults.timezone' => 'Europe/Stockholm']);
        [$team, $board] = $this->teamBoard();

        Carbon::setTestNow('2026-10-01 09:00:00');
        $beforeMidnight = $this->ticketOn($board, $team, ['title' => 'ALPHA 23:30 the day before']);
        $afterMidnight = $this->ticketOn($board, $team, ['title' => 'BRAVO 00:30 yesterday']);
        $lateYesterday = $this->ticketOn($board, $team, ['title' => 'CHARLIE 23:30 yesterday']);
        $today = $this->ticketOn($board, $team, ['title' => 'DELTA 00:30 today']);

        // Stockholm is UTC+2 in October before the change.
        $this->doneAt($beforeMidnight, $board, $team, '2026-10-12 21:30:00');
        $this->doneAt($afterMidnight, $board, $team, '2026-10-12 22:30:00');
        $this->doneAt($lateYesterday, $board, $team, '2026-10-13 21:30:00');
        $this->doneAt($today, $board, $team, '2026-10-13 22:30:00');

        Carbon::setTestNow('2026-10-14 10:00:00');

        $outcome = $this->ask($team, ['period' => 'yesterday']);

        $this->assertStringContainsString('BRAVO', $outcome->text);
        $this->assertStringContainsString('CHARLIE', $outcome->text);
        $this->assertStringNotContainsString('ALPHA', $outcome->text);
        $this->assertStringNotContainsString('DELTA', $outcome->text);
        $this->assertStringContainsString('2026-10-13T00:00:00+02:00 to 2026-10-13T23:59:59+02:00, timezone Europe/Stockholm', $outcome->text);
        $this->assertStringContainsString('completed 2026-10-13T00:30:00+02:00', $outcome->text);
    }

    /**
     * 2026-03-29 in Stockholm has 23 hours: the clocks jump from 02:00 to 03:00.
     */
    public function test_yesterday_on_the_spring_forward_day_spans_twenty_three_hours(): void
    {
        config(['workspace.board_defaults.timezone' => 'Europe/Stockholm']);
        [$team, $board] = $this->teamBoard();

        Carbon::setTestNow('2026-03-20 09:00:00');
        $justBefore = $this->ticketOn($board, $team, ['title' => 'ALPHA just before']);
        $firstSecond = $this->ticketOn($board, $team, ['title' => 'BRAVO first second']);
        $lastSecond = $this->ticketOn($board, $team, ['title' => 'CHARLIE last second']);
        $justAfter = $this->ticketOn($board, $team, ['title' => 'DELTA just after']);

        $this->doneAt($justBefore, $board, $team, '2026-03-28 22:59:59');
        $this->doneAt($firstSecond, $board, $team, '2026-03-28 23:00:00');
        $this->doneAt($lastSecond, $board, $team, '2026-03-29 21:59:59');
        $this->doneAt($justAfter, $board, $team, '2026-03-29 22:00:00');

        Carbon::setTestNow('2026-03-30 10:00:00');

        $outcome = $this->ask($team, ['period' => 'yesterday']);

        $this->assertStringContainsString('BRAVO', $outcome->text);
        $this->assertStringContainsString('CHARLIE', $outcome->text);
        $this->assertStringNotContainsString('ALPHA', $outcome->text);
        $this->assertStringNotContainsString('DELTA', $outcome->text);
        $this->assertStringContainsString('2026-03-29T00:00:00+01:00 to 2026-03-29T23:59:59+02:00', $outcome->text);
    }

    /**
     * 2026-10-25 in Stockholm has 25 hours: 02:00–03:00 happens twice.
     */
    public function test_yesterday_on_the_fall_back_day_spans_twenty_five_hours(): void
    {
        config(['workspace.board_defaults.timezone' => 'Europe/Stockholm']);
        [$team, $board] = $this->teamBoard();

        Carbon::setTestNow('2026-10-20 09:00:00');
        $firstSecond = $this->ticketOn($board, $team, ['title' => 'ALPHA first second']);
        $firstHalfPastTwo = $this->ticketOn($board, $team, ['title' => 'BRAVO first 02:30']);
        $secondHalfPastTwo = $this->ticketOn($board, $team, ['title' => 'CHARLIE second 02:30']);
        $lastSecond = $this->ticketOn($board, $team, ['title' => 'DELTA last second']);
        $justAfter = $this->ticketOn($board, $team, ['title' => 'ECHO just after']);

        $this->doneAt($firstSecond, $board, $team, '2026-10-24 22:00:00');
        $this->doneAt($firstHalfPastTwo, $board, $team, '2026-10-25 00:30:00');
        $this->doneAt($secondHalfPastTwo, $board, $team, '2026-10-25 01:30:00');
        $this->doneAt($lastSecond, $board, $team, '2026-10-25 22:59:59');
        $this->doneAt($justAfter, $board, $team, '2026-10-25 23:00:00');

        Carbon::setTestNow('2026-10-26 10:00:00');

        $outcome = $this->ask($team, ['period' => 'yesterday']);

        foreach (['ALPHA', 'BRAVO', 'CHARLIE', 'DELTA'] as $title) {
            $this->assertStringContainsString($title, $outcome->text);
        }

        $this->assertStringNotContainsString('ECHO', $outcome->text);
        $this->assertStringContainsString('Total: 4 completed tickets.', $outcome->text);
        $this->assertStringContainsString('2026-10-25T00:00:00+02:00 to 2026-10-25T23:59:59+01:00', $outcome->text);
    }

    public function test_a_ticket_reopened_since_is_still_listed_and_marked(): void
    {
        [$team, $board] = $this->teamBoard();

        Carbon::setTestNow('2026-06-01 09:00:00');
        $ticket = $this->ticketOn($board, $team, ['title' => 'ALPHA reopened']);

        $this->doneAt($ticket, $board, $team, '2026-06-09 14:00:00');

        Carbon::setTestNow('2026-06-10 08:00:00');
        $this->moveTo($ticket, $board, 'In Progress', $team);

        Carbon::setTestNow('2026-06-10 12:00:00');

        $outcome = $this->ask($team, ['period' => 'yesterday']);

        $this->assertStringContainsString('ALPHA reopened', $outcome->text);
        $this->assertStringContainsString('REOPENED (now in In Progress)', $outcome->text);
    }

    public function test_a_ticket_finished_twice_is_counted_once_at_its_first_finish(): void
    {
        [$team, $board] = $this->teamBoard();

        Carbon::setTestNow('2026-06-01 09:00:00');
        $ticket = $this->ticketOn($board, $team, ['title' => 'ALPHA twice']);

        $this->doneAt($ticket, $board, $team, '2026-06-03 10:00:00');

        Carbon::setTestNow('2026-06-04 10:00:00');
        $this->moveTo($ticket, $board, 'In Progress', $team);

        $this->doneAt($ticket, $board, $team, '2026-06-05 10:00:00');

        Carbon::setTestNow('2026-06-10 12:00:00');

        $outcome = $this->ask($team, ['period' => 'this_month']);

        $this->assertStringContainsString('Total: 1 completed ticket.', $outcome->text);
        $this->assertStringContainsString('completed 2026-06-03T10:00:00+00:00', $outcome->text);
    }

    public function test_a_ticket_already_done_before_the_period_is_not_listed(): void
    {
        [$team, $board] = $this->teamBoard();

        Carbon::setTestNow('2026-05-01 09:00:00');
        $old = $this->ticketOn($board, $team, ['title' => 'ALPHA done in May']);
        $this->doneAt($old, $board, $team, '2026-05-02 10:00:00');

        Carbon::setTestNow('2026-06-10 12:00:00');

        $outcome = $this->ask($team, ['period' => 'yesterday']);

        $this->assertStringNotContainsString('ALPHA', $outcome->text);
        $this->assertStringContainsString('No tickets were completed', $outcome->text);
    }

    public function test_the_board_filter_narrows_and_an_unknown_board_is_not_found(): void
    {
        $team = $this->teamMember();
        $first = $this->boardWithColumns([$team]);
        $second = $this->boardWithColumns([$team]);

        Carbon::setTestNow('2026-06-01 09:00:00');
        $onFirst = $this->ticketOn($first, $team, ['title' => 'ALPHA first board']);
        $onSecond = $this->ticketOn($second, $team, ['title' => 'BRAVO second board']);

        $this->doneAt($onFirst, $first, $team, '2026-06-09 10:00:00');
        $this->doneAt($onSecond, $second, $team, '2026-06-09 11:00:00');

        Carbon::setTestNow('2026-06-10 12:00:00');

        $everywhere = $this->ask($team, ['period' => 'yesterday']);
        $this->assertStringContainsString('ALPHA', $everywhere->text);
        $this->assertStringContainsString('BRAVO', $everywhere->text);

        $narrowed = $this->ask($team, ['period' => 'yesterday', 'board' => $first->slug]);
        $this->assertStringContainsString('ALPHA', $narrowed->text);
        $this->assertStringNotContainsString('BRAVO', $narrowed->text);

        $unknown = $this->ask($team, ['period' => 'yesterday', 'board' => 'no-such-board']);
        $this->assertFalse($unknown->success);
        $this->assertSame('not_found', $unknown->outcome);
    }

    public function test_explicit_dates_win_over_the_period(): void
    {
        [$team, $board] = $this->teamBoard();

        Carbon::setTestNow('2026-06-01 09:00:00');
        $ticket = $this->ticketOn($board, $team, ['title' => 'ALPHA on the 3rd']);
        $this->doneAt($ticket, $board, $team, '2026-06-03 10:00:00');

        Carbon::setTestNow('2026-06-10 12:00:00');

        $outcome = $this->ask($team, ['period' => 'yesterday', 'from' => '2026-06-03', 'to' => '2026-06-03']);

        $this->assertStringContainsString('ALPHA on the 3rd', $outcome->text);
        $this->assertStringNotContainsString('Yesterday (', $outcome->text);
    }

    public function test_the_limit_caps_the_lines_but_not_the_total(): void
    {
        [$team, $board] = $this->teamBoard();

        Carbon::setTestNow('2026-06-01 09:00:00');
        $tickets = [];

        for ($i = 0; $i < 5; $i++) {
            $tickets[] = $this->ticketOn($board, $team, ['title' => 'Ticket '.$i]);
        }

        foreach ($tickets as $i => $ticket) {
            $this->doneAt($ticket, $board, $team, '2026-06-09 1'.$i.':00:00');
        }

        Carbon::setTestNow('2026-06-10 12:00:00');

        $outcome = $this->ask($team, ['period' => 'yesterday', 'limit' => 2]);

        $this->assertStringContainsString('Total: 5 completed tickets, showing the 2 most recently completed.', $outcome->text);
        $this->assertSame(2, substr_count($outcome->text, "\n- "));
        // Newest first.
        $this->assertStringContainsString('Ticket 4', $outcome->text);
        $this->assertStringContainsString('Ticket 3', $outcome->text);
    }

    public function test_a_customer_is_offered_it_and_sees_only_shared_tickets(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        Carbon::setTestNow('2026-06-01 09:00:00');
        $shared = $this->ticketOn($board, $team, ['title' => 'OTTER shared', 'customer_visible' => true]);
        $internal = $this->ticketOn($board, $team, ['title' => 'ZEBRA internal']);

        $this->doneAt($shared, $board, $team, '2026-06-09 10:00:00');
        $this->doneAt($internal, $board, $team, '2026-06-09 11:00:00');

        Carbon::setTestNow('2026-06-10 12:00:00');

        $this->assertArrayHasKey(
            'get_completed_tickets',
            app(AiToolRegistry::class)->availableFor($this->contextFor($customer)),
        );

        $outcome = $this->ask($customer, ['period' => 'yesterday']);

        $this->assertStringContainsString('OTTER shared', $outcome->text);
        $this->assertStringNotContainsString('ZEBRA', $outcome->text);
        $this->assertStringContainsString('Total: 1 completed ticket.', $outcome->text);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------

    /**
     * @return array{0: User, 1: Board}
     */
    private function teamBoard(): array
    {
        $team = $this->teamMember();

        return [$team, $this->boardWithColumns([$team])];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function ask(User $user, array $input): AiToolOutcome
    {
        return app(AiToolRegistry::class)->invoke('get_completed_tickets', $input, $this->contextFor($user));
    }

    private function contextFor(User $user): AiToolContext
    {
        $scope = AiContextScope::workspace();

        return new AiToolContext(
            user: $user,
            scope: $scope,
            session: app(AiSessionManager::class)->start($scope, $user),
            mode: AiCapabilityMode::Agent,
            staff: app(BoardAccess::class)->canSeeInternalContent($user),
        );
    }

    private function doneAt(Ticket $ticket, Board $board, User $actor, string $utc): void
    {
        Carbon::setTestNow($utc);

        $this->moveTo($ticket, $board, 'Done', $actor);
    }

    private function moveTo(Ticket $ticket, Board $board, string $column, User $actor): void
    {
        app(MoveTicket::class)->handle($ticket->refresh(), $this->columnNamed($board, $column), 0, $actor);
    }
}
