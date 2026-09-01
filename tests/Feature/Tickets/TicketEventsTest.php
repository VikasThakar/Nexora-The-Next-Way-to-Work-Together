<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Actions\Tickets\SyncTicketLabels;
use App\Actions\Tickets\UpdateTicket;
use App\Enums\TicketEventType;
use App\Enums\TicketPriority;
use App\Livewire\Tickets\Show as TicketShow;
use App\Models\TicketEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ticket history.
 *
 * Recorded from the very first ticket so the timeline and the flow metrics of
 * later phases have real data rather than starting from the day they ship.
 */
class TicketEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_significant_fields_get_their_own_event_type(): void
    {
        $team = $this->teamMember();
        $other = $this->teamMember();
        $board = $this->boardWithColumns([$team, $other]);

        $ticket = $this->ticketOn($board, $team, ['priority' => TicketPriority::Low]);

        app(UpdateTicket::class)->handle($ticket, [
            'priority' => TicketPriority::Critical->value,
            'assignee_id' => $other->id,
            'customer_visible' => true,
        ], $team);

        $types = $ticket->events()->pluck('type');

        $this->assertTrue($types->contains(TicketEventType::PriorityChanged));
        $this->assertTrue($types->contains(TicketEventType::AssigneeChanged));
        $this->assertTrue($types->contains(TicketEventType::VisibilityChanged));
    }

    public function test_ordinary_field_edits_collapse_into_one_updated_event(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team, ['title' => 'Before']);

        app(UpdateTicket::class)->handle($ticket, [
            'title' => 'After',
            'description_md' => 'New body',
        ], $team);

        $event = $ticket->events()->where('type', TicketEventType::TicketUpdated)->sole();

        $this->assertSame('Before', $event->payload['changes']['title']['from']);
        $this->assertSame('After', $event->payload['changes']['title']['to']);
        $this->assertArrayHasKey('description_md', $event->payload['changes']);
    }

    public function test_an_update_that_changes_nothing_records_nothing(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team, ['title' => 'Same']);
        $before = $ticket->events()->count();

        app(UpdateTicket::class)->handle($ticket, ['title' => 'Same'], $team);

        $this->assertSame($before, $ticket->events()->count(), 'A no-op edit must not pollute the timeline.');
    }

    public function test_label_changes_record_what_was_added_and_removed(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $bug = $board->labels()->create(['name' => 'bug', 'color' => 'rose']);
        $feature = $board->labels()->create(['name' => 'feature', 'color' => 'brand']);

        $ticket = $this->ticketOn($board, $team);
        $sync = app(SyncTicketLabels::class);

        $sync->handle($ticket, [$bug->id], $team);
        $sync->handle($ticket, [$feature->id], $team);

        $events = $ticket->events()->where('type', TicketEventType::LabelChanged)->orderBy('id')->get();

        $this->assertSame(['bug'], $events[0]->payload['added']);
        $this->assertSame(['feature'], $events[1]->payload['added']);
        $this->assertSame(['bug'], $events[1]->payload['removed']);
    }

    public function test_events_carry_the_board_for_future_metrics(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team);

        $this->assertSame(
            $board->id,
            TicketEvent::query()->where('ticket_id', $ticket->id)->value('board_id')
        );
    }

    public function test_events_are_removed_with_their_ticket(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team);
        $this->assertSame(1, TicketEvent::query()->count());

        $ticket->delete();

        $this->assertSame(0, TicketEvent::query()->count());
    }

    public function test_the_timeline_renders_on_the_ticket_page(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team);

        app(UpdateTicket::class)->handle($ticket, ['priority' => TicketPriority::Critical->value], $team);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->assertSee('created this ticket')
            ->assertSee('changed the priority');
    }

    public function test_an_event_survives_the_actor_being_deleted(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team);

        $team->delete();

        $event = $ticket->events()->sole();

        $this->assertNull($event->actor_id, 'History must outlive the person who made it.');
        $this->assertSame(TicketEventType::TicketCreated, $event->type);
    }
}
