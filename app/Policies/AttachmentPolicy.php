<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AiSession;
use App\Models\Attachment;
use App\Models\Board;
use App\Models\Comment;
use App\Models\DocPage;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Attachments have no visibility of their own: they inherit it from whatever
 * they hang off.
 *
 * Every decision here therefore delegates to the owner's policy. A file on an
 * internal ticket is internal because the ticket is; a file on an internal note
 * is internal because the note is; a file on an unpublished documentation page
 * is internal because the page is. The three can never drift apart, because
 * there is no second flag to keep in sync — there is no flag at all.
 *
 * Owners are resolved from an explicit allow-list. An attachable type nobody
 * has thought about is denied rather than served: a polymorphic key is a string
 * in a column, and "unknown owner" must not mean "no restrictions".
 *
 * AiSession is the fourth owner, and the delegation earns its keep there: a
 * file uploaded to an assistant conversation is private to the one person whose
 * conversation it is, an administrator included, because that is what
 * AiSessionPolicy::view says — and this class did not have to learn anything
 * about conversations to enforce it.
 *
 * There is exactly one exception to the delegation, and it is an exception
 * because it has to be: a **draft** has no owner yet, so there is nothing to
 * delegate to. See draft() below for the rule that stands in until one arrives.
 */
class AttachmentPolicy
{
    /**
     * Types that may own an attachment, each of which has a `view` ability.
     *
     * @var array<int, class-string<Model>>
     */
    private const ATTACHABLE = [
        Ticket::class,
        Comment::class,
        DocPage::class,
        AiSession::class,
    ];

    public function view(User $user, Attachment $attachment): Response
    {
        if ($attachment->isDraft()) {
            return $this->draft($user, $attachment);
        }

        $owner = $this->owner($attachment);

        if ($owner === null) {
            return Response::denyAsNotFound();
        }

        return $user->can('view', $owner)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function delete(User $user, Attachment $attachment): Response
    {
        if ($attachment->isDraft()) {
            return $this->draft($user, $attachment);
        }

        $owner = $this->owner($attachment);

        if ($owner === null) {
            return Response::denyAsNotFound();
        }

        if (! $user->can('view', $owner)) {
            return Response::denyAsNotFound();
        }

        // Whoever uploaded a file may take it down again, even if they could
        // not otherwise edit the thing it is attached to.
        if ($attachment->uploaded_by_id === $user->getKey()) {
            return Response::allow();
        }

        return $user->can('manageAttachments', $owner)
            ? Response::allow()
            : Response::deny('You cannot remove this attachment.');
    }

    /**
     * The one case with nothing to delegate to.
     *
     * A draft is a file uploaded on a creation form before the record it belongs
     * to exists — see App\Models\Attachment::isDraft(). There is no owner, so
     * there is no owner's policy to ask, and this class has to hold a rule of
     * its own for the first time. It is kept as narrow as the situation allows:
     *
     *   the uploader, and nobody else. Not board members, not staff, not an
     *   administrator. Nobody else has any reason to read a file somebody is
     *   part-way through attaching to a ticket they have not filed yet, and a
     *   draft carries no signal about what it will become — so the only safe
     *   reading is the narrowest one.
     *
     *   and only while they can still reach the board it was filed under.
     *   Membership can be revoked between the upload and the fetch, and a
     *   stale draft must not outlive the access that created it.
     *
     * Denied as not found, like every other refusal here: an id that is somebody
     * else's draft and an id that was never used must look identical.
     */
    private function draft(User $user, Attachment $attachment): Response
    {
        if ($attachment->uploaded_by_id !== $user->getKey()) {
            return Response::denyAsNotFound();
        }

        $board = $attachment->board;

        if (! $board instanceof Board || ! $user->can('view', $board)) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    /**
     * Resolve the owning record.
     *
     * A soft-deleted owner does not resolve, which is what makes the files on a
     * deleted comment unreachable without any extra bookkeeping.
     */
    private function owner(Attachment $attachment): ?Model
    {
        $attachment->loadMissing('attachable');

        $owner = $attachment->attachable;

        if (! $owner instanceof Model) {
            return null;
        }

        foreach (self::ATTACHABLE as $type) {
            if ($owner instanceof $type) {
                return $owner;
            }
        }

        return null;
    }
}
