<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Board;
use App\Models\User;
use App\Services\BoardAccess;
use Illuminate\Auth\Access\Response;

/**
 * Board authorization.
 *
 * The policy holds no rules of its own: it delegates to BoardAccess so that
 * Gate checks, Livewire components and raw queries can never disagree about
 * who may see what.
 */
class BoardPolicy
{
    public function __construct(private readonly BoardAccess $access) {}

    /**
     * Every authenticated, active user may open the board index; the list
     * itself is filtered by BoardAccess::query().
     */
    public function viewAny(User $user): bool
    {
        return $user->isActive();
    }

    /**
     * Deny as 404 rather than 403.
     *
     * A 403 tells the caller that a board with the requested slug exists,
     * which already leaks information a non-member must not receive.
     */
    public function view(User $user, Board $board): Response
    {
        return $this->access->canView($user, $board)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $this->access->canManage($user);
    }

    public function update(User $user, Board $board): Response
    {
        if (! $this->access->canView($user, $board)) {
            return Response::denyAsNotFound();
        }

        return $this->access->canManage($user, $board)
            ? Response::allow()
            : Response::deny('You are not allowed to change this board.');
    }

    public function delete(User $user, Board $board): Response
    {
        return $this->update($user, $board);
    }

    /**
     * Add or remove board members.
     */
    public function manageMembers(User $user, Board $board): Response
    {
        return $this->update($user, $board);
    }

    /**
     * Configure the working surface of the board: its columns and its labels.
     *
     * Wider than update() on purpose. Renaming a column or adding a label is
     * everyday workflow for the delivery team, whereas the board record itself
     * — its name, prefix, membership and existence — stays administrator-only.
     * Customers are excluded from both.
     */
    public function manageColumns(User $user, Board $board): Response
    {
        if (! $this->access->canView($user, $board)) {
            return Response::denyAsNotFound();
        }

        return $this->access->canManageBoardContent($user, $board)
            ? Response::allow()
            : Response::deny('Only the delivery team can change this board configuration.');
    }

    public function manageLabels(User $user, Board $board): Response
    {
        return $this->manageColumns($user, $board);
    }

    /**
     * May the user observe internal content on this board?
     *
     * Membership alone is never enough: customers are excluded even on boards
     * they belong to. Phase 2+ ticket, comment and documentation policies
     * should call this rather than re-deriving the rule.
     */
    public function viewInternalContent(User $user, Board $board): bool
    {
        return $this->access->canView($user, $board)
            && $this->access->canSeeInternalContent($user);
    }

    /**
     * May the user open the workspace AI chat for this board?
     *
     * Team-only, and denied as 404 rather than 403 for both halves. A customer
     * who is a member of the board would otherwise learn from a 403 that the
     * chat exists on a board they work on — and the chat quotes internal
     * tickets, internal notes and internal documentation, so it can be no safer
     * than the least safe thing in it.
     *
     * Expressed through the same two questions as everything else: can they see
     * the board, and may they observe internal content.
     */
    public function useAiChat(User $user, Board $board): Response
    {
        return $this->viewInternalContent($user, $board)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * May the user talk to the assistant about this board at all?
     *
     * Deliberately a different question from useAiChat above, and the
     * difference is the whole customer-AI feature.
     *
     *   useAiChat     the full-page board chat, and the staff assistant. It
     *                 quotes internal tickets, internal notes and internal
     *                 documentation, so it can be no safer than the least safe
     *                 thing in it: team only, denied as 404.
     *   useAssistant  the assistant panel. A customer who is a member of this
     *                 board may ask questions about it, and receives an answer
     *                 built from what they can already read — their own
     *                 tickets, the documentation published to them, the files
     *                 they uploaded themselves.
     *
     * Two abilities rather than one loosened ability, because the two surfaces
     * differ in what they are allowed to contain and a single relaxed check
     * would have quietly opened the staff page as well.
     *
     * This ability grants no capability beyond "may hold a conversation". What a
     * conversation may contain is decided elsewhere and separately:
     * BoardContextBuilder assembles it with this person as the viewer,
     * App\Services\AI\Tools\AiToolRegistry withholds the staff-only lookups,
     * and AiCapabilityGuard::allowsProposals refuses a customer a write tool in
     * every mode, AI Agent included.
     *
     * Denied as 404 for the same reason view() is: a board somebody cannot
     * reach must not be distinguishable from one that does not exist.
     */
    public function useAssistant(User $user, Board $board): Response
    {
        return $this->access->canView($user, $board)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * May the user change the board's AI settings and its repositories?
     *
     * Deliberately the same bar as columns and labels rather than the
     * administrator-only bar of the board record: choosing a model, writing the
     * project context and attaching a repository are the delivery team's daily
     * work on their own board.
     *
     * The expensive switch inside that screen — turning automatic runs on —
     * carries a cost cap that nobody can bypass (see AiRunCap), which is what
     * makes it safe to hand to the team rather than reserving it for an
     * administrator.
     */
    public function manageAiSettings(User $user, Board $board): Response
    {
        if (! $this->access->canView($user, $board)) {
            return Response::denyAsNotFound();
        }

        if (! $this->access->canSeeInternalContent($user)) {
            // A customer must not learn that the screen exists.
            return Response::denyAsNotFound();
        }

        return $this->access->canManageBoardContent($user, $board)
            ? Response::allow()
            : Response::deny('Only the delivery team can change this board configuration.');
    }
}
