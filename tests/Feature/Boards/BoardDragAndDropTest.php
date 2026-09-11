<?php

declare(strict_types=1);

namespace Tests\Feature\Boards;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dragging a card between columns.
 *
 * A drop is the whole status change on this board: a ticket's status is the
 * column it sits in, so x-sort's handler and the Status select on the
 * ticket page end in the same call to MoveTicket.
 *
 * The drag itself cannot be exercised without a browser, so these tests pin
 * the two things that break it and that a server-side test can see: the markup
 * Livewire and SortableJS need, and the fact that it is only emitted for people
 * allowed to move a ticket. That the move itself works, and that a customer is
 * refused one, is covered by TicketMovementTest.
 */
class BoardDragAndDropTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_board_is_sortable_for_staff(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $backlog = $this->columnNamed($board, 'Backlog');
        $progress = $this->columnNamed($board, 'In Progress');

        $ticket = $this->ticketOn($board, $team);

        $html = $this->actingAs($team)
            ->get(route('boards.show', $board))
            ->assertOk()
            ->getContent();

        // Every column is a drop target, and they share one group so a card
        // can be dragged between them rather than only within one.
        $this->assertStringContainsString('x-sort:group="board-'.$board->id.'"', $html);

        // Each column bakes its own id into the handler, so the server is told
        // where the card landed rather than having to infer it.
        $this->assertStringContainsString(
            '$wire.moveTicket($item, $position, '.$backlog->id.')',
            $html
        );
        $this->assertStringContainsString(
            '$wire.moveTicket($item, $position, '.$progress->id.')',
            $html
        );

        $this->assertStringContainsString('x-sort:item="'.$ticket->id.'"', $html);

        /*
         * x-sort, and specifically not Livewire's wire:sort wrapper.
         *
         * wire:sort hands the expression to Livewire's evaluator before Alpine
         * sees it, and that rewrites any identifier it does not recognise into
         * a property of $wire — including the Sort plugin's own $item and
         * $position. They become $wire.$item and $wire.$position, which resolve
         * to Livewire's "call a method of this name" fallback, and every drop
         * reaches the server as moveTicket(null, null, 5).
         *
         * That is a silent swap somebody could make while tidying, so the
         * absence is asserted rather than left to the comment in the view.
         */
        $this->assertStringNotContainsString('wire:sort', $html);
    }

    /**
     * The options that make a touch screen work.
     *
     * Without the delay a finger swiping to scroll a column picks a card up
     * instead, and with pointer events the browser is free to scroll the column
     * during the drag as well. Both are easy to drop in a refactor and neither
     * shows up on a laptop, which is why they are asserted rather than trusted.
     */
    public function test_the_drop_zone_is_configured_for_touch(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->ticketOn($board, $team);

        $html = $this->actingAs($team)
            ->get(route('boards.show', $board))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('delay: 180', $html);
        $this->assertStringContainsString('delayOnTouchOnly: true', $html);
        $this->assertStringContainsString('supportPointer: false', $html);

        // The drag mirror is SortableJS's own and lives on the body, so the
        // column's new overflow cannot clip the card being dragged.
        $this->assertStringContainsString('forceFallback: true', $html);
        $this->assertStringContainsString('fallbackOnBody: true', $html);
    }

    /**
     * Seven cards, then a scroll.
     *
     * The height is measured in the browser, so all a server-side test can
     * check is that the column is a scroll box at all — without the overflow
     * there is nothing for the measured cap to cap.
     */
    public function test_a_column_is_a_scroll_box(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->ticketOn($board, $team);

        $html = $this->actingAs($team)
            ->get(route('boards.show', $board))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('x-data="boardColumn"', $html);
        $this->assertStringContainsString('column-scroll', $html);
        $this->assertStringContainsString('overflow-y-auto', $html);
    }

    public function test_the_board_is_not_sortable_for_a_customer(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $this->ticketOn($board, $team, ['customer_visible' => true]);

        $html = $this->actingAs($customer)
            ->get(route('boards.show', $board))
            ->assertOk()
            ->getContent();

        // Not a security control — TicketPolicy::move is — but the drop target
        // should not be offered at all.
        $this->assertStringNotContainsString('x-sort', $html);
        $this->assertStringNotContainsString('moveTicket', $html);

        // The column still scrolls; a customer reads a long backlog too.
        $this->assertStringContainsString('x-data="boardColumn"', $html);
    }
}
