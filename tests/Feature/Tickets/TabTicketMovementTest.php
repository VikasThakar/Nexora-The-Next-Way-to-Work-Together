<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Actions\Columns\DeleteColumn;
use App\Actions\Tabs\DeleteTab;
use App\Actions\Tickets\CreateTicket;
use App\Actions\Tickets\MoveTicket;
use App\Actions\Tickets\MoveTicketToTab;
use App\Enums\TicketEventType;
use App\Livewire\Boards\Show as BoardShow;
use App\Models\BoardColumn;
use App\Models\TicketEvent;
use App\Services\Statistics\FlowMetrics;
use App\Services\Statistics\StatisticsScope;
use App\Support\StatsPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Where tickets go once a board has tabs: a ticket lives in one tab (its
 * column's), moving it to another tab is always explicit, and new tickets land
 * in the tab they were asked for.
 */
class TabTicketMovementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_column_move_never_carries_a_ticket_into_another_tab_on_its_own(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');
        $ticket = $this->ticketOn($board, $team);

        try {
            app(MoveTicket::class)->handle($ticket, $this->columnNamed($board, 'Done', $website), 0, $team);
            $this->fail('A move into another tab\'s column should have been refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('another tab', $exception->getMessage());
        }

        $this->assertSame($this->columnNamed($board, 'Backlog', $this->defaultTab($board))->id, $ticket->fresh()->board_column_id);
    }

    public function test_moving_to_another_tab_lands_in_the_matching_column_and_says_so_in_the_history(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $main = $this->defaultTab($board);
        $website = $this->tabOn($board, 'Website');

        $ticket = $this->ticketOn($board, $team, ['board_column_id' => $this->columnNamed($board, 'In Progress', $main)->id]);

        $landed = app(MoveTicketToTab::class)->handle($ticket, $website, $team);

        $this->assertSame('In Progress', $landed->name);
        $this->assertTrue($landed->tab->is($website));
        $this->assertSame($landed->id, $ticket->fresh()->board_column_id);

        $event = TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', TicketEventType::TicketMoved->value)->sole();

        $this->assertSame('Main', $event->payload['from_tab']);
        $this->assertSame('Website', $event->payload['to_tab']);
    }

    public function test_a_done_ticket_moved_to_a_tab_without_that_column_stays_done(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');
        $this->columnNamed($board, 'Done', $website)->update(['name' => 'Live']);

        $ticket = $this->ticketOn($board, $team, ['board_column_id' => $this->columnNamed($board, 'Done')->id]);

        $landed = app(MoveTicketToTab::class)->handle($ticket, $website, $team);

        $this->assertSame('Live', $landed->name);
        $this->assertTrue($landed->is_done);
    }

    public function test_a_move_to_another_tab_with_a_column_must_name_a_column_of_that_tab(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');
        $ticket = $this->ticketOn($board, $team);

        $landed = app(MoveTicketToTab::class)->handle($ticket, $website, $team, $this->columnNamed($board, 'Review', $website));
        $this->assertSame('Review', $landed->name);

        $this->expectException(RuntimeException::class);

        app(MoveTicketToTab::class)->handle($ticket, $this->defaultTab($board), $team, $this->columnNamed($board, 'Done', $website));
    }

    public function test_a_tab_of_another_board_is_refused_and_the_same_tab_changes_nothing(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $other = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $same = app(MoveTicketToTab::class)->handle($ticket, $this->defaultTab($board), $team);
        $this->assertSame($ticket->board_column_id, $same->id);
        $this->assertSame(0, TicketEvent::query()->where('type', TicketEventType::TicketMoved->value)->count());

        $this->expectException(RuntimeException::class);

        app(MoveTicketToTab::class)->handle($ticket, $this->defaultTab($other), $team);
    }

    public function test_a_new_ticket_lands_in_the_tab_it_was_asked_for(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $other = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');

        // A tab and nothing else: that tab's first column.
        $inTab = $this->ticketOn($board, $team, ['board_tab_id' => $website->id]);
        $this->assertSame($this->columnNamed($board, 'Backlog', $website)->id, $inTab->board_column_id);

        // A column and nothing else: that column, and so its tab.
        $byColumn = $this->ticketOn($board, $team, ['board_column_id' => $this->columnNamed($board, 'Review', $website)->id]);
        $this->assertSame($this->columnNamed($board, 'Review', $website)->id, $byColumn->board_column_id);

        // A column outside the tab asked for: the tab wins, at its first column.
        $mismatch = $this->ticketOn($board, $team, [
            'board_tab_id' => $website->id,
            'board_column_id' => $this->columnNamed($board, 'Done', $this->defaultTab($board))->id,
        ]);
        $this->assertSame($this->columnNamed($board, 'Backlog', $website)->id, $mismatch->board_column_id);

        // Another board's tab: ignored, so the board's own default.
        $foreign = $this->ticketOn($board, $team, ['board_tab_id' => $this->defaultTab($other)->id]);
        $this->assertSame($this->columnNamed($board, 'Backlog', $this->defaultTab($board))->id, $foreign->board_column_id);

        $created = TicketEvent::query()->where('ticket_id', $inTab->id)->where('type', TicketEventType::TicketCreated->value)->sole();
        $this->assertSame('Website', $created->payload['tab']);
    }

    public function test_a_customer_request_starts_in_the_first_column_of_the_tab_they_raised_it_in(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $website = $this->tabOn($board, 'Website');

        // As quick add sends it: only the column the "+" was clicked on.
        $ticket = $this->ticketOn($board, $customer, ['board_column_id' => $this->columnNamed($board, 'Review', $website)->id]);

        $this->assertSame($this->columnNamed($board, 'Backlog', $website)->id, $ticket->board_column_id);
        $this->assertTrue($ticket->customer_visible);
    }

    public function test_quick_add_on_a_tab_files_the_ticket_in_that_tab(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');
        $review = $this->columnNamed($board, 'Review', $website);

        Livewire::actingAs($team)
            ->withQueryParams(['tab' => $website->slug])
            ->test(BoardShow::class, ['board' => $board])
            ->call('startQuickAdd', $review->id)
            ->set('quickAddTitle', 'Filed on the website tab')
            ->call('quickAdd')
            ->assertHasNoErrors();

        $this->assertSame($review->id, $board->tickets()->where('title', 'Filed on the website tab')->value('board_column_id'));
    }

    public function test_an_empty_tab_refuses_a_ticket_with_a_clear_message(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');
        $website->columns()->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Website tab has no columns');

        app(CreateTicket::class)->handle($board, ['title' => 'Nowhere to go', 'board_tab_id' => $website->id], $team);
    }

    public function test_deleting_a_column_keeps_its_tickets_in_the_same_tab(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');
        $review = $this->columnNamed($board, 'Review', $website);

        $this->ticketOn($board, $team, ['board_column_id' => $review->id]);

        try {
            app(DeleteColumn::class)->handle($review, $this->columnNamed($board, 'Done', $this->defaultTab($board)), $team);
            $this->fail('A destination in another tab should have been refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('same tab', $exception->getMessage());
        }

        app(DeleteColumn::class)->handle($review, $this->columnNamed($board, 'Done', $website), $team);

        $this->assertNull(BoardColumn::query()->find($review->id));
        $this->assertSame(1, $this->columnNamed($board, 'Done', $website)->tickets()->count());
    }

    public function test_a_tab_keeps_at_least_one_column(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');

        $columns = $website->columns()->get();

        foreach ($columns->slice(1) as $column) {
            app(DeleteColumn::class)->handle($column, null, $team);
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A tab needs at least one column.');

        app(DeleteColumn::class)->handle($columns->first(), null, $team);
    }

    public function test_tidying_a_board_does_not_count_as_finishing_work_again(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');

        Carbon::setTestNow('2026-06-01 09:00:00');
        $ticket = $this->ticketOn($board, $team, ['board_tab_id' => $website->id]);

        Carbon::setTestNow('2026-06-02 09:00:00');
        app(MoveTicket::class)->handle($ticket, $this->columnNamed($board, 'Done', $website), 0, $team);

        // The tab goes; the finished ticket is carried into Main's Done.
        Carbon::setTestNow('2026-06-05 09:00:00');
        app(DeleteTab::class)->handle($website, $this->defaultTab($board), $team);

        Carbon::setTestNow('2026-06-10 12:00:00');

        $closures = app(FlowMetrics::class)->closures(
            StatisticsScope::for($team, StatsPeriod::preset(StatsPeriod::LAST_30_DAYS, 'UTC'))
        );

        $this->assertSame(1, $closures->count());
        $this->assertSame('2026-06-02', $closures->first()->toDateString());
    }
}
