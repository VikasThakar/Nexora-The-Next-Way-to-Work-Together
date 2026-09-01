<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Actions\Comments\DeleteComment;
use App\Actions\Tickets\UpdateTicket;
use App\Enums\CommentStream;
use App\Livewire\Notifications\Bell;
use App\Models\Comment;
use App\Services\NotificationReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A customer must never be told about something they cannot see.
 *
 * Notifications are a peripheral surface, and peripheral surfaces are where
 * copies of the visibility rules go stale. Two design choices are under test
 * here: recipients are filtered by the same policy that guards the record, and
 * the stored payload holds identifiers only, so rendering has to re-read the
 * subject and drops it when it is no longer readable.
 */
class NotificationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_is_never_notified_about_an_internal_note(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        // The customer raised the ticket, so they are a participant and would
        // otherwise be a recipient of everything posted on it.
        $ticket = $this->ticketOn($board, $customer, ['title' => 'Please investigate']);

        $this->commentOn($ticket, $team, 'Internal thinking', CommentStream::Internal);

        $this->assertSame(0, $customer->fresh()->notifications()->count());

        // The customer-facing reply does reach them, which is what makes the
        // absence above meaningful rather than the feature simply not working.
        $this->commentOn($ticket, $team, 'We are on it', CommentStream::Customer);

        $this->assertSame(1, $customer->fresh()->notifications()->count());
    }

    /**
     * Mentions are the sharpest edge: writing "@customer" in an internal note
     * must not resolve, let alone notify.
     */
    public function test_mentioning_a_customer_in_an_internal_note_notifies_nobody(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer(['name' => 'Casey Client', 'email' => 'casey@client.test']);
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);

        $this->commentOn($ticket, $team, 'Do not tell @casey about the delay yet', CommentStream::Internal);

        $this->assertSame(0, $customer->fresh()->notifications()->count());
    }

    public function test_mentioning_a_teammate_in_an_internal_note_does_notify(): void
    {
        $team = $this->teamMember();
        $colleague = $this->teamMember(['name' => 'Robin Dev', 'email' => 'robin@aqueduct.test']);
        $board = $this->boardWithColumns([$team, $colleague]);

        $ticket = $this->ticketOn($board, $team);

        $this->commentOn($ticket, $team, 'Can you look at this @robin?', CommentStream::Internal);

        $this->assertSame(1, $colleague->fresh()->notifications()->count());
    }

    /**
     * Authorization is not frozen at the moment a notification is written.
     */
    public function test_a_notification_disappears_when_its_ticket_becomes_internal(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $customer, ['title' => 'Please investigate']);

        $this->commentOn($ticket, $team, 'A reply the customer can read', CommentStream::Customer);

        $reader = app(NotificationReader::class);

        $this->assertCount(1, $reader->items($customer->fresh()));
        $this->assertSame(1, $reader->unreadCount($customer->fresh()));

        // The team decides this ticket should not have been shared after all.
        app(UpdateTicket::class)->handle($ticket, ['customer_visible' => false], $team);

        $this->assertCount(0, $reader->items($customer->fresh()));
        $this->assertSame(0, $reader->unreadCount($customer->fresh()));
    }

    public function test_a_notification_disappears_when_its_comment_is_deleted(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $customer);
        $comment = $this->commentOn($ticket, $team, 'A reply', CommentStream::Customer);

        $reader = app(NotificationReader::class);
        $this->assertCount(1, $reader->items($customer->fresh()));

        app(DeleteComment::class)->handle($comment, $team);

        $this->assertCount(0, $reader->items($customer->fresh()));
    }

    /**
     * A badge of 3 above a list of 1 would itself say that two things happened
     * which the reader is not allowed to see.
     */
    public function test_the_unread_badge_counts_only_what_the_list_can_show(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $visible = $this->ticketOn($board, $customer, ['title' => 'Shown']);
        $retracted = $this->ticketOn($board, $customer, ['title' => 'Later hidden']);

        $this->commentOn($visible, $team, 'One', CommentStream::Customer);
        $this->commentOn($retracted, $team, 'Two', CommentStream::Customer);

        app(UpdateTicket::class)->handle($retracted, ['customer_visible' => false], $team);

        $reader = app(NotificationReader::class);
        $fresh = $customer->fresh();

        $this->assertSame(2, $fresh->notifications()->count(), 'Both rows still exist');
        $this->assertCount(1, $reader->items($fresh));
        $this->assertSame(1, $reader->unreadCount($fresh));
    }

    public function test_the_bell_never_renders_content_from_a_hidden_notification(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $customer, ['title' => 'Commercially sensitive title']);

        $this->commentOn($ticket, $team, 'Reply', CommentStream::Customer);

        app(UpdateTicket::class)->handle($ticket, ['customer_visible' => false], $team);

        Livewire::actingAs($customer->fresh())
            ->test(Bell::class)
            ->assertSee('Nothing yet')
            ->assertDontSee('Commercially sensitive title');
    }

    public function test_one_person_cannot_mark_another_persons_notification_read(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $other = $this->customer();
        $board = $this->boardWithColumns([$team, $customer, $other]);

        $ticket = $this->ticketOn($board, $customer);
        $this->commentOn($ticket, $team, 'Reply', CommentStream::Customer);

        $notification = $customer->fresh()->notifications()->sole();

        Livewire::actingAs($other)
            ->test(Bell::class)
            ->call('markRead', $notification->id);

        $this->assertNull($customer->fresh()->notifications()->sole()->read_at);
    }

    /**
     * Assignment notifications go through the same policy filter.
     */
    public function test_a_notification_is_not_written_for_somebody_who_cannot_see_the_ticket(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $outsider = $this->teamMember();

        $ticket = $this->ticketOn($board, $team);

        // Forced past the action's own guard, so only the dispatcher's policy
        // check stands between an outsider and a notification naming a ticket
        // on a board they are not a member of.
        $ticket->assignee_id = $outsider->getKey();
        $ticket->save();

        $this->assertSame(0, $outsider->fresh()->notifications()->count());
    }

    public function test_the_stored_payload_holds_identifiers_only(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $customer, ['title' => 'Distinctive ticket title']);
        $this->commentOn($ticket, $team, 'Distinctive comment body', CommentStream::Customer);

        $payload = json_encode($customer->fresh()->notifications()->sole()->data);

        $this->assertStringNotContainsString('Distinctive ticket title', (string) $payload);
        $this->assertStringNotContainsString('Distinctive comment body', (string) $payload);
        $this->assertStringContainsString('"ticket_id"', (string) $payload);
    }

    public function test_an_internal_note_notification_is_never_written_for_a_customer_participant(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $customer);

        // The customer has taken part in the customer stream.
        $this->commentOn($ticket, $customer, 'Any news?', CommentStream::Customer);

        $this->assertSame(0, $customer->fresh()->notifications()->count());

        $note = $this->commentOn($ticket, $team, 'Internal follow-up', CommentStream::Internal);

        $this->assertSame(CommentStream::Internal, $note->stream);
        $this->assertSame(0, $customer->fresh()->notifications()->count());

        // And the note itself is not readable, which is the reason why.
        $this->assertNull(Comment::query()->visibleTo($customer)->find($note->getKey()));
    }
}
