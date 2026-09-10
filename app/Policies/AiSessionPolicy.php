<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AiSession;
use App\Models\Board;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may read a conversation, and attach files to it.
 *
 * This policy exists because attachments needed an owner. AttachmentPolicy
 * answers "may this person download this file" by asking the file's owner's
 * policy, and the owner of an assistant upload is the session — so a session
 * needed a `view` ability for that delegation to reach. Adding it here rather
 * than special-casing AI files inside AttachmentPolicy is what keeps one rule
 * for attachment visibility across tickets, comments, pages and conversations.
 *
 * The rule is the same one AiSession::scopeVisibleTo enforces in SQL, and it is
 * stated in the same order:
 *
 *   1. the account is active;
 *   2. ownership. A conversation is one person's, an administrator included —
 *      there is no reading right over a colleague's thread, so there is none
 *      over the documents in it either;
 *   3. the board is still reachable. A conversation about a board somebody has
 *      since been removed from stops being readable even though they own it.
 *
 * A customer has a conversation of their own
 * ------------------------------------------
 * This policy used to refuse a customer at step 1, because the assistant was
 * staff-only. Customers now have a read-only assistant and may attach their own
 * files to it, so the refusal has moved to where it belongs: not "may you have
 * a conversation" but "what may a conversation contain", which is decided by
 * the context builders and the tool registry rather than here.
 *
 * Ownership was always the real rule at this level, and it is unchanged. A
 * customer reaches their own thread and their own uploads, and nobody else's —
 * in particular not the delivery team's conversation about their board.
 *
 * Every denial is a 404. A conversation somebody may not read must not be
 * distinguishable from one that does not exist — the same choice
 * BoardPolicy::useAiChat makes, for the same reason.
 */
class AiSessionPolicy
{
    /**
     * May this person read the conversation, and the files in it?
     */
    public function view(User $user, AiSession $session): Response
    {
        return $this->reach($user, $session);
    }

    /**
     * May this person attach files to it, or take one back off?
     *
     * The same answer as reading, and that is not laziness. A conversation is
     * private to one person, so the set of people who may read it and the set
     * who may add to it are the same set of one. Keeping the abilities separate
     * matters anyway, because AttachmentPolicy asks for this one by name when
     * deciding who may delete a file, and a future surface that shares a
     * conversation would have somewhere to differ.
     */
    public function manageAttachments(User $user, AiSession $session): Response
    {
        return $this->reach($user, $session);
    }

    /**
     * The three conditions, in the order that leaks least.
     *
     * Ownership is compared before the board is loaded, so somebody probing
     * another person's conversation learns nothing from timing — and nothing at
     * all, since every denial is the same 404.
     */
    private function reach(User $user, AiSession $session): Response
    {
        if (! $user->isActive()) {
            return Response::denyAsNotFound();
        }

        if ((int) $session->user_id !== (int) $user->getKey()) {
            return Response::denyAsNotFound();
        }

        $board = $session->board;

        /*
         * A workspace conversation has no board to check, and is protected by
         * ownership alone — which is exactly what AiSession::scopeVisibleTo
         * does with a null board_id, and for the same reason: board membership
         * cannot express "belongs to nobody's board".
         */
        if (! $board instanceof Board) {
            return Response::allow();
        }

        return $user->can('view', $board)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
