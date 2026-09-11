<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Models\DocPage;
use App\Services\DocPageFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Tests\TestCase;

/**
 * The page tree: what it shows, what it opens, and what it costs.
 *
 * Its nesting rules, its drag and drop and its visibility rules are covered by
 * DocumentationTest, DocumentationDragAndDropTest and
 * Tests\Security\DocumentationVisibilityTest. This file is about the two things
 * that changed when it became a table of contents rather than a flat list —
 * disclosure, and the size of the query behind it.
 */
class DocumentationTreeTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Disclosure
    // -----------------------------------------------------------------

    public function test_only_a_page_with_children_gets_a_disclosure_control(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $parent = $this->docPageOn($board, $team, ['title' => 'Development']);
        $this->docPageOn($board, $team, ['title' => 'Coding standards', 'parent_id' => $parent->getKey()]);
        $leaf = $this->docPageOn($board, $team, ['title' => 'Design']);

        $html = $this->actingAs($team)
            ->get(route('docs.index', $board))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('$store.docTree.toggle('.$parent->id.',', $html);
        $this->assertStringContainsString('aria-controls="doc-children-'.$parent->id.'"', $html);

        // A control that cannot do anything is worse than no control.
        $this->assertStringNotContainsString('$store.docTree.toggle('.$leaf->id.',', $html);
        $this->assertStringNotContainsString('doc-children-'.$leaf->id, $html);
    }

    public function test_the_branch_holding_the_open_page_is_expanded_and_the_others_are_not(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $onPath = $this->docPageOn($board, $team, ['title' => 'Development']);
        $child = $this->docPageOn($board, $team, ['title' => 'Coding standards', 'parent_id' => $onPath->getKey()]);

        $elsewhere = $this->docPageOn($board, $team, ['title' => 'API']);
        $this->docPageOn($board, $team, ['title' => 'Endpoints', 'parent_id' => $elsewhere->getKey()]);

        $html = $this->actingAs($team)
            ->get(route('docs.show', ['board' => $board, 'slug' => $child->slug]))
            ->assertOk()
            ->getContent();

        /*
         * The closed state is rendered server-side as an inline style, which is
         * the same channel Alpine's x-show writes to — so a collapsed tree does
         * not flash fully expanded before the store is read, and the two cannot
         * fight over the same element.
         */
        $this->assertStringContainsString(
            'id="doc-children-'.$elsewhere->id.'" x-show="$store.docTree.isOpen('.$elsewhere->id.', false)" style="display: none"',
            $this->squash($html)
        );

        $this->assertStringContainsString(
            'id="doc-children-'.$onPath->id.'" x-show="$store.docTree.isOpen('.$onPath->id.', true)"',
            $this->squash($html)
        );

        /*
         * And the ancestor chain is what the store is told to open. The needle
         * is built with the same helper the template uses, so the assertion
         * cannot drift from however Blade's @js chooses to encode an array.
         */
        $this->assertStringContainsString(
            '$store.docTree.use('.Js::from($board->id).', '.Js::from([$onPath->id, $child->id]).')',
            $html
        );
    }

    public function test_a_page_at_the_maximum_depth_is_still_reachable_in_the_sidebar(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $trail = [];
        $current = null;

        for ($depth = 0; $depth <= DocPage::MAX_DEPTH; $depth++) {
            $current = $this->docPageOn($board, $team, [
                'title' => 'Level '.$depth,
                'parent_id' => $current?->getKey(),
            ]);

            $trail[] = $current;
        }

        $html = $this->actingAs($team)
            ->get(route('docs.show', ['board' => $board, 'slug' => $current->slug]))
            ->assertOk()
            ->assertSee('Level 0')
            ->assertSee('Level '.DocPage::MAX_DEPTH)
            ->getContent();

        // Every branch on the way down is open, so the sidebar shows where you
        // are rather than needing four clicks to find out. The deepest page is
        // not a branch — it has nothing under it and so no control.
        foreach (array_slice($trail, 0, -1) as $page) {
            $this->assertStringContainsString(
                'isOpen('.$page->id.', true)',
                $html,
                'Level "'.$page->title.'" should be on the open path.'
            );
        }

        $this->assertStringNotContainsString('doc-children-'.$current->id, $html);
    }

    // -----------------------------------------------------------------
    // Responsive
    // -----------------------------------------------------------------

    public function test_the_tree_is_rendered_once_and_becomes_a_drawer_on_a_narrow_screen(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook']);

        $html = $this->actingAs($team)
            ->get(route('docs.index', $board))
            ->assertOk()
            ->getContent();

        // A second copy for mobile would mean two lists carrying the same
        // x-sort:item ids, and the drag plugin would have no way to tell
        // which list a drop belonged to.
        $this->assertSame(
            1,
            substr_count($html, 'x-sort:item="'.$page->id.'"'),
            'The tree must be rendered once, not once per breakpoint.'
        );

        // Slid out of the way rather than display:none, so the same element can
        // be part of the layout on a desktop.
        $this->assertStringContainsString('-translate-x-full', $html);
        $this->assertStringContainsString('lg:translate-x-0', $html);
        $this->assertStringContainsString('aria-controls="doc-tree"', $html);
    }

    // -----------------------------------------------------------------
    // Cost
    // -----------------------------------------------------------------

    /**
     * The tree shows titles. Selecting `body_md` would drag every document on
     * the board into memory to render a list of them.
     */
    public function test_the_tree_does_not_read_the_documents_it_lists(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->docPageOn($board, $team, [
            'title' => 'Enormous',
            'body_md' => str_repeat('Paragraphs of prose nobody needs here. ', 400),
        ]);

        DB::enableQueryLog();

        $tree = app(DocPageFinder::class)->tree($board, $team);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $tree);

        $select = collect($queries)
            ->pluck('query')
            ->first(fn (string $sql): bool => str_contains($sql, 'from "doc_pages"'));

        $this->assertNotNull($select);
        $this->assertStringNotContainsString('body_md', (string) $select);
        $this->assertStringContainsString('"doc_pages"."title"', (string) $select);
    }

    public function test_the_whole_tree_costs_one_query_however_deep_it_goes(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        // Four levels, several siblings per level: enough that a per-node
        // lookup would be obvious.
        $roots = collect(range(1, 3))->map(
            fn (int $n): DocPage => $this->docPageOn($board, $team, ['title' => 'Root '.$n])
        );

        foreach ($roots as $root) {
            $child = $this->docPageOn($board, $team, ['title' => 'Child of '.$root->title, 'parent_id' => $root->getKey()]);
            $grandchild = $this->docPageOn($board, $team, ['title' => 'Under '.$child->title, 'parent_id' => $child->getKey()]);
            $this->docPageOn($board, $team, ['title' => 'Deep in '.$root->title, 'parent_id' => $grandchild->getKey()]);
        }

        DB::enableQueryLog();

        $tree = app(DocPageFinder::class)->tree($board, $team);

        $count = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains((string) $query['query'], 'doc_pages'))
            ->count();

        DB::disableQueryLog();

        $this->assertCount(3, $tree);
        $this->assertSame(1, $count, 'The tree is one query, assembled in PHP.');
    }

    /**
     * The search path used to re-ask the database whether each result was
     * visible, having just fetched them all through the visibility scope.
     */
    public function test_searching_does_not_re_check_every_result_it_just_fetched(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        foreach (range(1, 6) as $n) {
            $this->docPageOn($board, $team, ['title' => 'Runbook '.$n]);
        }

        DB::enableQueryLog();

        $results = app(DocPageFinder::class)->tree($board, $team, 'Runbook');

        $count = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains((string) $query['query'], 'doc_pages'))
            ->count();

        DB::disableQueryLog();

        $this->assertCount(6, $results);
        $this->assertSame(1, $count);
    }

    /**
     * Collapse the whitespace Blade leaves between attributes, so an assertion
     * about markup does not depend on how a template happens to be indented.
     */
    private function squash(string $html): string
    {
        return (string) preg_replace('/\s+/', ' ', $html);
    }
}
