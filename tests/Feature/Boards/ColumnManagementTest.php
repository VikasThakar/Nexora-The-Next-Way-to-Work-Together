<?php

declare(strict_types=1);

namespace Tests\Feature\Boards;

use App\Actions\Columns\DeleteColumn;
use App\Enums\TicketEventType;
use App\Livewire\Boards\Settings as BoardSettings;
use App\Models\BoardColumn;
use App\Models\Ticket;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class ColumnManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_board_member_can_add_a_column(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(BoardSettings::class, ['board' => $board])
            ->set('newColumnName', 'Blocked')
            ->call('addColumn')
            ->assertHasNoErrors();

        $column = $board->columns()->where('name', 'Blocked')->sole();

        $this->assertSame(5, $column->position, 'A new column belongs at the end.');
    }

    public function test_column_names_must_be_unique_on_a_board(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(BoardSettings::class, ['board' => $board])
            ->set('newColumnName', 'Backlog')
            ->call('addColumn')
            ->assertHasErrors('newColumnName');

        $this->assertSame(5, $board->columns()->count());
    }

    public function test_the_same_column_name_may_be_used_on_a_different_board(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $other = $this->boardWithColumns([$team]);

        $this->assertSame('Backlog', $this->columnNamed($board, 'Backlog')->name);
        $this->assertSame('Backlog', $this->columnNamed($other, 'Backlog')->name);
    }

    public function test_a_column_can_be_renamed(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $column = $this->columnNamed($board, 'Review');

        Livewire::actingAs($team)
            ->test(BoardSettings::class, ['board' => $board])
            ->call('startEditingColumn', $column->id)
            ->set('editingColumnName', 'Code review')
            ->call('saveColumn')
            ->assertHasNoErrors();

        $this->assertSame('Code review', $column->fresh()->name);
    }

    public function test_columns_can_be_reordered(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $done = $this->columnNamed($board, 'Done');

        Livewire::actingAs($team)
            ->test(BoardSettings::class, ['board' => $board])
            ->call('reorderColumn', $done->id, 0);

        $this->assertSame(
            ['Done', 'Backlog', 'To Do', 'In Progress', 'Review'],
            $board->columns()->ordered()->pluck('name')->all()
        );
    }

    public function test_a_column_from_another_board_cannot_be_reordered(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $other = $this->boardWithColumns([$team]);
        $foreign = $this->columnNamed($other, 'Done');

        Livewire::actingAs($team)
            ->test(BoardSettings::class, ['board' => $board])
            ->call('reorderColumn', $foreign->id, 0)
            ->assertNotFound();
    }

    public function test_the_done_column_marker_can_be_moved(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $review = $this->columnNamed($board, 'Review');

        Livewire::actingAs($team)
            ->test(BoardSettings::class, ['board' => $board])
            ->call('toggleDoneColumn', $review->id);

        $this->assertTrue($review->fresh()->is_done);
    }

    public function test_an_empty_column_can_be_deleted_outright(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $review = $this->columnNamed($board, 'Review');

        Livewire::actingAs($team)
            ->test(BoardSettings::class, ['board' => $board])
            ->call('startDeletingColumn', $review->id)
            ->call('confirmDeleteColumn');

        $this->assertNull(BoardColumn::query()->find($review->id));
        $this->assertSame(4, $board->columns()->count());
    }

    public function test_deleting_a_column_with_tickets_moves_them_instead_of_destroying_them(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $review = $this->columnNamed($board, 'Review');
        $done = $this->columnNamed($board, 'Done');

        $first = $this->ticketOn($board, $team, ['title' => 'One', 'board_column_id' => $review->id]);
        $second = $this->ticketOn($board, $team, ['title' => 'Two', 'board_column_id' => $review->id]);

        Livewire::actingAs($team)
            ->test(BoardSettings::class, ['board' => $board])
            ->call('startDeletingColumn', $review->id)
            ->set('destinationColumnId', $done->id)
            ->call('confirmDeleteColumn');

        $this->assertNull(BoardColumn::query()->find($review->id));
        $this->assertSame(2, Ticket::query()->count(), 'Tickets must survive their column.');
        $this->assertSame($done->id, $first->fresh()->board_column_id);
        $this->assertSame($done->id, $second->fresh()->board_column_id);
    }

    public function test_moving_tickets_out_of_a_deleted_column_is_recorded_in_their_history(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $review = $this->columnNamed($board, 'Review');
        $done = $this->columnNamed($board, 'Done');

        $ticket = $this->ticketOn($board, $team, ['board_column_id' => $review->id]);

        app(DeleteColumn::class)->handle($review, $done, $team);

        $event = $ticket->events()->where('type', TicketEventType::TicketMoved)->sole();

        $this->assertSame('Review', $event->payload['from_column']);
        $this->assertSame('Done', $event->payload['to_column']);
        $this->assertSame('column_deleted', $event->payload['reason']);
    }

    public function test_deleting_a_column_holding_tickets_without_a_destination_is_refused(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $review = $this->columnNamed($board, 'Review');

        $this->ticketOn($board, $team, ['board_column_id' => $review->id]);

        $this->expectException(RuntimeException::class);

        try {
            app(DeleteColumn::class)->handle($review, null, $team);
        } finally {
            $this->assertNotNull(BoardColumn::query()->find($review->id));
            $this->assertSame(1, Ticket::query()->count());
        }
    }

    public function test_tickets_cannot_be_moved_to_a_column_on_another_board_while_deleting(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $other = $this->boardWithColumns([$team]);
        $review = $this->columnNamed($board, 'Review');

        $this->ticketOn($board, $team, ['board_column_id' => $review->id]);

        $this->expectException(RuntimeException::class);

        try {
            app(DeleteColumn::class)->handle($review, $this->columnNamed($other, 'Done'), $team);
        } finally {
            $this->assertNotNull(BoardColumn::query()->find($review->id));
        }
    }

    public function test_the_last_column_on_a_board_cannot_be_deleted(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithMembers([$team]);
        $only = $board->columns()->create(['name' => 'Only', 'position' => 0]);

        Livewire::actingAs($team)
            ->test(BoardSettings::class, ['board' => $board])
            ->call('startDeletingColumn', $only->id);

        $this->assertNotNull(BoardColumn::query()->find($only->id));
    }

    public function test_the_database_refuses_to_delete_a_column_that_still_holds_tickets(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $review = $this->columnNamed($board, 'Review');

        $this->ticketOn($board, $team, ['board_column_id' => $review->id]);

        // The last line of defence: even bypassing the action entirely, the
        // foreign key refuses rather than cascading the tickets away.
        $this->expectException(QueryException::class);

        try {
            $review->delete();
        } finally {
            $this->assertSame(1, Ticket::query()->count());
        }
    }
}
