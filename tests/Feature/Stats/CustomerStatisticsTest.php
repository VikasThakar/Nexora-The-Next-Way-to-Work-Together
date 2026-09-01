<?php

declare(strict_types=1);

namespace Tests\Feature\Stats;

use App\Actions\Tickets\MoveTicket;
use App\Actions\Tickets\UpdateTicket;
use App\Enums\TicketPriority;
use App\Livewire\Stats\Customer;
use App\Services\Statistics\CustomerStatistics;
use App\Services\Statistics\StatisticsScope;
use App\Support\StatsPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerStatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_only_counts_the_tickets_they_can_see(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        // Two shared, three internal.
        $this->ticketOn($board, $customer, ['title' => 'Mine one']);
        $this->ticketOn($board, $team, ['title' => 'Shared', 'customer_visible' => true]);
        $this->ticketOn($board, $team, ['title' => 'Internal one']);
        $this->ticketOn($board, $team, ['title' => 'Internal two']);
        $this->ticketOn($board, $team, ['title' => 'Internal three']);

        $counts = app(CustomerStatistics::class)->counts($this->scope($customer));

        // Five exist; the customer's report knows about two, because the query
        // starts from the ordinary visibility scope.
        $this->assertSame(2, $counts['total']);
        $this->assertSame(2, $counts['open']);
        $this->assertSame(0, $counts['closed']);
    }

    public function test_the_priority_breakdown_excludes_internal_work(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $this->ticketOn($board, $team, [
            'title' => 'Internal fire',
            'priority' => TicketPriority::Critical->value,
        ]);

        $this->ticketOn($board, $customer, ['title' => 'Their request']);

        $rows = collect(app(CustomerStatistics::class)->byPriority($this->scope($customer)))
            ->keyBy('label');

        // The internal critical ticket is invisible, so the customer's chart
        // shows no critical work — which is what they are allowed to know.
        $this->assertSame(0, $rows['Critical']['value']);
        $this->assertSame(1, $rows['Medium']['value']);
    }

    public function test_the_status_chart_is_open_versus_closed_not_one_slice_per_column(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $this->columnNamed($board, 'Review')->update(['name' => 'Blocked on legal']);

        $ticket = $this->ticketOn($board, $customer);
        app(MoveTicket::class)->handle($ticket, $this->columnNamed($board, 'Blocked on legal'), 0, $team);

        // Two slices, whatever the board's workflow looks like. Not a secrecy
        // rule — the board shows a customer the same columns it shows everyone —
        // but a chart of their work spread across the team's process answers a
        // question they did not ask. See CustomerStatistics::statusSplit.
        $split = app(CustomerStatistics::class)->statusSplit($this->scope($customer));

        $this->assertSame(['Open', 'Closed'], array_column($split, 'label'));
        $this->assertSame(1, $split[0]['value']);
    }

    public function test_recent_activity_excludes_internal_only_event_types(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $customer);

        // Flipping visibility records an internal-only event.
        app(UpdateTicket::class)
            ->handle($ticket, ['customer_visible' => false], $team);

        app(UpdateTicket::class)
            ->handle($ticket->refresh(), ['customer_visible' => true], $team);

        $events = app(CustomerStatistics::class)->recentActivity($this->scope($customer));

        foreach ($events as $event) {
            // TicketEvent::readableBy drops these in SQL; nothing in the view
            // has to remember to skip them.
            $this->assertFalse($event->type->isInternalOnly());
        }
    }

    public function test_the_screen_renders_for_a_customer(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer], ['name' => 'Aqueduct Platform']);
        $this->ticketOn($board, $customer, ['title' => 'Export is broken']);

        Livewire::actingAs($customer)
            ->test(Customer::class)
            ->assertOk()
            ->assertSee('Your tickets')
            ->assertSee('Export is broken');
    }

    public function test_staff_opening_the_customer_view_are_told_whose_view_it_is(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->ticketOn($board, $team);

        // Useful for checking the shape of the page, but the numbers are still
        // the staff member's own visibility — and the screen says so rather
        // than implying it simulates a particular customer.
        Livewire::actingAs($team)
            ->test(Customer::class)
            ->assertOk()
            ->assertSee('This is the customer view');
    }

    public function test_a_customer_with_no_boards_sees_an_empty_state_not_an_error(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer)
            ->get(route('stats.customer'))
            ->assertOk()
            ->assertSee('Nothing shared with you yet');
    }

    private function scope($viewer, $board = null): StatisticsScope
    {
        return StatisticsScope::for($viewer, StatsPeriod::preset(StatsPeriod::LAST_30_DAYS, 'UTC'), $board);
    }
}
