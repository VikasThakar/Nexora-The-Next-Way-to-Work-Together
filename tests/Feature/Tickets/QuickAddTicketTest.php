<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Enums\TicketPriority;
use App\Enums\TicketType;
use App\Livewire\Boards\Show as BoardShow;
use App\Livewire\Tickets\Show;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Quick add, with the fields the client asked for.
 *
 * Title, type, assignee, priority, labels and a due date. Five of the six
 * already existed on a ticket; only the type is new. Everything is passed
 * through to App\Actions\Tickets\CreateTicket, which is where the customer
 * rules live — so the tests below check both that the fields work and that
 * offering them has not become a way around those rules.
 */
class QuickAddTicketTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The fast path, and the one people use constantly: type a title, press
     * Enter. It must keep working with nothing else filled in.
     */
    public function test_a_title_alone_still_creates_a_ticket(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $column = $this->columnNamed($board, 'In Progress');

        Livewire::actingAs($team)
            ->test(BoardShow::class, ['board' => $board])
            ->call('startQuickAdd', $column->id)
            ->set('quickAddTitle', 'Just a title')
            ->call('quickAdd')
            ->assertHasNoErrors();

        $ticket = Ticket::query()->where('title', 'Just a title')->sole();

        $this->assertSame($column->id, $ticket->board_column_id);
        $this->assertSame(TicketType::default(), $ticket->type);
        $this->assertSame(TicketPriority::default(), $ticket->priority);
        $this->assertNull($ticket->assignee_id);
    }

    public function test_every_offered_field_is_stored(): void
    {
        $team = $this->teamMember();
        $assignee = $this->teamMember();
        $board = $this->boardWithColumns([$team, $assignee]);
        $column = $this->columnNamed($board, 'Backlog');

        $label = $board->labels()->create(['name' => 'Regression', 'color' => 'rose']);

        Livewire::actingAs($team)
            ->test(BoardShow::class, ['board' => $board])
            ->call('startQuickAdd', $column->id)
            ->call('toggleQuickAddDetails')
            ->assertSet('quickAddExpanded', true)
            ->set('quickAddTitle', 'Login screen rejects valid passwords')
            ->set('quickAddType', TicketType::Bug->value)
            ->set('quickAddPriority', TicketPriority::Critical->value)
            ->set('quickAddAssigneeId', (string) $assignee->id)
            ->set('quickAddDueDate', '2026-10-01')
            ->call('toggleQuickAddLabel', $label->id)
            ->call('quickAdd')
            ->assertHasNoErrors();

        $ticket = Ticket::query()->where('title', 'Login screen rejects valid passwords')->sole();

        $this->assertSame(TicketType::Bug, $ticket->type);
        $this->assertSame(TicketPriority::Critical, $ticket->priority);
        $this->assertSame($assignee->id, $ticket->assignee_id);
        $this->assertSame('2026-10-01', $ticket->due_date?->format('Y-m-d'));
        $this->assertTrue($ticket->labels->contains($label));
    }

    /**
     * Filing three bugs in a row should not mean reopening the form and
     * re-choosing the details each time.
     */
    public function test_the_form_stays_open_and_clears_after_adding(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $column = $this->columnNamed($board, 'Backlog');

        Livewire::actingAs($team)
            ->test(BoardShow::class, ['board' => $board])
            ->call('startQuickAdd', $column->id)
            ->call('toggleQuickAddDetails')
            ->set('quickAddTitle', 'First')
            ->set('quickAddType', TicketType::Bug->value)
            ->call('quickAdd')
            // Same column, still expanded, title cleared.
            ->assertSet('quickAddColumnId', $column->id)
            ->assertSet('quickAddExpanded', true)
            ->assertSet('quickAddTitle', '')
            // The per-ticket choices reset, so the next card does not silently
            // inherit the last one's assignee or due date.
            ->assertSet('quickAddType', TicketType::default()->value)
            ->assertSet('quickAddAssigneeId', '')
            ->assertSet('quickAddDueDate', '')
            ->assertSet('quickAddLabelIds', []);
    }

    public function test_a_title_is_required(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $column = $this->columnNamed($board, 'Backlog');

        Livewire::actingAs($team)
            ->test(BoardShow::class, ['board' => $board])
            ->call('startQuickAdd', $column->id)
            ->set('quickAddTitle', '   ')
            ->call('quickAdd')
            ->assertHasErrors(['quickAddTitle' => 'required']);

        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_an_unparseable_due_date_is_rejected(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $column = $this->columnNamed($board, 'Backlog');

        Livewire::actingAs($team)
            ->test(BoardShow::class, ['board' => $board])
            ->call('startQuickAdd', $column->id)
            ->set('quickAddTitle', 'Valid title')
            ->set('quickAddDueDate', 'next tuesday-ish')
            ->call('quickAdd')
            ->assertHasErrors(['quickAddDueDate' => 'date']);

        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_an_unknown_type_is_refused_rather_than_stored(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $column = $this->columnNamed($board, 'Backlog');

        Livewire::actingAs($team)
            ->test(BoardShow::class, ['board' => $board])
            ->call('startQuickAdd', $column->id)
            ->set('quickAddTitle', 'Valid title')
            ->set('quickAddType', 'epic')
            ->call('quickAdd')
            ->assertHasErrors('quickAddType');

        $this->assertSame(0, Ticket::query()->count());
    }

    /**
     * A label belongs to a board, so an id from somewhere else must not attach.
     * CreateTicket filters through the board's own labels; this pins it from the
     * quick-add form, which is the new way to reach it.
     */
    public function test_a_label_from_another_board_is_dropped(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $column = $this->columnNamed($board, 'Backlog');

        $elsewhere = $this->boardWithColumns([$this->teamMember()]);
        $foreign = $elsewhere->labels()->create(['name' => 'Theirs', 'color' => 'amber']);

        Livewire::actingAs($team)
            ->test(BoardShow::class, ['board' => $board])
            ->call('startQuickAdd', $column->id)
            ->set('quickAddTitle', 'Mine')
            ->call('toggleQuickAddLabel', $foreign->id)
            ->call('quickAdd')
            ->assertHasNoErrors();

        $ticket = Ticket::query()->where('title', 'Mine')->sole();

        $this->assertCount(0, $ticket->labels);
    }

    // -----------------------------------------------------------------
    // Customers
    // -----------------------------------------------------------------

    /**
     * The details are staff-only in the markup, because CreateTicket would
     * discard them for a customer anyway. Showing a customer an assignee picker
     * whose value is silently dropped would be a lie told by the interface.
     */
    public function test_the_details_are_not_offered_to_a_customer(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $column = $this->columnNamed($board, 'Backlog');

        Livewire::actingAs($customer)
            ->test(BoardShow::class, ['board' => $board])
            ->call('startQuickAdd', $column->id)
            // Asserted on the controls' own ids rather than on their labels:
            // "Unassigned" also appears in the board's assignee filter, and a
            // word-level assertion would pass or fail for the wrong reason.
            ->assertDontSee('qa-assignee-')
            ->assertDontSee('qa-type-')
            ->assertDontSee('quick-add-details-');
    }

    /**
     * And forging them changes nothing, because the rule lives in the action.
     */
    public function test_a_customer_forging_the_details_still_gets_the_customer_rules(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $first = $this->columnNamed($board, 'Backlog');
        $later = $this->columnNamed($board, 'In Progress');
        $label = $board->labels()->create(['name' => 'Internal only', 'color' => 'slate']);

        Livewire::actingAs($customer)
            ->test(BoardShow::class, ['board' => $board])
            ->call('startQuickAdd', $later->id)
            ->set('quickAddTitle', 'Please look at this')
            ->set('quickAddAssigneeId', (string) $team->id)
            ->set('quickAddType', TicketType::Bug->value)
            ->call('toggleQuickAddLabel', $label->id)
            ->call('quickAdd')
            ->assertHasNoErrors();

        $ticket = Ticket::query()->where('title', 'Please look at this')->sole();

        // Forced into the first column, unassigned, unlabelled, and visible to
        // the customer who raised it.
        $this->assertSame($first->id, $ticket->board_column_id);
        $this->assertNull($ticket->assignee_id);
        $this->assertCount(0, $ticket->labels);
        $this->assertTrue($ticket->customer_visible);

        // Type is not a delivery-team decision, so this one is honoured: the
        // person reporting a defect knows it is a defect.
        $this->assertSame(TicketType::Bug, $ticket->type);
    }

    /**
     * The board itself is unreachable, so quick add never gets a chance.
     *
     * Asserted through the route rather than by mounting the component: mount()
     * denies as 404 before there is a component to call an action on, which is
     * the correct behaviour and not something Livewire::test can express.
     */
    public function test_somebody_who_cannot_reach_the_board_cannot_quick_add_to_it(): void
    {
        $outsider = $this->teamMember();
        $board = $this->boardWithColumns([$this->teamMember()]);

        $this->actingAs($outsider)
            ->get(route('boards.show', $board))
            ->assertNotFound();

        $this->assertSame(0, Ticket::query()->count());
    }

    // -----------------------------------------------------------------
    // Type, elsewhere
    // -----------------------------------------------------------------

    public function test_the_type_can_be_changed_from_the_ticket_sidebar(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(Show::class, ['board' => $board, 'number' => $ticket->number])
            ->set('type', TicketType::Feature->value)
            ->assertHasNoErrors();

        $this->assertSame(TicketType::Feature, $ticket->refresh()->type);

        // And it reads in the timeline through the existing activity system,
        // collapsed into ticket_updated like any other ordinary field.
        $this->assertTrue(
            $ticket->events()->where('type', 'ticket_updated')->exists(),
            'Changing the type was not recorded.'
        );
    }

    public function test_a_bug_is_marked_on_the_board_card(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->ticketOn($board, $team, ['title' => 'A defect', 'type' => TicketType::Bug->value]);
        $this->ticketOn($board, $team, ['title' => 'A chore', 'type' => TicketType::Task->value]);

        $response = $this->actingAs($team)->get(route('boards.show', $board))->assertOk();

        $response->assertSee('A defect')->assertSee('A chore');

        // One chip, not three: Task is the default and Feature is unremarkable,
        // so labelling every card would communicate nothing.
        $this->assertSame(1, substr_count($response->getContent(), 'title="Bug"'));
    }

    /**
     * Every ticket that existed before the column did reads as a Task, from the
     * database default rather than from a backfill.
     */
    public function test_existing_tickets_default_to_task(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team);

        // Written the way a pre-migration row was: with no type at all.
        \DB::table('tickets')->where('id', $ticket->id)->update(['type' => 'task']);

        $this->assertSame(TicketType::Task, $ticket->refresh()->type);
    }
}
