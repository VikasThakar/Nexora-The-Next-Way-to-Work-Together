<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Actions\Tickets\ManageSubtasks;
use App\Livewire\Tickets\Components\Subtasks;
use App\Livewire\Tickets\Show as TicketShow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Moving a standalone checklist into the description.
 *
 * Nexora already had checklists — `ticket_subtasks`, with drag-to-reorder and a
 * record of who ticked each item and when. The client asked for checklists
 * inside the description. Both now exist, and this file covers the bridge
 * between them.
 *
 * The strong claim being tested is that no data is lost. The subtask rows do go
 * away, but the `completed_at` and `completed_by_id` a Markdown checkbox cannot
 * express are written into the append-only ticket event, so the conversion is
 * reconstructible from the audit trail. That is a different thing from being
 * undoable in the interface, and only the first is claimed.
 */
class ChecklistTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_nothing_converts_on_its_own(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['description_md' => 'Original']);

        app(ManageSubtasks::class)->add($ticket, 'Still here', $team);

        // Opening the ticket, editing it, saving it — none of that touches the
        // checklist. A migration that rewrote descriptions automatically would
        // have been irreversible and unasked for.
        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('startEditing')
            ->set('descriptionHtml', '<p>Edited</p>')
            ->call('save');

        $ticket->refresh();

        $this->assertSame(1, $ticket->subtasks()->count());
        $this->assertSame('Edited', $ticket->description_md);
        $this->assertStringNotContainsString('Still here', (string) $ticket->description_md);
    }

    public function test_the_checklist_is_appended_to_the_description(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['description_md' => "## Background\n\nSome context."]);

        $subtasks = app(ManageSubtasks::class);
        $subtasks->add($ticket, 'Design login screen', $team);
        $second = $subtasks->add($ticket, 'Implement authentication', $team);
        $subtasks->add($ticket, 'Write tests', $team);

        $subtasks->setCompleted($ticket, $second, true, $team);

        Livewire::actingAs($team)
            ->test(Subtasks::class, ['ticket' => $ticket])
            ->call('convertToChecklist')
            ->assertHasNoErrors();

        $ticket->refresh();

        // Appended under a heading, not substituted: the description somebody
        // wrote survives intact above it.
        $this->assertSame(
            "## Background\n\nSome context.\n\n## Checklist\n\n"
            ."- [ ] Design login screen\n- [x] Implement authentication\n- [ ] Write tests",
            $ticket->description_md
        );

        $this->assertSame(0, $ticket->subtasks()->count());
    }

    /**
     * A ticket whose description is nothing but a checklist does not need a
     * heading announcing it.
     */
    public function test_an_empty_description_gets_the_list_with_no_heading(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['description_md' => null]);

        app(ManageSubtasks::class)->add($ticket, 'The only step', $team);

        Livewire::actingAs($team)
            ->test(Subtasks::class, ['ticket' => $ticket])
            ->call('convertToChecklist');

        $this->assertSame('- [ ] The only step', $ticket->refresh()->description_md);
    }

    /**
     * The claim that makes this safe to ship.
     */
    public function test_the_metadata_a_checkbox_cannot_hold_is_kept_in_the_ticket_history(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $subtasks = app(ManageSubtasks::class);
        $item = $subtasks->add($ticket, 'Implement authentication', $team);
        $subtasks->setCompleted($ticket, $item, true, $team);

        Livewire::actingAs($team)
            ->test(Subtasks::class, ['ticket' => $ticket])
            ->call('convertToChecklist');

        $event = $ticket->events()->where('type', 'checklist_converted')->sole();

        $this->assertSame(1, $event->payload['count']);
        $this->assertSame(1, $event->payload['completed']);

        $stored = $event->payload['items'][0];

        $this->assertSame('Implement authentication', $stored['title']);
        $this->assertTrue($stored['completed']);

        // Neither of these can be expressed as `- [x] text`, which is exactly
        // why they are written here.
        $this->assertSame($team->id, $stored['completed_by_id']);
        $this->assertNotNull($stored['completed_at']);
    }

    public function test_the_conversion_reads_in_the_timeline(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        app(ManageSubtasks::class)->add($ticket, 'A step', $team);

        Livewire::actingAs($team)
            ->test(Subtasks::class, ['ticket' => $ticket])
            ->call('convertToChecklist');

        $this->actingAs($team)
            ->get(route('tickets.show', ['board' => $board, 'number' => $ticket->number]))
            ->assertOk()
            ->assertSee('moved the checklist into the description');
    }

    /**
     * Once converted, the items are live where they landed — which is the whole
     * point of the exercise.
     */
    public function test_converted_items_can_be_ticked_in_the_description(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['description_md' => null]);

        app(ManageSubtasks::class)->add($ticket, 'Write tests', $team);

        Livewire::actingAs($team)
            ->test(Subtasks::class, ['ticket' => $ticket])
            ->call('convertToChecklist');

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('toggleDescriptionTask', 0);

        $this->assertSame('- [x] Write tests', $ticket->refresh()->description_md);
    }

    /**
     * A title with a newline in it would end the list item and split one
     * checklist entry into two things. `title` is a plain string column with no
     * newline validation, so this cannot be assumed away.
     */
    public function test_a_multi_line_title_stays_one_checklist_item(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['description_md' => null]);

        // Written through the relation rather than the action: ManageSubtasks
        // is reached from a form, and a form cannot post a newline into a
        // single-line input. The column can hold one, so the converter has to
        // cope with one.
        $ticket->subtasks()->create([
            'title' => "First line\nsecond line",
            'completed' => false,
            'position' => 0,
        ]);

        Livewire::actingAs($team)
            ->test(Subtasks::class, ['ticket' => $ticket])
            ->call('convertToChecklist');

        $this->assertSame('- [ ] First line second line', $ticket->refresh()->description_md);
    }

    public function test_converting_an_empty_checklist_does_nothing(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['description_md' => 'Unchanged']);

        Livewire::actingAs($team)
            ->test(Subtasks::class, ['ticket' => $ticket])
            ->call('convertToChecklist');

        $ticket->refresh();

        $this->assertSame('Unchanged', $ticket->description_md);
        $this->assertSame(0, $ticket->events()->where('type', 'checklist_converted')->count());
    }

    public function test_the_button_only_appears_when_there_is_something_to_move(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(Subtasks::class, ['ticket' => $ticket])
            ->assertSet('ticket.id', $ticket->id)
            ->assertDontSee('Move into description');

        app(ManageSubtasks::class)->add($ticket, 'A step', $team);

        Livewire::actingAs($team)
            ->test(Subtasks::class, ['ticket' => $ticket])
            ->assertSee('Move into description');
    }

    // -----------------------------------------------------------------
    // Authorization
    // -----------------------------------------------------------------

    /**
     * The conversion does two things — it empties the checklist and it rewrites
     * the description — so it needs the right to do both.
     *
     * A customer may tick items on a request they raised (manageSubtasks
     * follows update), so the interesting case is a customer on somebody
     * else's ticket: they can read it, and must not be able to rewrite it.
     */
    public function test_a_customer_cannot_convert_a_checklist_on_a_ticket_they_did_not_raise(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $team, [
            'customer_visible' => true,
            'description_md' => 'Not yours to rewrite',
        ]);

        app(ManageSubtasks::class)->add($ticket, 'A step', $team);

        Livewire::actingAs($customer)
            ->test(Subtasks::class, ['ticket' => $ticket])
            ->call('convertToChecklist')
            ->assertForbidden();

        $ticket->refresh();

        $this->assertSame('Not yours to rewrite', $ticket->description_md);
        $this->assertSame(1, $ticket->subtasks()->count());
    }

    public function test_a_non_member_cannot_reach_the_checklist_at_all(): void
    {
        $team = $this->teamMember();
        $outsider = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        app(ManageSubtasks::class)->add($ticket, 'A step', $team);

        Livewire::actingAs($outsider)
            ->test(Subtasks::class, ['ticket' => $ticket])
            ->assertNotFound();
    }
}
