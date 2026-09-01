<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Enums\TicketEventType;
use App\Enums\TicketPriority;
use App\Livewire\Boards\Show as BoardShow;
use App\Livewire\Tickets\Create as TicketCreate;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TicketCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_board_starts_with_the_default_columns(): void
    {
        $board = $this->boardWithColumns();

        $this->assertSame(
            ['Backlog', 'To Do', 'In Progress', 'Review', 'Done'],
            $board->columns()->ordered()->pluck('name')->all()
        );

        $this->assertTrue($this->columnNamed($board, 'Done')->is_done);
    }

    public function test_a_team_member_can_create_a_ticket_with_the_full_field_set(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $review = $this->columnNamed($board, 'Review');
        $label = $board->labels()->create(['name' => 'bug', 'color' => 'rose']);

        Livewire::actingAs($team)
            ->test(TicketCreate::class, ['board' => $board])
            ->set('title', 'Fix the importer')
            ->set('descriptionMd', '## Steps')
            ->set('priority', TicketPriority::High->value)
            ->set('columnId', (string) $review->id)
            ->set('assigneeId', (string) $team->id)
            ->set('estimate', '3.5')
            ->set('dueDate', '2026-12-01')
            ->set('customerVisible', true)
            ->set('selectedLabelIds', [$label->id])
            ->call('save')
            ->assertHasNoErrors();

        $ticket = Ticket::query()->where('title', 'Fix the importer')->sole();

        $this->assertSame($review->id, $ticket->board_column_id);
        $this->assertSame(TicketPriority::High, $ticket->priority);
        $this->assertSame($team->id, $ticket->assignee_id);
        $this->assertSame($team->id, $ticket->created_by_id);
        $this->assertSame('3.50', $ticket->estimate);
        $this->assertTrue($ticket->customer_visible);
        $this->assertEquals([$label->id], $ticket->labels()->pluck('labels.id')->all());
    }

    public function test_a_ticket_created_by_staff_is_internal_unless_asked_otherwise(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team, ['title' => 'Quiet work']);

        $this->assertFalse($ticket->customer_visible, 'Work must default to internal, not exposed.');
    }

    public function test_ticket_numbers_are_sequential_per_board_and_form_the_key(): void
    {
        $team = $this->teamMember();
        $first = $this->boardWithColumns([$team], ['ticket_prefix' => 'AAA']);
        $second = $this->boardWithColumns([$team], ['ticket_prefix' => 'BBB']);

        $a1 = $this->ticketOn($first, $team);
        $a2 = $this->ticketOn($first, $team);
        $b1 = $this->ticketOn($second, $team);

        $this->assertSame([1, 2, 1], [$a1->number, $a2->number, $b1->number]);
        $this->assertSame('AAA-1', $a1->load('board')->key());
        $this->assertSame('AAA-2', $a2->load('board')->key());
        $this->assertSame('BBB-1', $b1->load('board')->key());
    }

    public function test_a_deleted_ticket_does_not_release_its_number(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $first = $this->ticketOn($board, $team);
        $first->delete();

        $second = $this->ticketOn($board, $team);

        $this->assertSame(2, $second->number, 'Reusing a retired key would rewrite history in old links.');
    }

    public function test_creating_a_ticket_records_a_created_event(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team);

        $event = $ticket->events()->sole();

        $this->assertSame(TicketEventType::TicketCreated, $event->type);
        $this->assertSame($team->id, $event->actor_id);
        $this->assertSame($board->id, $event->board_id);
        $this->assertSame('Backlog', $event->payload['column']);
    }

    public function test_quick_add_on_the_board_creates_a_ticket_in_that_column(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $progress = $this->columnNamed($board, 'In Progress');

        Livewire::actingAs($team)
            ->test(BoardShow::class, ['board' => $board])
            ->call('startQuickAdd', $progress->id)
            ->set('quickAddTitle', 'Straight to work')
            ->call('quickAdd')
            ->assertHasNoErrors();

        $ticket = Ticket::query()->where('title', 'Straight to work')->sole();

        $this->assertSame($progress->id, $ticket->board_column_id);
    }

    public function test_a_ticket_requires_a_title(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(TicketCreate::class, ['board' => $board])
            ->set('title', '')
            ->call('save')
            ->assertHasErrors('title');

        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_new_tickets_are_appended_to_the_end_of_their_column(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $first = $this->ticketOn($board, $team, ['title' => 'First']);
        $second = $this->ticketOn($board, $team, ['title' => 'Second']);

        $this->assertSame(0, $first->position);
        $this->assertSame(1, $second->position);
    }

    public function test_a_column_from_another_board_is_ignored_rather_than_used(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $foreign = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team, [
            'board_column_id' => $this->columnNamed($foreign, 'Done')->id,
        ]);

        $this->assertSame($board->id, $ticket->board_id);
        $this->assertSame($this->columnNamed($board, 'Backlog')->id, $ticket->board_column_id);
    }

    public function test_an_assignee_who_is_not_a_board_member_is_dropped(): void
    {
        $team = $this->teamMember();
        $outsider = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team, ['assignee_id' => $outsider->id]);

        $this->assertNull($ticket->assignee_id);
    }

    public function test_a_customer_cannot_be_made_an_assignee(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $team, ['assignee_id' => $customer->id]);

        $this->assertNull($ticket->assignee_id, 'Assignment means "doing the work"; customers are not assignable.');
    }
}
