<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Board;
use App\Models\DocPage;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The only supported way to read documentation.
 *
 * A tree needs one rule a flat table does not: a page is visible only when it
 * AND every one of its ancestors are visible. Without it, publishing a child of
 * an internal page would show a customer a page whose parent, path and siblings
 * they must not see — and the breadcrumb alone would leak the internal titles.
 *
 * That rule is enforced here, once, and everything else delegates to it:
 * DocPagePolicy::view calls isVisible(), the sidebar calls tree(), the page
 * calls findOrFail(). App\Actions\Docs\* keeps the invariant true on write; the
 * checks here assume it might not be.
 *
 * Cost: for staff the ancestor walk is skipped entirely, because a member of
 * the board can see every page on it. For a customer it is at most
 * DocPage::MAX_DEPTH extra lookups.
 */
class DocPageFinder
{
    /**
     * Base query: pages on this board that this viewer may read.
     *
     * Ancestry is NOT applied here — it cannot be expressed as a single scope
     * without a recursive query. Use tree() or findOrFail(), which layer it on.
     *
     * @return Builder<DocPage>
     */
    public function query(Board $board, ?Authenticatable $viewer): Builder
    {
        return DocPage::query()
            ->visibleTo($viewer)
            ->forBoard($board);
    }

    /**
     * The whole visible tree of a board, nested and ordered.
     *
     * One query for the board, assembled in PHP. Any page whose parent is not
     * itself visible is dropped along with its subtree: that is the read-side
     * backstop for the ancestor rule, so even a page published under an
     * internal parent by some future bug never reaches a customer.
     *
     * @return Collection<int, object>
     */
    public function tree(Board $board, ?Authenticatable $viewer, ?string $search = null): Collection
    {
        $searching = $search !== null && trim($search) !== '';

        $pages = $this->query($board, $viewer)
            ->when($searching, fn (Builder $query) => $query->search($search))
            ->ordered()
            ->get();

        if ($pages->isEmpty()) {
            return collect();
        }

        // When searching, the matches may not form a connected tree, so they
        // are listed flat rather than silently hidden under a parent that did
        // not match. Ancestry still decided which pages could be fetched.
        if ($searching) {
            return $pages
                ->filter(fn (DocPage $page): bool => $this->isVisible($page, $viewer))
                ->map(fn (DocPage $page): object => (object) [
                    'page' => $page,
                    'children' => collect(),
                    'depth' => 0,
                ])
                ->values();
        }

        $visibleIds = $pages->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $byParent = $pages->groupBy(fn (DocPage $page): string => (string) ($page->parent_id ?? ''));

        return $this->build($byParent, null, $visibleIds, 0);
    }

    /**
     * Resolve one page by slug, or fail as 404.
     *
     * 404 rather than 403 for the same reason everywhere else in this codebase
     * does it: an internal page and a slug nobody has used must be
     * indistinguishable to somebody guessing.
     */
    public function findOrFail(Board $board, string $slug, ?Authenticatable $viewer): DocPage
    {
        $page = $this->query($board, $viewer)
            ->where('doc_pages.slug', $slug)
            ->first();

        if (! $page instanceof DocPage || ! $this->ancestorsAreVisible($page, $viewer)) {
            throw new NotFoundHttpException;
        }

        return $page;
    }

    /**
     * May this viewer read this page, ancestors included?
     *
     * The single definition of documentation read access. DocPagePolicy calls
     * it rather than restating the rule.
     */
    public function isVisible(DocPage $page, ?Authenticatable $viewer): bool
    {
        $reachable = DocPage::query()
            ->visibleTo($viewer)
            ->whereKey($page->getKey())
            ->exists();

        return $reachable && $this->ancestorsAreVisible($page, $viewer);
    }

    /**
     * The breadcrumb trail, root first.
     *
     * Returns nothing at all when any link in the chain is not visible, so a
     * breadcrumb can never name an internal page.
     *
     * @return Collection<int, DocPage>
     */
    public function ancestors(DocPage $page, ?Authenticatable $viewer): Collection
    {
        $trail = collect();
        $current = $page;
        $steps = 0;

        while ($current->parent_id !== null) {
            if ($steps++ > DocPage::MAX_DEPTH + 1) {
                return collect();
            }

            $parent = DocPage::query()
                ->visibleTo($viewer)
                ->whereKey($current->parent_id)
                ->first();

            if (! $parent instanceof DocPage) {
                return collect();
            }

            $trail->prepend($parent);
            $current = $parent;
        }

        return $trail;
    }

    /**
     * Walk up from a page, requiring every ancestor to be visible.
     *
     * Staff short-circuit: somebody who may observe internal content can read
     * every page on a board they can reach, so there is nothing an ancestor
     * could hide from them.
     */
    private function ancestorsAreVisible(DocPage $page, ?Authenticatable $viewer): bool
    {
        if ($page->parent_id === null) {
            return true;
        }

        if (app(BoardAccess::class)->canSeeInternalContent($viewer)) {
            return true;
        }

        $current = $page;
        $steps = 0;

        while ($current->parent_id !== null) {
            // Bounded, so a cycle introduced by a bad write cannot spin forever.
            if ($steps++ > DocPage::MAX_DEPTH + 1) {
                return false;
            }

            $parent = DocPage::query()
                ->visibleTo($viewer)
                ->whereKey($current->parent_id)
                ->first();

            if (! $parent instanceof DocPage) {
                return false;
            }

            $current = $parent;
        }

        return true;
    }

    /**
     * @param  Collection<string, Collection<int, DocPage>>  $byParent
     * @param  array<int, int>  $visibleIds
     * @return Collection<int, object>
     */
    private function build(Collection $byParent, ?int $parentId, array $visibleIds, int $depth): Collection
    {
        if ($depth > DocPage::MAX_DEPTH + 1) {
            return collect();
        }

        return $byParent
            ->get((string) ($parentId ?? ''), collect())
            ->filter(fn (DocPage $page): bool => $page->parent_id === null
                || in_array((int) $page->parent_id, $visibleIds, true))
            ->map(fn (DocPage $page): object => (object) [
                'page' => $page,
                'children' => $this->build($byParent, (int) $page->getKey(), $visibleIds, $depth + 1),
                'depth' => $depth,
            ])
            ->values();
    }
}
