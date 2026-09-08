<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Actions\Columns\DeleteColumn;
use App\Actions\Tickets\MoveTicket;
use App\Actions\Tickets\UpdateTicket;
use App\Enums\TicketPriority;
use App\Livewire\Notifications\Bell;
use App\Models\User;
use App\Notifications\TicketPriorityChanged;
use App\Notifications\TicketStatusChanged;
use App\Services\NotificationReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The in-app notifications a ticket produces.
 *
 * Two of these are new: a status change and a priority change. Both were on the
 * client's list and neither existed, which the audit in this phase's report
 * sets out alongside the two that did.
 *
 * The interesting tests are the negative ones. A notification system earns its
 * keep by what it stays quiet about — a bell that fires for everything is a
 * bell people switch off, and then the ones that mattered are gone too.
 */
class TicketNotificationTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Status
    // -----------------------------------------------------------------

    public function test_moving_a_ticket_notifies_its_assignee(): void
    {
        $mover = $this->teamMember(['name' => 'Ada Fell']);
        $assignee = $this->teamMember();
        $board = $this->boardWithColumns([$mover, $assignee]);

        $ticket = $this->ticketOn($board, $mover, ['assignee_id' => $assignee->getKey()]);

        $this->actingAs($mover);
        $this->clearNotifications();

        app(MoveTicket::class)->handle($ticket, $this->columnNamed($board, 'In Progress'), 0, $mover);

        $this->assertSame(1, $this->notificationsOf($assignee, TicketStatusChanged::TYPE));

        // The column is named from the live record, so a rename reads correctly
        // in a notification written before it.
        Livewire::actingAs($assignee)
            ->test(Bell::class)
            ->assertSee('Ada Fell moved '.$ticket->key().' to In Progress');
    }

    public function test_moving_a_ticket_notifies_the_person_who_raised_it(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $customer, ['title' => 'Login is broken']);

        $this->actingAs($team);
        $this->clearNotifications();

        app(MoveTicket::class)->handle($ticket, $this->columnNamed($board, 'In Progress'), 0, $team);

        // A customer learns nothing new: the column of their own ticket is on
        // the page they can already open.
        $this->assertSame(1, $this->notificationsOf($customer, TicketStatusChanged::TYPE));
    }

    public function test_moving_your_own_ticket_notifies_nobody(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team, ['assignee_id' => $team->getKey()]);

        $this->actingAs($team);
        $this->clearNotifications();

        app(MoveTicket::class)->handle($ticket, $this->columnNamed($board, 'In Progress'), 0, $team);

        $this->assertSame(0, $this->notificationsOf($team, TicketStatusChanged::TYPE));
    }

    /**
     * The failure this feature would otherwise introduce.
     */
    public function test_deleting_a_column_does_not_notify_everybody_it_moves(): void
    {
        $admin = $this->admin();
        $assignee = $this->teamMember();
        $board = $this->boardWithColumns([$admin, $assignee]);

        $doomed = $this->columnNamed($board, 'Backlog');
        $destination = $this->columnNamed($board, 'To Do');

        foreach (range(1, 4) as $n) {
            $this->ticketOn($board, $admin, [
                'title' => 'Card '.$n,
                'assignee_id' => $assignee->getKey(),
            ]);
        }

        $this->actingAs($admin);
        $this->clearNotifications();

        app(DeleteColumn::class)->handle($doomed, $destination, $admin);

        // Tidying a board is not four pieces of news.
        $this->assertSame(0, $this->notificationsOf($assignee, TicketStatusChanged::TYPE));

        // The moves are still recorded, and the timeline says why.
        $this->assertSame(
            4,
            $board->tickets()->where('board_column_id', $destination->getKey())->count()
        );
    }

    public function test_reordering_within_a_column_notifies_nobody(): void
    {
        $team = $this->teamMember();
        $assignee = $this->teamMember();
        $board = $this->boardWithColumns([$team, $assignee]);

        $first = $this->ticketOn($board, $team, ['assignee_id' => $assignee->getKey()]);
        $this->ticketOn($board, $team, ['assignee_id' => $assignee->getKey()]);

        $this->actingAs($team);
        $this->clearNotifications();

        // Same column, new position: not a status change.
        app(MoveTicket::class)->handle($first, $this->columnNamed($board, 'Backlog'), 1, $team);

        $this->assertSame(0, $this->notificationsOf($assignee, TicketStatusChanged::TYPE));
    }

    // -----------------------------------------------------------------
    // Priority
    // -----------------------------------------------------------------

    public function test_raising_the_priority_notifies_the_assignee(): void
    {
        $lead = $this->teamMember(['name' => 'Bo Nadir']);
        $assignee = $this->teamMember();
        $board = $this->boardWithColumns([$lead, $assignee]);

        $ticket = $this->ticketOn($board, $lead, [
            'assignee_id' => $assignee->getKey(),
            'priority' => TicketPriority::Low->value,
        ]);

        $this->actingAs($lead);
        $this->clearNotifications();

        app(UpdateTicket::class)->handle($ticket, ['priority' => TicketPriority::Critical->value], $lead);

        $this->assertSame(1, $this->notificationsOf($assignee, TicketPriorityChanged::TYPE));

        Livewire::actingAs($assignee)
            ->test(Bell::class)
            ->assertSee('Bo Nadir set '.$ticket->key().' to '.TicketPriority::Critical->label());
    }

    /**
     * Priority is the delivery team's own ordering decision.
     */
    public function test_the_customer_who_raised_a_ticket_is_not_told_about_its_priority(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $customer, ['priority' => TicketPriority::Low->value]);

        $this->actingAs($team);
        $this->clearNotifications();

        app(UpdateTicket::class)->handle($ticket, ['priority' => TicketPriority::High->value], $team);

        $this->assertSame(0, $this->notificationsOf($customer, TicketPriorityChanged::TYPE));
    }

    public function test_an_unassigned_ticket_produces_no_priority_notification(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['priority' => TicketPriority::Low->value]);

        $this->actingAs($team);
        $this->clearNotifications();

        app(UpdateTicket::class)->handle($ticket, ['priority' => TicketPriority::High->value], $team);

        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_a_priority_that_did_not_change_notifies_nobody(): void
    {
        $lead = $this->teamMember();
        $assignee = $this->teamMember();
        $board = $this->boardWithColumns([$lead, $assignee]);

        $ticket = $this->ticketOn($board, $lead, [
            'assignee_id' => $assignee->getKey(),
            'priority' => TicketPriority::High->value,
        ]);

        $this->actingAs($lead);
        $this->clearNotifications();

        app(UpdateTicket::class)->handle($ticket, ['priority' => TicketPriority::High->value], $lead);

        $this->assertSame(0, $this->notificationsOf($assignee, TicketPriorityChanged::TYPE));
    }

    // -----------------------------------------------------------------
    // The payload, and the bell
    // -----------------------------------------------------------------

    public function test_the_new_payloads_hold_identifiers_only(): void
    {
        $lead = $this->teamMember();
        $assignee = $this->teamMember();
        $board = $this->boardWithColumns([$lead, $assignee]);

        $ticket = $this->ticketOn($board, $lead, [
            'title' => 'Rotate the production credentials',
            'assignee_id' => $assignee->getKey(),
            'priority' => TicketPriority::Low->value,
        ]);

        $this->actingAs($lead);
        $this->clearNotifications();

        app(MoveTicket::class)->handle($ticket, $this->columnNamed($board, 'In Progress'), 0, $lead);
        app(UpdateTicket::class)->handle($ticket->refresh(), ['priority' => TicketPriority::Critical->value], $lead);

        $payloads = $assignee->notifications()->pluck('data')->map(
            fn ($data): array => is_array($data) ? $data : (array) json_decode((string) $data, true)
        );

        $this->assertCount(2, $payloads);

        foreach ($payloads as $payload) {
            // No title, no column name, no priority label — nothing that would
            // keep being displayed after the thing stopped being readable.
            $this->assertStringNotContainsString(
                'Rotate the production credentials',
                json_encode($payload) ?: ''
            );
            $this->assertStringNotContainsString('In Progress', json_encode($payload) ?: '');

            foreach (array_keys($payload) as $key) {
                $this->assertContains($key, [
                    'type', 'board_id', 'ticket_id', 'comment_id', 'actor_id', 'column_id', 'priority',
                ], 'Unexpected key in a notification payload: '.$key);
            }
        }
    }

    /**
     * A deleted column must not make a notification unrenderable.
     */
    public function test_a_status_notification_survives_its_column_being_deleted(): void
    {
        $admin = $this->admin();
        $assignee = $this->teamMember();
        $board = $this->boardWithColumns([$admin, $assignee]);

        $ticket = $this->ticketOn($board, $admin, ['assignee_id' => $assignee->getKey()]);

        $this->actingAs($admin);
        $this->clearNotifications();

        $moved = $this->columnNamed($board, 'In Progress');
        app(MoveTicket::class)->handle($ticket, $moved, 0, $admin);

        app(DeleteColumn::class)->handle($moved->refresh(), $this->columnNamed($board, 'To Do'), $admin);

        // The move happened, so it is still reported — just without a column
        // that no longer exists.
        Livewire::actingAs($assignee)
            ->test(Bell::class)
            ->assertSee('moved '.$ticket->key())
            ->assertDontSee('In Progress');
    }

    public function test_the_bell_says_when_it_is_showing_only_the_most_recent(): void
    {
        $lead = $this->teamMember();
        $assignee = $this->teamMember();
        $board = $this->boardWithColumns([$lead, $assignee]);

        $ticket = $this->ticketOn($board, $lead, ['assignee_id' => $assignee->getKey()]);

        $this->actingAs($lead);
        $this->clearNotifications();

        // More than the bell shows at once, and fewer than the reader's window.
        $columns = ['Backlog', 'To Do', 'In Progress', 'Review'];

        foreach (range(1, NotificationReader::PAGE + 3) as $n) {
            app(MoveTicket::class)->handle(
                $ticket->refresh(),
                $this->columnNamed($board, $columns[$n % count($columns)]),
                0,
                $lead,
            );
        }

        $component = Livewire::actingAs($assignee)->test(Bell::class);

        // Without this line a badge of eighteen above a list of fifteen reads
        // as a miscount rather than as a truncation.
        $component->assertSee('older notifications not shown');
    }

    public function test_the_bell_stays_quiet_about_truncation_when_there_is_none(): void
    {
        $lead = $this->teamMember();
        $assignee = $this->teamMember();
        $board = $this->boardWithColumns([$lead, $assignee]);

        $ticket = $this->ticketOn($board, $lead, ['assignee_id' => $assignee->getKey()]);

        $this->actingAs($lead);
        $this->clearNotifications();

        app(MoveTicket::class)->handle($ticket, $this->columnNamed($board, 'In Progress'), 0, $lead);

        Livewire::actingAs($assignee)
            ->test(Bell::class)
            ->assertSee('moved '.$ticket->key())
            ->assertDontSee('not shown');
    }

    // -----------------------------------------------------------------

    /**
     * Creating a ticket already notifies its assignee, which would otherwise
     * be counted by every assertion in this file.
     */
    private function clearNotifications(): void
    {
        DB::table('notifications')->delete();
    }

    private function notificationsOf(User $user, string $type): int
    {
        return $user->notifications()
            ->get()
            ->filter(function ($row) use ($type): bool {
                $data = is_array($row->data) ? $row->data : (array) json_decode((string) $row->data, true);

                return ($data['type'] ?? null) === $type;
            })
            ->count();
    }
}
