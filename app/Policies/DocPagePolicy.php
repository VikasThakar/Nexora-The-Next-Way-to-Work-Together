<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Board;
use App\Models\DocPage;
use App\Models\User;
use App\Services\BoardAccess;
use App\Services\DocPageFinder;
use Illuminate\Auth\Access\Response;

/**
 * Documentation authorization.
 *
 * Reads delegate to DocPageFinder::isVisible(), which is the one place that
 * knows a tree rule a flat table does not need: a page is visible only when it
 * and every one of its ancestors are visible. Repeating that rule here would
 * give it two definitions and therefore two chances to be wrong.
 *
 * Writes are staff-only. Documentation is the delivery team's material, which
 * they publish to customers a page at a time; a customer never authors it.
 */
class DocPagePolicy
{
    public function __construct(
        private readonly BoardAccess $access,
        private readonly DocPageFinder $finder,
    ) {}

    /**
     * May this user open the documentation section of a board?
     */
    public function viewAny(User $user, Board $board): Response
    {
        return $this->access->canView($user, $board)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Deny as 404: an internal page and a page that does not exist must look
     * identical to a customer guessing at a slug.
     */
    public function view(User $user, DocPage $page): Response
    {
        return $this->finder->isVisible($page, $user)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user, Board $board): Response
    {
        if (! $this->access->canView($user, $board)) {
            return Response::denyAsNotFound();
        }

        return $this->access->canManageBoardContent($user, $board)
            ? Response::allow()
            : Response::deny('Only the delivery team can write documentation.');
    }

    public function update(User $user, DocPage $page): Response
    {
        return $this->staffOnly($user, $page, 'Only the delivery team can edit documentation.');
    }

    public function delete(User $user, DocPage $page): Response
    {
        return $this->staffOnly($user, $page, 'Only the delivery team can delete documentation.');
    }

    /**
     * Reparent or reorder a page.
     */
    public function move(User $user, DocPage $page): Response
    {
        return $this->staffOnly($user, $page, 'Only the delivery team can reorganise documentation.');
    }

    /**
     * Publish a page to customers, or take it back.
     *
     * The most sensitive write in the documentation system: it is what exposes
     * internal material. Whether a particular change is allowed at all — a page
     * cannot be published under an internal parent — is decided by
     * App\Actions\Docs\SetPageVisibility.
     */
    public function changeVisibility(User $user, DocPage $page): Response
    {
        return $this->staffOnly($user, $page, 'Only the delivery team can publish documentation.');
    }

    public function manageAttachments(User $user, DocPage $page): Response
    {
        return $this->update($user, $page);
    }

    private function staffOnly(User $user, DocPage $page, string $message): Response
    {
        if (! $this->finder->isVisible($page, $user)) {
            return Response::denyAsNotFound();
        }

        $page->loadMissing('board');

        return $this->access->canManageBoardContent($user, $page->board)
            ? Response::allow()
            : Response::deny($message);
    }
}
