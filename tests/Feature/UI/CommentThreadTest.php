<?php

declare(strict_types=1);

namespace Tests\Feature\UI;

use App\Enums\CommentStream;
use App\Livewire\Tickets\Components\Comments as CommentsPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The conversation scrolls inside itself.
 *
 * How tall the box ends up is measured in the browser — resources/js/comment-thread.js
 * sizes it to the last few messages, which no server-side test can see. What is
 * checked here is the wiring that measurement depends on, because all of it is
 * markup somebody could quietly drop while restyling the thread: the Alpine
 * component, the overflow that makes it a scroll container, the Tailwind cap
 * that holds the shape before the script runs, and the tabindex that keeps a
 * scrolling region reachable without a mouse.
 */
class CommentThreadTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_thread_is_a_capped_scrolling_region(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);
        $this->commentOn($ticket, $team, 'A note.', CommentStream::Internal);

        $html = Livewire::actingAs($team)
            ->test(CommentsPanel::class, ['ticket' => $ticket])
            ->html();

        $this->assertStringContainsString('x-data="commentThread"', $html);
        $this->assertStringContainsString('overflow-y-auto', $html);

        // The stand-in cap. Without it the thread paints at full height and
        // then collapses on the first frame, which on a long conversation is a
        // visible jump rather than a scroll box.
        $this->assertStringContainsString('max-h-[30rem]', $html);

        // A region that scrolls has to be reachable from the keyboard.
        $this->assertStringContainsString('tabindex="0"', $html);
    }

    /**
     * The two tabs are two different conversations, and a screen reader lands
     * in one of them with no tab strip in view to say which.
     */
    public function test_each_conversation_names_its_own_region(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);
        $this->commentOn($ticket, $team, 'A note.', CommentStream::Internal);
        $this->commentOn($ticket, $team, 'A reply.', CommentStream::Customer);

        // Staff open on the internal tab.
        $panel = Livewire::actingAs($team)->test(CommentsPanel::class, ['ticket' => $ticket]);
        $this->assertStringContainsString('aria-label="Internal notes"', $panel->html());

        $panel->call('switchStream', CommentStream::Customer->value);
        $this->assertStringContainsString('aria-label="Customer conversation"', $panel->html());
    }
}
