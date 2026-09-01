<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Livewire\Tickets\Components\Subtasks;
use App\Models\TicketSubtask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TicketSubtasksTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_subtask_can_be_added_edited_completed_and_removed(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $component = Livewire::actingAs($team)->test(Subtasks::class, ['ticket' => $ticket]);

        $component->set('newTitle', 'Write the migration')->call('add')->assertHasNoErrors();

        $subtask = $ticket->subtasks()->sole();
        $this->assertFalse($subtask->completed);

        $component->call('toggle', $subtask->id);
        $this->assertTrue($subtask->fresh()->completed);
        $this->assertSame($team->id, $subtask->fresh()->completed_by_id);
        $this->assertNotNull($subtask->fresh()->completed_at);

        $component->call('toggle', $subtask->id);
        $this->assertFalse($subtask->fresh()->completed);
        $this->assertNull($subtask->fresh()->completed_at);

        $component->call('startEditing', $subtask->id)
            ->set('editingTitle', 'Write and test the migration')
            ->call('saveEditing');
        $this->assertSame('Write and test the migration', $subtask->fresh()->title);

        $component->call('remove', $subtask->id);
        $this->assertSame(0, $ticket->subtasks()->count());
    }

    public function test_subtasks_are_appended_in_order_and_can_be_reordered(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $component = Livewire::actingAs($team)->test(Subtasks::class, ['ticket' => $ticket]);

        foreach (['One', 'Two', 'Three'] as $title) {
            $component->set('newTitle', $title)->call('add');
        }

        $this->assertSame(['One', 'Two', 'Three'], $ticket->subtasks()->pluck('title')->all());

        $third = $ticket->subtasks()->where('title', 'Three')->sole();

        $component->call('reorder', $third->id, 0);

        $this->assertSame(['Three', 'One', 'Two'], $ticket->fresh()->subtasks()->pluck('title')->all());
    }

    public function test_a_subtask_requires_a_title(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(Subtasks::class, ['ticket' => $ticket])
            ->set('newTitle', '')
            ->call('add')
            ->assertHasErrors('newTitle');

        $this->assertSame(0, $ticket->subtasks()->count());
    }

    public function test_a_subtask_belonging_to_another_ticket_cannot_be_touched(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $mine = $this->ticketOn($board, $team);
        $theirs = $this->ticketOn($board, $team);

        $foreign = $theirs->subtasks()->create(['title' => 'Not yours', 'position' => 0]);

        Livewire::actingAs($team)
            ->test(Subtasks::class, ['ticket' => $mine])
            ->call('toggle', $foreign->id)
            ->assertNotFound();

        $this->assertFalse($foreign->fresh()->completed);
    }

    public function test_subtasks_are_removed_with_their_ticket(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $ticket->subtasks()->create(['title' => 'Something', 'position' => 0]);

        $ticket->delete();

        $this->assertSame(0, TicketSubtask::query()->count());
    }

    public function test_the_board_card_shows_subtask_progress(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'With a checklist']);

        $ticket->subtasks()->create(['title' => 'A', 'position' => 0, 'completed' => true]);
        $ticket->subtasks()->create(['title' => 'B', 'position' => 1]);

        $this->actingAs($team)
            ->get(route('boards.show', $board))
            ->assertOk()
            ->assertSee('1/2');
    }
}
