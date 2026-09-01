<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Actions\Comments\PostComment;
use App\Enums\CommentStream;
use App\Livewire\Tickets\Components\Comments as CommentsPanel;
use App\Livewire\Tickets\Show as TicketShow;
use App\Models\Comment;
use App\Services\CommentReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A customer must never receive an internal note, by any route.
 *
 * Internal notes are the place where the delivery team says the things they
 * would not say in front of the customer, so this is the most consequential
 * boundary added in Phase 3. Each test closes one specific way it could be
 * crossed: the rendered thread, the Livewire payload, a guessed id, the count,
 * the composer, and the ticket the note hangs off.
 */
class CommentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_thread_never_renders_an_internal_note_to_a_customer(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);

        $this->commentOn($ticket, $team, 'The client is three weeks late paying', CommentStream::Internal);
        $this->commentOn($ticket, $team, 'We have picked this up, thank you', CommentStream::Customer);

        $this->actingAs($customer)
            ->get(route('tickets.show', ['board' => $board, 'number' => $ticket->number]))
            ->assertOk()
            ->assertSee('We have picked this up, thank you')
            ->assertDontSee('three weeks late');
    }

    /**
     * The rendered HTML is not the only thing sent to the browser: Livewire
     * serialises component state into the page too. Neither may contain the
     * note.
     */
    public function test_no_part_of_the_customer_response_contains_internal_note_data(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);

        $note = $this->commentOn($ticket, $team, 'Escalate to legal if they push back', CommentStream::Internal);

        $html = $this->actingAs($customer)
            ->get(route('tickets.show', ['board' => $board, 'number' => $ticket->number]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Escalate to legal', $html);
        $this->assertStringNotContainsString('comment-'.$note->getKey(), $html);
        $this->assertStringNotContainsString('Internal notes', $html);
    }

    public function test_a_customer_cannot_read_an_internal_note_through_the_reader(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);

        $this->commentOn($ticket, $team, 'Internal', CommentStream::Internal);
        $this->commentOn($ticket, $team, 'External', CommentStream::Customer);

        $reader = app(CommentReader::class);

        // Asking for the internal stream explicitly is harmless: the stream
        // filter composes on top of a scope that has already excluded it.
        $this->assertCount(0, $reader->forStream($ticket, $customer, CommentStream::Internal));
        $this->assertCount(1, $reader->forStream($ticket, $customer, CommentStream::Customer));
        $this->assertCount(1, $reader->forStream($ticket, $team, CommentStream::Internal));
    }

    public function test_a_customer_cannot_reach_an_internal_note_by_its_id(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);
        $note = $this->commentOn($ticket, $team, 'Internal', CommentStream::Internal);

        // Editing and deleting both resolve through the reader first, so a
        // guessed id is not found rather than found and refused.
        Livewire::actingAs($customer)
            ->test(CommentsPanel::class, ['ticket' => $ticket])
            ->call('startEditing', $note->getKey())
            ->assertNotFound();

        Livewire::actingAs($customer)
            ->test(CommentsPanel::class, ['ticket' => $ticket])
            ->call('remove', $note->getKey())
            ->assertNotFound();

        $this->assertNotNull(Comment::query()->find($note->getKey()));
    }

    /**
     * "3 notes you cannot open" would tell the customer that internal
     * discussion is happening, and roughly how much.
     */
    public function test_the_internal_note_count_is_never_exposed_to_a_customer(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);

        $this->commentOn($ticket, $team, 'One', CommentStream::Internal);
        $this->commentOn($ticket, $team, 'Two', CommentStream::Internal);
        $this->commentOn($ticket, $team, 'Visible', CommentStream::Customer);

        $counts = app(CommentReader::class)->countsByStream($ticket, $customer);

        $this->assertArrayNotHasKey(CommentStream::Internal->value, $counts);
        $this->assertSame(1, $counts[CommentStream::Customer->value] ?? 0);

        $staffCounts = app(CommentReader::class)->countsByStream($ticket, $team);

        $this->assertSame(2, $staffCounts[CommentStream::Internal->value] ?? 0);
    }

    public function test_a_customer_cannot_open_the_internal_tab(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);

        Livewire::actingAs($customer)
            ->test(CommentsPanel::class, ['ticket' => $ticket])
            ->assertSet('stream', CommentStream::Customer->value)
            ->call('switchStream', CommentStream::Internal->value)
            ->assertNotFound();

        // The refused request changed nothing: a fresh mount is still on the
        // customer conversation.
        Livewire::actingAs($customer)
            ->test(CommentsPanel::class, ['ticket' => $ticket])
            ->assertSet('stream', CommentStream::Customer->value);
    }

    /**
     * Even when the request says "internal", a customer's words go to the
     * customer stream. The rule is in the action, not the form.
     */
    public function test_a_customer_comment_is_forced_into_the_customer_stream(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);

        $comment = app(PostComment::class)->handle(
            $ticket,
            'Any update?',
            CommentStream::Internal,
            $customer
        );

        $this->assertSame(CommentStream::Customer, $comment->stream);
        $this->assertFalse($comment->isInternal());
    }

    /**
     * The other half of the boundary: a customer-stream comment on an internal
     * ticket is protected because the ticket is.
     */
    public function test_a_customer_cannot_read_any_comment_on_an_internal_ticket(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $internalTicket = $this->ticketOn($board, $team, ['title' => 'Internal work']);

        $this->commentOn($internalTicket, $team, 'Customer-stream words', CommentStream::Customer);

        Livewire::actingAs($customer)
            ->test(CommentsPanel::class, ['ticket' => $internalTicket])
            ->assertNotFound();

        $this->assertCount(
            0,
            app(CommentReader::class)->forStream($internalTicket, $customer, CommentStream::Customer)
        );
    }

    public function test_a_non_member_receives_no_comments_at_all(): void
    {
        $team = $this->teamMember();
        $outsider = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);
        $this->commentOn($ticket, $team, 'Members only', CommentStream::Customer);

        $this->assertCount(
            0,
            app(CommentReader::class)->forStream($ticket, $outsider, CommentStream::Customer)
        );

        $this->assertCount(0, Comment::query()->visibleTo($outsider)->get());
    }

    public function test_a_deleted_note_disappears_from_every_read(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team);
        $note = $this->commentOn($ticket, $team, 'Struck from the record', CommentStream::Internal);

        Livewire::actingAs($team)
            ->test(CommentsPanel::class, ['ticket' => $ticket])
            ->call('remove', $note->getKey());

        $reader = app(CommentReader::class);

        $this->assertCount(0, $reader->forStream($ticket, $team, CommentStream::Internal));
        $this->assertSame(0, $reader->countsByStream($ticket, $team)[CommentStream::Internal->value] ?? 0);

        $this->actingAs($team)
            ->get(route('tickets.show', ['board' => $board, 'number' => $ticket->number]))
            ->assertOk()
            ->assertDontSee('Struck from the record');
    }

    public function test_the_ticket_page_shows_staff_both_streams_and_customers_one(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->assertSee('Internal notes')
            ->assertSee('Customer conversation');

        Livewire::actingAs($customer)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->assertDontSee('Internal notes')
            ->assertSee('Customer conversation');
    }
}
