<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Actions\Comments\PostComment;
use App\Enums\CommentStream;
use App\Events\BoardUpdated;
use App\Livewire\Tickets\Components\Comments as CommentsPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The ticket conversation, live.
 *
 * A message posted by one person appears in the other person's open ticket
 * without them reloading. Three separate pieces have to line up for that, and
 * each is covered here:
 *
 *   1. Posting broadcasts. PostComment signals the board, on the audience
 *      derived from the stream that was actually stored.
 *   2. The open panel is subscribed. Which channel it listens on is decided
 *      from the viewer's own role — never from anything the browser sent — so
 *      a customer is told to listen on the customer channel and is never even
 *      pointed at the internal one.
 *   3. The signal makes it re-read. The handler is `$refresh`, so the panel
 *      re-runs the same authorized, scoped query it ran on first paint. The
 *      last two tests are the ones that matter: they prove the re-read shows a
 *      staff reply to the customer, and still hides an internal note from them.
 *
 * That third point is why this can be tested at all without a browser. Nothing
 * about who-sees-what depends on the websocket; the socket only says "ask
 * again", and CommentReader answers exactly as it always does.
 */
class LiveConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_replying_to_the_customer_signals_both_audiences(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);

        Event::fake([BoardUpdated::class]);

        app(PostComment::class)->handle($ticket, 'On it now.', CommentStream::Customer, $team);

        // The customer's open page has to hear about this one.
        Event::assertDispatched(
            BoardUpdated::class,
            fn (BoardUpdated $e): bool => $e->boardId === $board->getKey()
                && $e->audience === BoardUpdated::AUDIENCE_CUSTOMER
        );

        // And so does the rest of the team.
        Event::assertDispatched(
            BoardUpdated::class,
            fn (BoardUpdated $e): bool => $e->audience === BoardUpdated::AUDIENCE_INTERNAL
        );
    }

    public function test_the_panel_subscribes_staff_to_the_internal_channel(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        // Switched on only now the fixtures are written: creating a ticket
        // broadcasts, and a real driver would try to reach a websocket server
        // this test has no interest in.
        config(['broadcasting.default' => 'reverb']);

        $listeners = $this->listenersOf(
            Livewire::actingAs($team)->test(CommentsPanel::class, ['ticket' => $ticket])
        );

        $this->assertArrayHasKey(
            'echo-private:board.'.$board->getKey().'.internal,.board.updated',
            $listeners
        );
    }

    /**
     * The one that matters. A customer is pointed at the customer channel and
     * never at the internal one — and would be refused it by
     * routes/channels.php even if the browser asked.
     */
    public function test_the_panel_never_points_a_customer_at_the_internal_channel(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);

        config(['broadcasting.default' => 'reverb']);

        $listeners = $this->listenersOf(
            Livewire::actingAs($customer)->test(CommentsPanel::class, ['ticket' => $ticket])
        );

        $this->assertArrayHasKey(
            'echo-private:board.'.$board->getKey().'.customer,.board.updated',
            $listeners
        );

        foreach (array_keys($listeners) as $channel) {
            $this->assertStringNotContainsString('.internal', $channel);
        }
    }

    /**
     * With no websocket server configured the panel registers nothing, rather
     * than subscribing to a channel that cannot exist and filling the console
     * with "Laravel Echo cannot be found".
     */
    public function test_no_subscription_is_registered_when_realtime_is_off(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        config(['broadcasting.default' => 'null']);

        $this->assertSame([], $this->listenersOf(
            Livewire::actingAs($team)->test(CommentsPanel::class, ['ticket' => $ticket])
        ));
    }

    /**
     * The refresh the broadcast triggers, performed directly: a reply posted
     * after the customer opened the page is in the thread on the next render,
     * with no remount and no reload.
     */
    public function test_a_refresh_brings_in_a_reply_posted_after_the_page_opened(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);

        $panel = Livewire::actingAs($customer)
            ->test(CommentsPanel::class, ['ticket' => $ticket])
            ->assertDontSee('We have shipped the fix');

        app(PostComment::class)->handle($ticket, 'We have shipped the fix', CommentStream::Customer, $team);

        $panel->call('$refresh')->assertSee('We have shipped the fix');
    }

    /**
     * The same refresh, with an internal note. The customer's panel re-reads
     * through CommentReader like always, so live delivery cannot become a
     * second, weaker copy of the visibility rules.
     */
    public function test_a_refresh_still_hides_an_internal_note_from_the_customer(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);

        $panel = Livewire::actingAs($customer)
            ->test(CommentsPanel::class, ['ticket' => $ticket]);

        app(PostComment::class)->handle(
            $ticket,
            'Client is three weeks late paying',
            CommentStream::Internal,
            $team
        );

        $panel->call('$refresh')->assertDontSee('three weeks late');
    }

    /**
     * @param  Testable  $component
     * @return array<string, string>
     */
    private function listenersOf($component): array
    {
        $instance = $component->instance();

        $listeners = new \ReflectionMethod($instance, 'getListeners');
        $listeners->setAccessible(true);

        return $listeners->invoke($instance);
    }
}
