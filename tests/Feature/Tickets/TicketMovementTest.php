<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Actions\Tickets\MoveTicket;
use App\Enums\TicketEventType;
use App\Livewire\Boards\Show as BoardShow;
use App\Livewire\Tickets\Show as TicketShow;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Drag-and-drop persistence.
 *
 * The browser sends (ticket id, new index, destination column id); these tests
 * drive exactly that call, so they exercise the same path a real drop takes.
 */
class TicketMovementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_ticket_can_be_moved_to_another_column(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $progress = $this->columnNamed($board, 'In Progress');

        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(BoardShow::class, ['board' => $board])
            ->call('moveTicket', $ticket->id, 0, $progress->id)
            ->assertHasNoErrors();

        $this->assertSame($progress->id, $ticket->fresh()->board_column_id);
        $this->assertSame(0, $ticket->fresh()->position);
    }

    public function test_tickets_can_be_reordered_within_a_column(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $backlog = $this->columnNamed($board, 'Backlog');

        $a = $this->ticketOn($board, $team, ['title' => 'A']);
        $b = $this->ticketOn($board, $team, ['title' => 'B']);
        $c = $this->ticketOn($board, $team, ['title' => 'C']);

        // Drop C at the top.
        Livewire::actingAs($team)
            ->test(BoardShow::class, ['board' => $board])
            ->call('moveTicket', $c->id, 0, $backlog->id);

        $this->assertSame(
            ['C', 'A', 'B'],
            Ticket::query()->where('board_column_id', $backlog->id)->ordered()->pluck('title')->all()
        );
    }

    public function test_positions_are_left_dense_in_both_columns_after_a_move(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $backlog = $this->columnNamed($board, 'Backlog');
        $todo = $this->columnNamed($board, 'To Do');

        $tickets = collect(['A', 'B', 'C', 'D'])
            ->map(fn (string $title): Ticket => $this->ticketOn($board, $team, ['title' => $title]));

        // Move B out of the middle of Backlog into To Do.
        app(MoveTicket::class)->handle($tickets[1], $todo, 0, $team);

        $backlogPositions = Ticket::query()->where('board_column_id', $backlog->id)
            ->ordered()->pluck('position')->all();

        $todoPositions = Ticket::query()->where('board_column_id', $todo->id)
            ->ordered()->pluck('position')->all();

        $this->assertSame([0, 1, 2], $backlogPositions, 'The gap left behind must be closed.');
        $this->assertSame([0], $todoPositions);
    }

    public function test_an_index_beyond_the_end_of_a_column_is_clamped(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $todo = $this->columnNamed($board, 'To Do');

        $existing = $this->ticketOn($board, $team, ['title' => 'Existing', 'board_column_id' => $todo->id]);
        $moving = $this->ticketOn($board, $team, ['title' => 'Moving']);

        Livewire::actingAs($team)
            ->test(BoardShow::class, ['board' => $board])
            ->call('moveTicket', $moving->id, 999, $todo->id);

        $this->assertSame(
            ['Existing', 'Moving'],
            Ticket::query()->where('board_column_id', $todo->id)->ordered()->pluck('title')->all()
        );
    }

    public function test_moving_between_columns_records_an_event_with_both_ends(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $review = $this->columnNamed($board, 'Review');

        $ticket = $this->ticketOn($board, $team);

        app(MoveTicket::class)->handle($ticket, $review, 0, $team);

        $event = $ticket->events()->where('type', TicketEventType::TicketMoved)->sole();

        $this->assertSame('Backlog', $event->payload['from_column']);
        $this->assertSame('Review', $event->payload['to_column']);
        $this->assertSame($team->id, $event->actor_id);
    }

    public function test_reordering_inside_one_column_does_not_record_a_move_event(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $backlog = $this->columnNamed($board, 'Backlog');

        $a = $this->ticketOn($board, $team, ['title' => 'A']);
        $b = $this->ticketOn($board, $team, ['title' => 'B']);

        app(MoveTicket::class)->handle($b, $backlog, 0, $team);

        $this->assertSame(
            0,
            $b->events()->where('type', TicketEventType::TicketMoved)->count(),
            'Nudging a card within a column is not a workflow transition.'
        );
    }

    public function test_the_status_dropdown_on_the_ticket_page_moves_it_too(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $done = $this->columnNamed($board, 'Done');

        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->set('columnId', (string) $done->id)
            ->assertHasNoErrors();

        $this->assertSame($done->id, $ticket->fresh()->board_column_id);
    }

    public function test_moving_a_ticket_that_does_not_exist_is_a_404(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(BoardShow::class, ['board' => $board])
            ->call('moveTicket', 999999, 0, $this->columnNamed($board, 'Done')->id)
            ->assertNotFound();
    }

    public function test_a_ticket_from_another_board_cannot_be_moved_onto_this_one(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $other = $this->boardWithColumns([$team]);

        $foreignTicket = $this->ticketOn($other, $team);

        Livewire::actingAs($team)
            ->test(BoardShow::class, ['board' => $board])
            ->call('moveTicket', $foreignTicket->id, 0, $this->columnNamed($board, 'Done')->id)
            ->assertNotFound();

        $this->assertSame($other->id, $foreignTicket->fresh()->board_id);
    }
}
