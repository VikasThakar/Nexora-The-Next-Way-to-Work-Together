<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * User administration is an administrator-only concern.
 *
 * Customers and team members can only ever act on their own account, which is
 * handled by the profile components rather than this policy.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAdministerWorkspace();
    }

    public function view(User $user, User $model): bool
    {
        return $user->canAdministerWorkspace() || $user->is($model);
    }

    public function create(User $user): bool
    {
        return $user->canAdministerWorkspace();
    }

    /**
     * Who may fetch this person's profile picture.
     *
     * A separate ability from view(), and it has to be: view() asks "may this
     * person be administered by you", which is an administrator question, and
     * answering it here would mean every avatar in the product 404ing for
     * everybody except administrators and the person in the picture.
     *
     * The rule this needs instead is: **a picture is exactly as visible as the
     * name it sits beside.** Every surface that renders an avatar already
     * renders that person's name under a correct scope — Ticket::visibleTo()
     * for an assignee on a card, Comment::visibleTo() for a conversation
     * author, Board::assignableMembers() for the ticket sidebar, or an
     * administrator-only screen. So the picture should inherit that scope
     * rather than become a second, looser way to find people.
     *
     * "Shares a board" is the closest computable expression of "already
     * nameable to this viewer". A customer on a board sees the delivery team as
     * assignees and as comment authors; a member of staff sees the colleagues
     * they work with. Neither learns anybody new.
     *
     * What this deliberately is not
     * -----------------------------
     * Not "any authenticated user". That would make the route a directory: a
     * customer could walk the ids and find every account in the workspace. The
     * command palette already refuses customers a people search for precisely
     * that reason (App\Services\Search\GlobalSearch), and this must not reopen
     * what that closed.
     *
     * The residue is narrow and worth naming: a customer can discover that a
     * given id shares a board with them and whether it has a picture. Existence,
     * never a name and never an address — and those are the people whose names
     * already appear on their tickets.
     *
     * Denied as not found, like every other refusal in this product that would
     * otherwise confirm something exists.
     */
    public function viewAvatar(User $user, User $model): Response
    {
        if (! $user->isActive()) {
            return Response::denyAsNotFound();
        }

        if ($user->is($model) || $user->canAdministerWorkspace()) {
            return Response::allow();
        }

        $shared = $model->boardMemberships()
            ->whereIn('board_id', $user->boardMemberships()->select('board_id'))
            ->exists();

        return $shared ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, User $model): bool
    {
        return $user->canAdministerWorkspace();
    }

    /**
     * Administrators may not delete themselves; that would be an easy way to
     * leave the workspace with no administrator at all.
     */
    public function delete(User $user, User $model): Response
    {
        if (! $user->canAdministerWorkspace()) {
            return Response::deny('Only administrators can remove users.');
        }

        if ($user->is($model)) {
            return Response::deny('You cannot delete your own account.');
        }

        return Response::allow();
    }

    /**
     * Changing a role is separated from update() because it is the privilege
     * escalation path and deserves its own gate.
     */
    public function updateRole(User $user, User $model): Response
    {
        if (! $user->canAdministerWorkspace()) {
            return Response::deny('Only administrators can change roles.');
        }

        if ($user->is($model)) {
            return Response::deny('You cannot change your own role.');
        }

        return Response::allow();
    }
}
