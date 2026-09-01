<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Livewire\Boards\Show as BoardShow;
use App\Livewire\Tickets\Create as TicketCreate;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The rules for a ticket raised by a customer.
 *
 * These are enforced in App\Actions\Tickets\CreateTicket rather than in the
 * form, so each test drives the action through a component while submitting the
 * fields a hostile client would submit anyway. Passing means the rule survives
 * a crafted request, not just a well-behaved form.
 */
class CustomerTicketCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_can_raise_a_ticket_on_a_board_they_belong_to(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        Livewire::actingAs($customer)
            ->test(TicketCreate::class, ['board' => $board])
            ->set('title', 'Please add two more seats')
            ->set('descriptionMd', 'We have new starters joining.')
            ->call('save')
            ->assertHasNoErrors();

        $ticket = Ticket::query()->where('title', 'Please add two more seats')->sole();

        $this->assertSame($customer->id, $ticket->created_by_id);
    }

    public function test_a_customer_created_ticket_is_automatically_customer_visible(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        // Explicitly asking for an internal ticket must not produce one.
        $ticket = $this->ticketOn($board, $customer, ['customer_visible' => false]);

        $this->assertTrue(
            $ticket->customer_visible,
            'A customer must never create something they then cannot see.'
        );
    }

    public function test_a_customer_created_ticket_lands_in_the_first_column(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        // Asking for a later column must be ignored.
        $ticket = $this->ticketOn($board, $customer, [
            'board_column_id' => $this->columnNamed($board, 'Done')->id,
        ]);

        $this->assertSame(
            $this->columnNamed($board, 'Backlog')->id,
            $ticket->board_column_id,
            'Workflow position is the delivery team decision.'
        );
    }

    public function test_a_customer_cannot_assign_a_ticket_to_a_team_member(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $customer, ['assignee_id' => $team->id]);

        $this->assertNull($ticket->assignee_id);
    }

    public function test_a_customer_cannot_set_an_estimate_or_labels(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $label = $board->labels()->create(['name' => 'internal-only', 'color' => 'rose']);

        $ticket = $this->ticketOn($board, $customer, [
            'estimate' => 8,
            'label_ids' => [$label->id],
        ]);

        $this->assertNull($ticket->estimate);
        $this->assertSame(0, $ticket->labels()->count());
    }

    public function test_the_customer_form_does_not_offer_staff_fields(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        $this->actingAs($customer)
            ->get(route('tickets.create', $board))
            ->assertOk()
            ->assertSee('will start in', escape: false)
            ->assertDontSee('Customer visibility')
            ->assertDontSee('Assignee');
    }

    public function test_a_customer_cannot_raise_a_ticket_on_a_board_they_do_not_belong_to(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns();

        $this->actingAs($customer)
            ->get(route('tickets.create', $board))
            ->assertNotFound();

        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_a_customer_can_quick_add_from_the_board_and_it_still_lands_in_the_first_column(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $done = $this->columnNamed($board, 'Done');

        Livewire::actingAs($customer)
            ->test(BoardShow::class, ['board' => $board])
            ->call('startQuickAdd', $done->id)
            ->set('quickAddTitle', 'Raised from the board')
            ->call('quickAdd')
            ->assertHasNoErrors();

        $ticket = Ticket::query()->where('title', 'Raised from the board')->sole();

        $this->assertSame($this->columnNamed($board, 'Backlog')->id, $ticket->board_column_id);
        $this->assertTrue($ticket->customer_visible);
    }

    public function test_a_customer_can_see_and_open_the_ticket_they_raised(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        $ticket = $this->ticketOn($board, $customer, ['title' => 'My own request']);

        $this->actingAs($customer)
            ->get(route('tickets.show', ['board' => $board, 'number' => $ticket->number]))
            ->assertOk()
            ->assertSee('My own request');
    }
}
