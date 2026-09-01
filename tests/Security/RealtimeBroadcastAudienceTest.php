<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Actions\Tickets\MoveTicket;
use App\Actions\Tickets\UpdateTicket;
use App\Enums\CommentStream;
use App\Events\BoardUpdated;
use App\Events\NotificationReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * What is broadcast, and to which audience.
 *
 * The companion to RealtimeAuthorizationTest, which covers who may subscribe.
 * This class covers the two things that make a wrong subscription harmless
 * anyway:
 *
 *   1. An event about internal work is only ever sent to the internal channel.
 *      This is not redundant with channel authorization — it closes a timing
 *      side channel. A customer whose browser woke up every time the team
 *      touched something could infer internal activity from the pattern alone,
 *      without ever reading a payload.
 *   2. The payload has no content in it. There is nothing on the wire to leak.
 */
class RealtimeBroadcastAudienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_internal_ticket_change_never_reaches_the_customer_channel(): void
    {
        Event::fake([BoardUpdated::class]);

        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $team, ['title' => 'Internal work']);

        $this->assertTrue($ticket->isInternal());

        Event::assertDispatched(
            BoardUpdated::class,
            fn (BoardUpdated $event): bool => $event->audience === BoardUpdated::AUDIENCE_INTERNAL
        );

        Event::assertNotDispatched(
            BoardUpdated::class,
            fn (BoardUpdated $event): bool => $event->audience === BoardUpdated::AUDIENCE_CUSTOMER
        );
    }

    public function test_a_customer_visible_ticket_change_reaches_both_audiences(): void
    {
        Event::fake([BoardUpdated::class]);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->ticketOn($board, $team, ['customer_visible' => true]);

        Event::assertDispatched(
            BoardUpdated::class,
            fn (BoardUpdated $event): bool => $event->audience === BoardUpdated::AUDIENCE_CUSTOMER
        );

        Event::assertDispatched(
            BoardUpdated::class,
            fn (BoardUpdated $event): bool => $event->audience === BoardUpdated::AUDIENCE_INTERNAL
        );
    }

    /**
     * A ticket that has just been hidden also changes what customers see:
     * their card has to disappear, so they must be told to re-read.
     */
    public function test_hiding_a_ticket_still_signals_the_customer_channel(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);

        Event::fake([BoardUpdated::class]);

        app(UpdateTicket::class)->handle($ticket, ['customer_visible' => false], $team);

        Event::assertDispatched(
            BoardUpdated::class,
            fn (BoardUpdated $event): bool => $event->audience === BoardUpdated::AUDIENCE_CUSTOMER
        );
    }

    public function test_an_internal_note_never_signals_the_customer_channel(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);

        Event::fake([BoardUpdated::class]);

        $this->commentOn($ticket, $team, 'Internal', CommentStream::Internal);

        Event::assertDispatched(
            BoardUpdated::class,
            fn (BoardUpdated $event): bool => $event->audience === BoardUpdated::AUDIENCE_INTERNAL
        );

        Event::assertNotDispatched(
            BoardUpdated::class,
            fn (BoardUpdated $event): bool => $event->audience === BoardUpdated::AUDIENCE_CUSTOMER
        );
    }

    public function test_a_customer_reply_on_an_internal_ticket_never_signals_customers(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        // Customer stream, but on a ticket the customer cannot see.
        $ticket = $this->ticketOn($board, $team, ['title' => 'Internal']);

        Event::fake([BoardUpdated::class]);

        $this->commentOn($ticket, $team, 'Drafting a reply', CommentStream::Customer);

        Event::assertNotDispatched(
            BoardUpdated::class,
            fn (BoardUpdated $event): bool => $event->audience === BoardUpdated::AUDIENCE_CUSTOMER
        );
    }

    public function test_moving_a_ticket_announces_the_change(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);

        Event::fake([BoardUpdated::class]);

        app(MoveTicket::class)->handle($ticket, $this->columnNamed($board, 'Done'), 0, $team);

        Event::assertDispatched(BoardUpdated::class);
    }

    /**
     * The strongest guarantee in the realtime layer: there is nothing on the
     * wire to leak.
     */
    public function test_a_broadcast_payload_carries_no_content(): void
    {
        Event::fake([BoardUpdated::class]);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->ticketOn($board, $team, ['title' => 'Rotate the production credentials']);

        $event = new BoardUpdated((int) $board->getKey(), BoardUpdated::AUDIENCE_INTERNAL);

        $payload = $event->broadcastWith();

        $this->assertSame(['at'], array_keys($payload));
        $this->assertStringNotContainsString(
            'Rotate the production credentials',
            (string) json_encode($payload)
        );

        $this->assertSame(
            'private-board.'.$board->getKey().'.internal',
            $event->broadcastOn()[0]->name
        );

        $this->assertSame('board.updated', $event->broadcastAs());
    }

    /**
     * The notification nudge is content-free for the same reason.
     */
    public function test_the_notification_signal_carries_no_content(): void
    {
        $user = $this->teamMember();

        $event = new NotificationReceived((int) $user->getKey());

        $this->assertSame(['at'], array_keys($event->broadcastWith()));
        $this->assertSame('private-users.'.$user->getKey(), $event->broadcastOn()[0]->name);
    }
}
