<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Actions\Tickets\UpdateTicket;
use App\Livewire\Tickets\Components\Activity as TicketTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The activity card's shape.
 *
 * A ticket's history is unbounded, and the card used to grow with it — a ticket
 * worked on for a month pushed its comments, attachments and links below the
 * fold, so the page got longer the more there was to read on it. The card now
 * has a fixed viewport and scrolls inside itself.
 *
 * Asserted on the markup because that is where the behaviour lives: there is no
 * JavaScript involved and nothing to call. What matters is that the constraint
 * is on the body and not on the card, so the header stays visible, and that
 * nothing is hidden — every event is still in the list, reachable by scrolling.
 */
class ActivityCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_card_scrolls_internally_and_keeps_its_header(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $html = Livewire::actingAs($team)
            ->test(TicketTimeline::class, ['ticket' => $ticket])
            ->html();

        // A bounded viewport with its own scrollbar, taller on a desktop.
        $this->assertStringContainsString('max-h-72', $html);
        $this->assertStringContainsString('sm:max-h-96', $html);
        $this->assertStringContainsString('overflow-y-auto', $html);

        // A flick inside the list must not carry on scrolling the page.
        $this->assertStringContainsString('overscroll-contain', $html);
        $this->assertStringContainsString('scroll-smooth', $html);

        /*
         * The header is x-ui.card's own <header>, so the scroll container must
         * be inside the card body — which is only true when the card is asked
         * not to pad it. If :padded ever comes back, the whole card scrolls and
         * the title goes with it.
         */
        $this->assertStringContainsString('<header', $html);
        $this->assertLessThan(
            strpos($html, 'max-h-72'),
            strpos($html, '<header'),
            'The header must be rendered outside the scrolling region.'
        );

        // Reachable by keyboard, and announced as a region rather than as a
        // silent box that happens to scroll.
        $this->assertStringContainsString('role="region"', $html);
        $this->assertStringContainsString('tabindex="0"', $html);
    }

    public function test_nothing_is_hidden_and_older_events_are_still_reachable(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Busy ticket']);

        // Well past the card's height, and past its own page size.
        foreach (range(1, 20) as $n) {
            app(UpdateTicket::class)->handle($ticket, ['title' => 'Busy ticket '.$n], $team);
        }

        $component = Livewire::actingAs($team)->test(TicketTimeline::class, ['ticket' => $ticket]);

        // The count in the header is the true total, not the number rendered.
        $component->assertSee('events');
        $component->assertSee('Show earlier activity');

        // And the button reaches them rather than the list being truncated for
        // good — which is the difference between a scroll area and hiding
        // activity.
        $component->call('showMore')->call('showMore')->assertDontSee('Show earlier activity');
    }

    public function test_an_empty_timeline_does_not_render_a_scroll_area(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        // Remove the creation event so the timeline is genuinely empty.
        $ticket->events()->delete();

        Livewire::actingAs($team)
            ->test(TicketTimeline::class, ['ticket' => $ticket])
            ->assertSee('Nothing recorded yet.')
            ->assertDontSee('max-h-72');
    }
}
