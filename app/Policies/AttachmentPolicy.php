<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Attachment;
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
    ];

    public function view(User $user, Attachment $attachment): Response
    {
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
