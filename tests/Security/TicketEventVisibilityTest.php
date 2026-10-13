<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Actions\Tickets\MoveTicket;
use App\Actions\Tickets\UpdateTicket;
use App\Enums\TicketEventType;
use App\Livewire\Stats\Customer as CustomerStats;
use App\Models\Board;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Services\AI\AiContextScope;
use App\Services\AI\AssistantContextBuilder;
use App\Services\Statistics\CustomerStatistics;
use App\Services\Statistics\FlowMetrics;
use App\Services\Statistics\StatisticsScope;
use App\Support\StatsPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ticket history, as it crosses the customer boundary.
 *
 * Board membership is not enough for this table. Every event belongs to a
 * ticket, and an internal ticket's history is as internal as the ticket: its
 * key, who moved it and when it was finished all describe work the customer
 * was never shown. These tests pin the ticket rule onto the event scope, and
 * then check the three places that read history for a customer — the
 * statistics page, the completion counts and the assistant's board context —
 * because each of them leaked before the rule existed.
 */
class TicketEventVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_reads_history_only_for_tickets_shared_with_them(): void
    {
        [$team, $customer, $board] = $this->sharedBoard();

        $shared = $this->ticketOn($board, $team, ['title' => 'Shared', 'customer_visible' => true]);
        $internal = $this->ticketOn($board, $team, ['title' => 'Internal']);

        $this->moveTo($shared, $board, 'In Progress', $team);
        $this->moveTo($internal, $board, 'In Progress', $team);

        $customerSees = TicketEvent::query()->readableBy($customer)->pluck('ticket_id')->unique()->values()->all();

        $this->assertSame([$shared->getKey()], $customerSees);
    }

    public function test_staff_still_read_the_history_of_every_ticket_on_their_board(): void
    {
        [$team, , $board] = $this->sharedBoard();

        $shared = $this->ticketOn($board, $team, ['title' => 'Shared', 'customer_visible' => true]);
        $internal = $this->ticketOn($board, $team, ['title' => 'Internal']);

        $teamSees = TicketEvent::query()->readableBy($team)->pluck('ticket_id')->unique()->sort()->values()->all();

        $this->assertSame([$shared->getKey(), $internal->getKey()], $teamSees);
    }

    public function test_a_ticket_shared_later_shows_its_history_but_not_the_visibility_change(): void
    {
        [$team, $customer, $board] = $this->sharedBoard();

        $ticket = $this->ticketOn($board, $team, ['title' => 'Later shared']);
        $this->moveTo($ticket, $board, 'In Progress', $team);

        app(UpdateTicket::class)->handle($ticket, ['customer_visible' => true], $team);

        $types = TicketEvent::query()
            ->readableBy($customer)
            ->where('ticket_events.ticket_id', $ticket->getKey())
            ->pluck('type')
            ->map(fn (TicketEventType $type): string => $type->value)
            ->all();

        $this->assertContains(TicketEventType::TicketCreated->value, $types);
        $this->assertContains(TicketEventType::TicketMoved->value, $types);
        $this->assertNotContains(TicketEventType::VisibilityChanged->value, $types);
    }

    public function test_the_customer_statistics_page_lists_no_activity_on_internal_tickets(): void
    {
        [$team, $customer, $board] = $this->sharedBoard(['ticket_prefix' => 'LKT']);

        $shared = $this->ticketOn($board, $team, ['title' => 'Shared', 'customer_visible' => true]);
        $internal = $this->ticketOn($board, $team, ['title' => 'Internal']);

        $this->moveTo($internal, $board, 'Review', $team);

        $activity = app(CustomerStatistics::class)->recentActivity($this->scope($customer));

        $this->assertNotEmpty($activity);
        $this->assertSame(
            [$shared->getKey()],
            $activity->pluck('ticket_id')->unique()->values()->all(),
        );

        Livewire::actingAs($customer)
            ->test(CustomerStats::class)
            ->assertSee($shared->key())
            ->assertDontSee($internal->key());
    }

    public function test_completion_counts_for_a_customer_exclude_internal_tickets(): void
    {
        [$team, $customer, $board] = $this->sharedBoard();

        Carbon::setTestNow('2026-06-01 09:00:00');
        $shared = $this->ticketOn($board, $team, ['title' => 'Shared', 'customer_visible' => true]);
        $internal = $this->ticketOn($board, $team, ['title' => 'Internal']);

        Carbon::setTestNow('2026-06-03 09:00:00');
        $this->moveTo($shared, $board, 'Done', $team);
        $this->moveTo($internal, $board, 'Done', $team);

        Carbon::setTestNow('2026-06-10 12:00:00');

        $flow = app(FlowMetrics::class);

        // One for the customer, two for the team. The number is the assertion:
        // a leak here has no visible symptom.
        $this->assertSame([$shared->getKey()], $flow->closures($this->scope($customer))->keys()->all());
        $this->assertCount(2, $flow->closures($this->scope($team)));

        $this->assertSame(1, array_sum(array_column($flow->throughputByWeek($this->scope($customer)), 'value')));
    }

    public function test_the_customer_assistant_context_carries_no_history_of_internal_tickets(): void
    {
        [$team, $customer, $board] = $this->sharedBoard();

        $this->ticketOn($board, $team, ['title' => 'Shared', 'customer_visible' => true]);
        $internal = $this->ticketOn($board, $team, ['title' => 'Internal']);
        $this->moveTo($internal, $board, 'Review', $team);

        $context = app(AssistantContextBuilder::class)->build(AiContextScope::board($board), $customer);

        $this->assertStringContainsString('RECENT ACTIVITY', $context);
        $this->assertStringNotContainsString('on ticket #'.$internal->number, $context);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: User, 1: User, 2: Board}
     */
    private function sharedBoard(array $attributes = []): array
    {
        $team = $this->teamMember();
        $customer = $this->customer();

        return [$team, $customer, $this->boardWithColumns([$team, $customer], $attributes)];
    }

    private function scope(User $viewer): StatisticsScope
    {
        return StatisticsScope::for($viewer, StatsPeriod::preset(StatsPeriod::LAST_30_DAYS, 'UTC'));
    }

    private function moveTo(Ticket $ticket, Board $board, string $column, User $actor): void
    {
        app(MoveTicket::class)->handle($ticket, $this->columnNamed($board, $column), 0, $actor);
    }
}
