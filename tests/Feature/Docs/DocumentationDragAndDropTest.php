<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Models\DocPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sidebar tree is dragged with wire:sort, the Alpine Sort plugin bundled
 * inside Livewire — the same mechanism the Kanban board uses, and no extra
 * frontend dependency.
 *
 * Drag-and-drop cannot be exercised without a browser, so these tests pin the
 * two things that actually break it and that a server-side test can see: the
 * markup Livewire needs, and the fact that it is only emitted for people
 * allowed to reorganise. The handler itself is covered by DocumentationTest
 * (it works) and DocumentationVisibilityTest (a customer is refused).
 */
class DocumentationDragAndDropTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_tree_is_sortable_for_staff(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $parent = $this->docPageOn($board, $team, ['title' => 'Parent']);
        $child = $this->docPageOn($board, $team, ['title' => 'Child', 'parent_id' => $parent->getKey()]);

        $html = $this->actingAs($team)
            ->get(route('docs.index', $board))
            ->assertOk()
            ->getContent();

        // Every level is a drop target, sharing one group so a page can be
        // dragged between levels as well as within one.
        $this->assertStringContainsString('wire:sort:group="docs-'.$board->id.'"', $html);

        // Each list bakes its own parent id into the handler, so the server is
        // told where the page landed rather than having to infer it.
        $this->assertStringContainsString('$wire.movePage($item, $position, null)', $html);
        $this->assertStringContainsString('$wire.movePage($item, $position, '.$parent->id.')', $html);

        $this->assertStringContainsString('wire:sort:item="'.$parent->id.'"', $html);
        $this->assertStringContainsString('wire:sort:item="'.$child->id.'"', $html);
    }

    public function test_the_tree_is_not_sortable_for_a_customer(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $this->publishedPageOn($board, $team, ['title' => 'Readable']);

        $html = $this->actingAs($customer)
            ->get(route('docs.index', $board))
            ->assertOk()
            ->getContent();

        // Not a security control — DocPagePolicy::move is — but the handle and
        // the drop target should not be offered at all.
        $this->assertStringNotContainsString('wire:sort', $html);
        $this->assertStringNotContainsString('movePage', $html);
    }

    /**
     * Reordering is turned off while searching: the result list is flat and
     * unrelated to the tree, so an index in it would mean nothing.
     */
    public function test_search_results_are_not_sortable(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->docPageOn($board, $team, ['title' => 'Alpha']);

        $html = $this->actingAs($team)
            ->get(route('docs.index', $board).'?q=Alpha')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Alpha', $html);
        $this->assertStringNotContainsString('wire:sort:group', $html);
    }

    public function test_a_page_at_the_maximum_depth_still_renders(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $current = null;

        for ($depth = 0; $depth <= DocPage::MAX_DEPTH; $depth++) {
            $current = $this->docPageOn($board, $team, [
                'title' => 'Level '.$depth,
                'parent_id' => $current?->getKey(),
            ]);
        }

        $this->actingAs($team)
            ->get(route('docs.show', ['board' => $board, 'slug' => $current->slug]))
            ->assertOk()
            ->assertSee('Level 0')
            ->assertSee('Level '.DocPage::MAX_DEPTH);
    }
}
