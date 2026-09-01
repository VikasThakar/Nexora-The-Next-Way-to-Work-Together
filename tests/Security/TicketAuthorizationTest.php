<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Actions\Tickets\UpdateTicket;
use App\Livewire\Boards\Settings as BoardSettings;
use App\Livewire\Boards\Show as BoardShow;
use App\Livewire\Tickets\Components\Subtasks;
use App\Livewire\Tickets\Show as TicketShow;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Who may change what.
 *
 * Reading is covered by TicketVisibilityTest; this file is about writes. The
 * distinction that matters is that a customer can read a board and still not be
 * allowed to move, assign, relabel or expose anything on it.
 */
class TicketAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_non_member_cannot_move_a_ticket(): void
    {
        $team = $this->teamMember();
        $outsider = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team);
        $target = $this->columnNamed($board, 'Done');

        Livewire::actingAs($outsider)
            ->test(BoardShow::class, ['board' => $board])
            ->assertNotFound();

        $this->assertSame(
            $this->columnNamed($board, 'Backlog')->id,
            $ticket->fresh()->board_column_id
        );
    }

    public function test_a_customer_cannot_move_a_ticket_even_on_their_own_board(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);
        $done = $this->columnNamed($board, 'Done');

        Livewire::actingAs($customer)
            ->test(BoardShow::class, ['board' => $board])
            ->call('moveTicket', $ticket->id, 0, $done->id)
            ->assertForbidden();

        $this->assertSame(
            $this->columnNamed($board, 'Backlog')->id,
            $ticket->fresh()->board_column_id
        );
    }

    public function test_a_customer_cannot_move_an_internal_ticket_they_cannot_even_see(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $internal = $this->ticketOn($board, $team);
        $done = $this->columnNamed($board, 'Done');

        Livewire::actingAs($customer)
            ->test(BoardShow::class, ['board' => $board])
            ->call('moveTicket', $internal->id, 0, $done->id)
            ->assertNotFound();
    }

    public function test_a_ticket_cannot_be_moved_into_a_column_on_another_board(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $other = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team);
        $foreignColumn = $this->columnNamed($other, 'Done');

        Livewire::actingAs($team)
            ->test(BoardShow::class, ['board' => $board])
            ->call('moveTicket', $ticket->id, 0, $foreignColumn->id)
            ->assertNotFound();

        $this->assertSame($board->id, $ticket->fresh()->board_id);
    }

    public function test_a_customer_cannot_change_visibility_assignee_or_labels(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $label = $board->labels()->create(['name' => 'internal', 'color' => 'rose']);

        $ticket = $this->ticketOn($board, $customer, ['title' => 'My request']);

        $this->assertFalse($customer->can('changeVisibility', $ticket));
        $this->assertFalse($customer->can('assign', $ticket));
        $this->assertFalse($customer->can('manageLabels', $ticket));
        $this->assertFalse($customer->can('manageLinks', $ticket));
        $this->assertFalse($customer->can('delete', $ticket));

        Livewire::actingAs($customer)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('toggleVisibility')
            ->assertForbidden();

        Livewire::actingAs($customer)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('toggleLabel', $label->id)
            ->assertForbidden();

        $this->assertTrue($ticket->fresh()->customer_visible);
        $this->assertSame(0, $ticket->fresh()->labels()->count());
    }

    public function test_privileged_fields_are_dropped_even_when_a_customer_submits_them(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $customer, ['title' => 'Mine']);

        // The action is the boundary, not the form: a crafted payload gets the
        // privileged keys stripped rather than applied.
        app(UpdateTicket::class)->handle($ticket, [
            'title' => 'Renamed by me',
            'customer_visible' => false,
            'assignee_id' => $team->id,
            'estimate' => 13,
        ], $customer);

        $ticket->refresh();

        $this->assertSame('Renamed by me', $ticket->title);
        $this->assertTrue($ticket->customer_visible);
        $this->assertNull($ticket->assignee_id);
        $this->assertNull($ticket->estimate);
    }

    public function test_a_customer_cannot_edit_a_ticket_somebody_else_raised(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $staffTicket = $this->ticketOn($board, $team, ['customer_visible' => true]);

        $this->assertFalse($customer->can('update', $staffTicket));

        Livewire::actingAs($customer)
            ->test(TicketShow::class, ['board' => $board, 'number' => $staffTicket->number])
            ->call('startEditing')
            ->assertForbidden();
    }

    public function test_a_customer_may_edit_the_ticket_they_raised_themselves(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        $ticket = $this->ticketOn($board, $customer, ['title' => 'Original']);

        $this->assertTrue($customer->can('update', $ticket));

        Livewire::actingAs($customer)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('startEditing')
            ->set('title', 'Updated by the customer')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Updated by the customer', $ticket->fresh()->title);
    }

    public function test_a_customer_can_tick_off_subtasks_on_their_own_ticket_only(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $own = $this->ticketOn($board, $customer);
        $theirs = $this->ticketOn($board, $team, ['customer_visible' => true]);

        $this->assertTrue($customer->can('manageSubtasks', $own));
        $this->assertFalse($customer->can('manageSubtasks', $theirs));

        Livewire::actingAs($customer)
            ->test(Subtasks::class, ['ticket' => $theirs])
            ->set('newTitle', 'Sneaky')
            ->call('add')
            ->assertForbidden();

        $this->assertSame(0, $theirs->subtasks()->count());
    }

    public function test_a_customer_cannot_reach_board_configuration(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        $this->actingAs($customer)
            ->get(route('boards.settings', $board))
            ->assertForbidden();

        Livewire::actingAs($customer)
            ->test(BoardSettings::class, ['board' => $board])
            ->assertForbidden();
    }

    public function test_a_non_member_cannot_reach_board_configuration_and_gets_404(): void
    {
        $outsider = $this->teamMember();
        $board = $this->boardWithColumns();

        $this->actingAs($outsider)
            ->get(route('boards.settings', $board))
            ->assertNotFound();
    }

    public function test_a_staff_board_member_may_configure_columns_without_being_an_administrator(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        // Deliberately wider than BoardPolicy::update, which stays admin-only.
        $this->assertTrue($team->can('manageColumns', $board));
        $this->assertFalse($team->can('update', $board));

        $this->actingAs($team)
            ->get(route('boards.settings', $board))
            ->assertOk();
    }

    public function test_only_staff_may_delete_a_ticket(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $customer);

        $this->assertFalse($customer->can('delete', $ticket));
        $this->assertTrue($team->can('delete', $ticket));

        Livewire::actingAs($customer)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('destroyTicket')
            ->assertForbidden();

        $this->assertNotNull(Ticket::query()->find($ticket->id));
    }

    public function test_an_outsider_cannot_open_any_ticket_screen(): void
    {
        $team = $this->teamMember();
        $outsider = $this->customer();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);

        $this->actingAs($outsider)
            ->get(route('tickets.show', ['board' => $board, 'number' => $ticket->number]))
            ->assertNotFound();

        $this->actingAs($outsider)
            ->get(route('tickets.create', $board))
            ->assertNotFound();
    }
}
