<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\DocPage;
use Illuminate\Support\Collection;

/**
 * Structural questions about the documentation tree.
 *
 * Deliberately unscoped: this answers "what is the shape of the tree?", not
 * "what may this person see?". The two must not be confused, so they live in
 * two classes with two names — App\Services\DocPageFinder is the one that knows
 * about viewers, and it is the only one a request path should reach for.
 *
 * Everything here is used by App\Actions\Docs\*, which run after the policy has
 * already decided that the actor may reorganise this board's documentation. A
 * write needs the true shape, including pages the actor could see anyway
 * (staff) — using a filtered view would let a bug reparent a page under
 * something it cannot see.
 */
class DocPageTree
{
    /**
     * Every page beneath this one, breadth first, shallowest first.
     *
     * Bounded by DocPage::MAX_DEPTH, so a cycle introduced by a bad write
     * terminates instead of exhausting memory.
     *
     * @return Collection<int, DocPage>
     */
    public function descendants(DocPage $page): Collection
    {
        $found = collect();
        $frontier = [$page->getKey()];
        $depth = 0;

        while ($frontier !== [] && $depth++ <= DocPage::MAX_DEPTH + 1) {
            $children = DocPage::query()
                ->whereIn('parent_id', $frontier)
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            if ($children->isEmpty()) {
                break;
            }

            $found = $found->concat($children);
            $frontier = $children->pluck('id')->map(fn ($id): int => (int) $id)->all();
        }

        return $found;
    }

    /**
     * The same pages, deepest first.
     *
     * The order a subtree must be deleted in: `doc_pages.parent_id` is
     * RESTRICT, so a child still pointing at its parent blocks the parent's
     * removal.
     *
     * @return Collection<int, DocPage>
     */
    public function descendantsDeepestFirst(DocPage $page): Collection
    {
        return $this->descendants($page)->reverse()->values();
    }

    /**
     * The chain from a page up to its root, nearest first.
     *
     * @return Collection<int, DocPage>
     */
    public function ancestors(DocPage $page): Collection
    {
        $trail = collect();
        $current = $page;
        $steps = 0;

        while ($current->parent_id !== null && $steps++ <= DocPage::MAX_DEPTH + 1) {
            $parent = DocPage::query()->find($current->parent_id);

            if (! $parent instanceof DocPage) {
                break;
            }

            $trail->push($parent);
            $current = $parent;
        }

        return $trail;
    }

    /**
     * How deep a page sits. A root page is 0.
     */
    public function depthOf(?DocPage $page): int
    {
        return $page === null ? -1 : $this->ancestors($page)->count();
    }

    /**
     * How many levels the subtree below a page extends.
     */
    public function heightOf(DocPage $page): int
    {
        $base = $this->depthOf($page);

        $deepest = $this->descendants($page)
            ->map(fn (DocPage $child): int => $this->depthOf($child))
            ->max();

        return $deepest === null ? 0 : $deepest - $base;
    }

    /**
     * Would moving $page under $parent create a loop?
     *
     * True when the proposed parent is the page itself or one of its own
     * descendants. A loop would make the tree unrenderable and, worse, would
     * make the ancestor-visibility walk unable to terminate honestly.
     */
    public function wouldCycle(DocPage $page, ?DocPage $parent): bool
    {
        if ($parent === null) {
            return false;
        }

        if ($parent->getKey() === $page->getKey()) {
            return true;
        }

        return $this->descendants($page)->contains(
            fn (DocPage $child): bool => $child->getKey() === $parent->getKey()
        );
    }

    /**
     * Rewrite one level of the tree into a dense 0..n-1 ordering, optionally
     * placing one page at a chosen index.
     *
     * Mirrors App\Actions\Tickets\MoveTicket::resequence: only rows whose
     * position actually changes are written, so dropping a page back where it
     * came from costs no updates.
     */
    public function resequence(int $boardId, ?int $parentId, ?int $pageId = null, ?int $position = null): void
    {
        $ids = DocPage::query()
            ->where('board_id', $boardId)
            ->when($parentId === null, fn ($query) => $query->whereNull('parent_id'))
            ->when($parentId !== null, fn ($query) => $query->where('parent_id', $parentId))
            ->when($pageId !== null, fn ($query) => $query->whereKeyNot($pageId))
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($pageId !== null) {
            $index = max(0, min($position ?? count($ids), count($ids)));
            array_splice($ids, $index, 0, [$pageId]);
        }

        if ($ids === []) {
            return;
        }

        $current = DocPage::query()->whereIn('id', $ids)->pluck('position', 'id')->all();

        foreach ($ids as $index => $id) {
            if ((int) ($current[$id] ?? -1) === $index) {
                continue;
            }

            DocPage::query()->whereKey($id)->update(['position' => $index]);
        }
    }
}
