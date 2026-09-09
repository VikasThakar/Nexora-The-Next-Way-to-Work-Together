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
     * The columns the sidebar tree actually renders.
     *
     * `doc_pages.body_md` is a LONGTEXT holding a whole document, and the tree
     * shows none of it — so selecting it would drag every page's full text
     * into memory on every render of the documentation screen, for a list of
     * titles. Search still filters on the column in the WHERE clause, which
     * reads it in the database and returns none of it.
     *
     * @var list<string>
     */
    private const TREE_COLUMNS = [
        'doc_pages.id',
        'doc_pages.board_id',
        'doc_pages.parent_id',
        'doc_pages.title',
        'doc_pages.slug',
        'doc_pages.icon',
        'doc_pages.customer_visible',
        'doc_pages.position',
    ];

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
            ->select(self::TREE_COLUMNS)
            ->ordered()
            ->get();

        if ($pages->isEmpty()) {
            return collect();
        }

        // When searching, the matches may not form a connected tree, so they
        // are listed flat rather than silently hidden under a parent that did
        // not match. Ancestry still decided which pages could be fetched.
        //
        // Only the ancestor walk is repeated here, not isVisible(): every page
        // in $pages came back through the visibility scope already, so asking
        // the database again whether it is reachable would be one wasted query
        // per search result.
        if ($searching) {
            return $pages
                ->filter(fn (DocPage $page): bool => $this->ancestorsAreVisible($page, $viewer))
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
     * Search results for the documentation sidebar: the page, where it sits,
     * and enough of its text to recognise it by.
     *
     * A bare list of titles is a poor result set for documentation, because the
     * titles repeat — every product has an "Overview" and three "Getting
     * started"s — and the thing that tells them apart is the path above them.
     *
     * Two queries, whatever the result count:
     *
     *   1. every page on the board this viewer may see, as id/parent/title
     *      only. Small, covered by the board index, and it is what the paths
     *      are assembled from.
     *   2. the matches themselves, which is the only place `body_md` is read,
     *      and only for as many rows as are shown.
     *
     * The map from the first query does double duty as the ancestor check: it
     * holds only visible pages, so a match whose parent is missing from it is a
     * page filed under something this viewer cannot see, and is dropped along
     * with the internal titles its path would otherwise have spelled out. That
     * is the same rule as ancestorsAreVisible(), decided from memory instead of
     * one walk per result.
     *
     * @return Collection<int, object{page: DocPage, path: list<string>, snippet: string}>
     */
    public function searchResults(
        Board $board,
        ?Authenticatable $viewer,
        string $term,
        int $limit = 30,
    ): Collection {
        if (trim($term) === '' || $limit < 1) {
            return collect();
        }

        $index = $this->query($board, $viewer)
            ->select(['doc_pages.id', 'doc_pages.parent_id', 'doc_pages.title'])
            ->get()
            ->keyBy(fn (DocPage $page): int => (int) $page->getKey());

        return $this->query($board, $viewer)
            ->search($term)
            ->select([...self::TREE_COLUMNS, 'doc_pages.body_md'])
            ->ordered()
            ->limit($limit)
            ->get()
            ->map(function (DocPage $page) use ($index, $term): ?object {
                $path = $this->pathFrom($index, $page);

                // null, not an empty path: a root page has no path and is a
                // perfectly good result, while an unreachable one is not a
                // result at all.
                if ($path === null) {
                    return null;
                }

                return (object) [
                    'page' => $page,
                    'path' => $path,
                    'snippet' => self::snippet((string) $page->body_md, $term),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * The titles above a page, root first, or null when one of them is not in
     * the visible index.
     *
     * @param  Collection<int, DocPage>  $index
     * @return list<string>|null
     */
    private function pathFrom(Collection $index, DocPage $page): ?array
    {
        $path = [];
        $parentId = $page->parent_id === null ? null : (int) $page->parent_id;
        $steps = 0;

        while ($parentId !== null) {
            // Bounded for the same reason the database walks are: a cycle from
            // a bad write must not spin here either.
            if ($steps++ > DocPage::MAX_DEPTH + 1) {
                return null;
            }

            $parent = $index->get($parentId);

            if (! $parent instanceof DocPage) {
                return null;
            }

            array_unshift($path, (string) $parent->title);
            $parentId = $parent->parent_id === null ? null : (int) $parent->parent_id;
        }

        return $path;
    }

    /**
     * A window of a page's Markdown around the match.
     *
     * Shown around the term rather than from the top of the document, because
     * a preview of the first line tells the searcher nothing about why this
     * page came back. The source is Markdown, so the syntax is flattened
     * first — a snippet reading `## Setting **up**` is noise where the words
     * are what is being scanned for.
     *
     * Returned as plain text and escaped by the template like any other
     * string. Nothing here produces markup, and it deliberately does not
     * highlight the match with tags: that would mean rendering unescaped HTML
     * built from page content, which is not a trade worth making for a bold
     * word.
     */
    private static function snippet(string $markdown, string $term, int $length = 120): string
    {
        $text = trim(preg_replace(
            ['/```.*?```/s', '/[#>*_`~\[\]!]+/', '/\((?:https?|mailto):[^)]*\)/i', '/\s+/'],
            ['', '', '', ' '],
            $markdown
        ) ?? '');

        if ($text === '') {
            return '';
        }

        $at = mb_stripos($text, trim($term));

        if ($at === false) {
            // The term matched the title, not the body. The opening line is
            // then the most useful thing the body can offer.
            return mb_strimwidth($text, 0, $length, '…');
        }

        // A little context before the match, clamped so a hit near the start
        // does not produce a leading ellipsis for no reason.
        $from = max(0, $at - 40);

        return ($from > 0 ? '…' : '').mb_strimwidth($text, $from, $length, '…');
    }

    /**
     * Pages matching a term across every board this viewer can reach.
     *
     * For the command palette, and routed through here rather than through a
     * scope of its own for the reason this whole class exists: a page is
     * readable only when every one of its ancestors is, and a search that
     * queried `DocPage::visibleTo()` directly would happily return the title of
     * a page published beneath an internal parent.
     *
     * Over-fetched before the ancestor filter, so a customer whose first
     * matches happen to sit under internal parents still gets a full list
     * rather than a short one. The walk itself is free for staff, who
     * short-circuit.
     *
     * @return Collection<int, DocPage>
     */
    public function searchAcrossBoards(?Authenticatable $viewer, string $term, int $limit = 5): Collection
    {
        if (trim($term) === '' || $limit < 1) {
            return collect();
        }

        return DocPage::query()
            ->visibleTo($viewer)
            ->search($term)
            ->select(self::TREE_COLUMNS)
            ->with('board:id,name,slug')
            ->orderBy('doc_pages.title')
            ->limit($limit * 4)
            ->get()
            ->filter(fn (DocPage $page): bool => $this->ancestorsAreVisible($page, $viewer))
            ->take($limit)
            ->values();
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
