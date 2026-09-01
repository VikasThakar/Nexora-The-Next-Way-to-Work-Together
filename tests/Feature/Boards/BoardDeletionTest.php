<?php

declare(strict_types=1);

namespace Tests\Feature\Boards;

use App\Actions\Boards\DeleteBoard;
use App\Livewire\Boards\ManageBoard;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Label;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketSubtask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BoardDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_administrator_can_delete_a_board_and_everything_on_it(): void
    {
        $admin = $this->admin();
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $label = $board->labels()->create(['name' => 'bug', 'color' => 'rose']);

        $ticket = $this->ticketOn($board, $team, ['label_ids' => [$label->id]]);
        $ticket->subtasks()->create(['title' => 'A step', 'position' => 0]);

        Livewire::actingAs($admin)
            ->test(ManageBoard::class, ['board' => $board])
            ->call('confirmDelete')
            ->set('deleteConfirmation', $board->name)
            ->call('destroyBoard')
            ->assertRedirect(route('boards.index'));

        $this->assertNull(Board::query()->find($board->id));
        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(0, BoardColumn::query()->count());
        $this->assertSame(0, Label::query()->count());
        $this->assertSame(0, TicketSubtask::query()->count());
        $this->assertSame(0, TicketEvent::query()->count());
    }

    public function test_deletion_requires_the_board_name_to_be_typed_exactly(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([], ['name' => 'Precious Board']);

        Livewire::actingAs($admin)
            ->test(ManageBoard::class, ['board' => $board])
            ->call('confirmDelete')
            ->set('deleteConfirmation', 'precious board')
            ->call('destroyBoard')
            ->assertHasErrors('deleteConfirmation');

        $this->assertNotNull(Board::query()->find($board->id));
    }

    public function test_a_team_member_cannot_delete_a_board_they_belong_to(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->assertFalse($team->can('delete', $board));

        Livewire::actingAs($team)
            ->test(ManageBoard::class, ['board' => $board])
            ->assertForbidden();

        $this->assertNotNull(Board::query()->find($board->id));
    }

    public function test_deleting_one_board_leaves_other_boards_untouched(): void
    {
        $admin = $this->admin();
        $team = $this->teamMember();

        $doomed = $this->boardWithColumns([$team]);
        $survivor = $this->boardWithColumns([$team]);

        $this->ticketOn($doomed, $team);
        $keeper = $this->ticketOn($survivor, $team);

        app(DeleteBoard::class)->handle($doomed);

        $this->assertNull(Board::query()->find($doomed->id));
        $this->assertNotNull(Board::query()->find($survivor->id));
        $this->assertNotNull(Ticket::query()->find($keeper->id));
        $this->assertSame(5, $survivor->columns()->count());
    }
}
