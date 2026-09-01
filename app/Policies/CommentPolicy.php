<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CommentStream;
use App\Models\Comment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Comment authorization.
 *
 * A comment is protected by two independent facts, and both must hold:
 *
 *   1. the ticket it hangs off must be readable — delegated to TicketPolicy,
 *      never re-derived here;
 *   2. its stream must be one this user may observe — the customer boundary.
 *
 * Reading collections goes through App\Services\CommentReader, which applies
 * the same two rules in SQL. This policy is the single-model mirror, used where
 * there is no query to constrain.
 *
 * Write rules:
 *
 *   post to customer stream   anyone who can read the ticket; a customer also
 *                             needs the board to allow customer comments
 *   post to internal stream   staff only
 *   edit                      the author, and nobody else — editing another
 *                             person's words is not a moderation action
 *   delete                    the author, or an administrator
 */
class CommentPolicy
{
    /**
     * Deny as 404: the existence of an internal note is itself internal.
     */
    public function view(User $user, Comment $comment): Response
    {
        return $this->canRead($user, $comment)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * May this user take part in the conversation on this ticket at all?
     *
     * Which stream they may write to is decided by postTo().
     */
    public function create(User $user, Ticket $ticket): Response
    {
        return $user->can('view', $ticket)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * May this user post into a specific stream of this ticket?
     *
     * The one rule that decides who can write an internal note. Called by the
     * Livewire composer and again by App\Actions\Comments\PostComment, so a
     * crafted request cannot reach the internal stream by skipping the UI.
     */
    public function postTo(User $user, Ticket $ticket, CommentStream $stream): Response
    {
        if (! $user->can('view', $ticket)) {
            return Response::denyAsNotFound();
        }

        if (! $stream->isCustomerFacing()) {
            return $user->isStaff()
                ? Response::allow()
                : Response::denyAsNotFound();
        }

        // Customer stream. Staff always; customers only when the board allows
        // it, which is a per-board setting rather than a global one.
        if ($user->isStaff()) {
            return Response::allow();
        }

        $ticket->loadMissing('board');

        return (bool) $ticket->board->setting('customers_can_comment')
            ? Response::allow()
            : Response::deny('Commenting is turned off for this board.');
    }

    public function update(User $user, Comment $comment): Response
    {
        if (! $this->canRead($user, $comment)) {
            return Response::denyAsNotFound();
        }

        return $comment->author_id === $user->getKey()
            ? Response::allow()
            : Response::deny('You can only edit your own comments.');
    }

    public function delete(User $user, Comment $comment): Response
    {
        if (! $this->canRead($user, $comment)) {
            return Response::denyAsNotFound();
        }

        if ($comment->author_id === $user->getKey()) {
            return Response::allow();
        }

        return $user->isAdmin()
            ? Response::allow()
            : Response::deny('You can only delete your own comments.');
    }

    /**
     * Files are attached as part of writing a comment, so this follows update().
     */
    public function manageAttachments(User $user, Comment $comment): Response
    {
        return $this->update($user, $comment);
    }

    /**
     * The single-model mirror of Comment::visibleTo(), plus the ticket check.
     *
     * The ticket half delegates to TicketPolicy rather than repeating the board
     * membership and customer rules, so the two can never disagree.
     */
    private function canRead(User $user, Comment $comment): bool
    {
        $comment->loadMissing('ticket');

        if (! $comment->ticket instanceof Ticket) {
            return false;
        }

        if (! $user->can('view', $comment->ticket)) {
            return false;
        }

        return $comment->stream->isCustomerFacing() || $user->canSeeInternalContent();
    }
}
