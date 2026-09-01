<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Actions\Tickets\UpdateTicket;
use App\Enums\CommentStream;
use App\Livewire\Notifications\Bell;
use App\Services\NotificationReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    public function test_being_assigned_a_ticket_produces_a_notification(): void
    {
        $lead = $this->teamMember(['name' => 'Alex Lead']);
        $engineer = $this->teamMember();
        $board = $this->boardWithColumns([$lead, $engineer]);

        $ticket = $this->ticketOn($board, $lead);

        $this->actingAs($lead);

        app(UpdateTicket::class)->handle($ticket, ['assignee_id' => $engineer->getKey()], $lead);

        $items = app(NotificationReader::class)->items($engineer->fresh());

        $this->assertCount(1, $items);
        $this->assertStringContainsString('assigned', $items->first()->message);
        $this->assertStringContainsString($ticket->key(), $items->first()->message);
    }

    public function test_assigning_a_ticket_to_yourself_notifies_nobody(): void
    {
        $engineer = $this->teamMember();
        $board = $this->boardWithColumns([$engineer]);

        $ticket = $this->ticketOn($board, $engineer);

        $this->actingAs($engineer);

        app(UpdateTicket::class)->handle($ticket, ['assignee_id' => $engineer->getKey()], $engineer);

        $this->assertSame(0, $engineer->fresh()->notifications()->count());
    }

    public function test_a_reply_notifies_the_other_participants_but_not_the_author(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $customer, ['title' => 'Login is slow']);

        $this->commentOn($ticket, $team, 'Looking into it', CommentStream::Customer);

        $this->assertSame(1, $customer->fresh()->notifications()->count());
        $this->assertSame(0, $team->fresh()->notifications()->count());
    }

    public function test_a_mention_produces_a_mention_notification(): void
    {
        $team = $this->teamMember();
        $colleague = $this->teamMember(['name' => 'Sam Reviewer', 'email' => 'sam@aqueduct.test']);
        $board = $this->boardWithColumns([$team, $colleague]);

        $ticket = $this->ticketOn($board, $team);

        $this->commentOn($ticket, $team, 'Second opinion please @sam', CommentStream::Internal);

        $item = app(NotificationReader::class)->items($colleague->fresh())->sole();

        $this->assertStringContainsString('mentioned you', $item->message);
    }

    public function test_a_mention_replaces_rather_than_duplicates_the_reply_notification(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer(['name' => 'Casey Client', 'email' => 'casey@client.test']);
        $board = $this->boardWithColumns([$team, $customer]);

        // The customer raised the ticket, so they are a participant AND
        // mentioned. That must be one notification, not two.
        $ticket = $this->ticketOn($board, $customer);

        $this->commentOn($ticket, $team, 'Over to you @casey', CommentStream::Customer);

        $this->assertSame(1, $customer->fresh()->notifications()->count());
    }

    public function test_the_bell_shows_the_unread_count_and_marks_one_read(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $customer);
        $this->commentOn($ticket, $team, 'A reply', CommentStream::Customer);

        $notification = $customer->fresh()->notifications()->sole();

        $component = Livewire::actingAs($customer->fresh())
            ->test(Bell::class)
            ->assertSee($ticket->key());

        $this->assertSame(1, app(NotificationReader::class)->unreadCount($customer->fresh()));

        $component->call('markRead', $notification->id);

        $this->assertNotNull($customer->fresh()->notifications()->sole()->read_at);
        $this->assertSame(0, app(NotificationReader::class)->unreadCount($customer->fresh()));
    }

    public function test_mark_all_as_read_clears_the_badge(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $first = $this->ticketOn($board, $customer);
        $second = $this->ticketOn($board, $customer);

        $this->commentOn($first, $team, 'One', CommentStream::Customer);
        $this->commentOn($second, $team, 'Two', CommentStream::Customer);

        $this->assertSame(2, app(NotificationReader::class)->unreadCount($customer->fresh()));

        Livewire::actingAs($customer->fresh())
            ->test(Bell::class)
            ->call('markAllRead');

        $this->assertSame(0, app(NotificationReader::class)->unreadCount($customer->fresh()));
    }

    /**
     * Notifications that the reader hides are still marked, or "mark all as
     * read" would appear not to work whenever something had been retracted.
     */
    public function test_mark_all_as_read_also_clears_hidden_notifications(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $customer);
        $this->commentOn($ticket, $team, 'A reply', CommentStream::Customer);

        app(UpdateTicket::class)->handle($ticket, ['customer_visible' => false], $team);

        Livewire::actingAs($customer->fresh())
            ->test(Bell::class)
            ->call('markAllRead');

        $this->assertSame(0, $customer->fresh()->unreadNotifications()->count());
    }

    public function test_the_bell_is_empty_for_somebody_with_nothing(): void
    {
        $user = $this->teamMember();

        Livewire::actingAs($user)
            ->test(Bell::class)
            ->assertSee('Nothing yet');
    }

    public function test_the_bell_appears_on_every_authenticated_page(): void
    {
        $team = $this->teamMember();

        $this->actingAs($team)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Notifications');
    }
}
