<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Actions\Docs\UpdatePage;
use App\Livewire\Docs\Show as DocsShow;
use App\Models\DocPage;
use App\Services\DocPageFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The documentation workspace: the sidebar, its search, and the document
 * around the editor.
 *
 * The visual half of this — three columns, spacing, the contents list building
 * itself from headings in the browser — is not something a server-side test can
 * see. What is covered here is everything the look sits on and would quietly
 * break without: which pages a search returns and what it says about them,
 * whether the tree carries what it needs to render, and the query cost of
 * putting the screen together.
 */
class DocumentationWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Search
    // -----------------------------------------------------------------

    /**
     * A result is only useful with its path: documentation titles repeat, and
     * "Overview" on its own does not say which one was found.
     */
    public function test_a_search_result_carries_its_path_and_a_snippet(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $guides = $this->docPageOn($board, $team, ['title' => 'Guides']);
        $auth = $this->docPageOn($board, $team, [
            'title' => 'Authentication',
            'parent_id' => $guides->getKey(),
            'body_md' => "## Tokens\n\nEvery request carries a bearer token issued at sign-in.",
        ]);

        $results = app(DocPageFinder::class)->searchResults($board, $team, 'bearer token');

        $this->assertCount(1, $results);

        $result = $results->first();

        $this->assertSame($auth->getKey(), $result->page->getKey());
        $this->assertSame(['Guides'], $result->path);

        // A window around the match, not the first line of the document.
        $this->assertStringContainsString('bearer token', $result->snippet);

        // Markdown syntax is flattened: the words are what is being scanned.
        $this->assertStringNotContainsString('##', $result->snippet);
    }

    public function test_a_root_page_has_an_empty_path_rather_than_no_result(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->docPageOn($board, $team, ['title' => 'Zebra handbook', 'body_md' => 'Prose.']);

        $results = app(DocPageFinder::class)->searchResults($board, $team, 'Zebra');

        $this->assertCount(1, $results);
        $this->assertSame([], $results->first()->path);
    }

    /**
     * The ancestor rule, in the search results.
     *
     * A page published under an internal parent is unreachable, and its path
     * would spell out the internal titles above it — so it is not a result at
     * all. This is the same rule DocPageFinder::isVisible() enforces, decided
     * from the in-memory index rather than by a walk per row.
     */
    public function test_a_page_under_an_internal_parent_is_not_a_result_for_a_customer(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $secret = $this->docPageOn($board, $team, ['title' => 'Zebra commercials']);

        $child = $this->docPageOn($board, $team, [
            'title' => 'Zebra rates',
            'parent_id' => $secret->getKey(),
            'body_md' => 'Prose.',
        ]);

        /*
         * Forced through the query builder, deliberately.
         *
         * App\Actions\Docs\SetPageVisibility refuses this state — publishing a
         * page under an internal parent throws — so the only way to reach it is
         * a bug in some future write path. That is exactly the state this test
         * is about: the read side is the backstop, and it has to hold even when
         * the invariant it assumes has already been broken.
         */
        DB::table('doc_pages')->where('id', $child->getKey())->update(['customer_visible' => true]);

        $finder = app(DocPageFinder::class);

        // Staff see both.
        $this->assertCount(2, $finder->searchResults($board, $team, 'Zebra'));

        // The customer sees neither — not the internal parent, and not the
        // published child whose path runs through it.
        $this->assertCount(0, $finder->searchResults($board, $customer, 'Zebra'));
    }

    public function test_the_sidebar_shows_results_with_their_path(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $guides = $this->docPageOn($board, $team, ['title' => 'Guides']);
        $this->docPageOn($board, $team, [
            'title' => 'Deployment',
            'parent_id' => $guides->getKey(),
            'body_md' => 'Ship it with one command.',
        ]);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board])
            ->set('search', 'Deployment')
            ->assertSee('Deployment')
            ->assertSee('Guides')
            ->assertSee('Ship it with one command.');
    }

    public function test_a_search_that_matches_nothing_says_so(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->docPageOn($board, $team, ['title' => 'Handbook']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board])
            ->set('search', 'nothing matches this')
            ->assertSee('Nothing matches');
    }

    /**
     * Two queries, whatever the result count: one for the index the paths are
     * built from, one for the matches. A walk per result would be the obvious
     * way to write this and would scale with the page count.
     */
    public function test_search_results_cost_a_fixed_number_of_queries(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $parent = $this->docPageOn($board, $team, ['title' => 'Zebra root']);

        // Enough nested matches that a per-row ancestor walk would show up.
        foreach (range(1, 12) as $n) {
            $child = $this->docPageOn($board, $team, [
                'title' => 'Zebra child '.$n,
                'parent_id' => $parent->getKey(),
                'body_md' => 'Prose about zebras.',
            ]);

            $this->docPageOn($board, $team, [
                'title' => 'Zebra grandchild '.$n,
                'parent_id' => $child->getKey(),
                'body_md' => 'More prose about zebras.',
            ]);
        }

        DB::enableQueryLog();

        $results = app(DocPageFinder::class)->searchResults($board, $team, 'Zebra');

        $queries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_contains($sql, 'doc_pages'));

        DB::disableQueryLog();

        $this->assertCount(25, $results);
        $this->assertCount(2, $queries, 'Search should cost one index query and one match query.');
    }

    // -----------------------------------------------------------------
    // The tree
    // -----------------------------------------------------------------

    public function test_the_tree_renders_a_page_icon(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $page = $this->docPageOn($board, $team, ['title' => 'Getting started']);
        app(UpdatePage::class)->handle($page, ['icon' => "\u{1F680}"], $team);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board])
            ->assertSee("\u{1F680}", escape: false);
    }

    /**
     * The tree selects only the columns it renders, and `icon` had to join
     * them. `body_md` still must not: it is a whole document per row.
     */
    public function test_the_tree_query_reads_the_icon_but_not_the_body(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->docPageOn($board, $team, ['title' => 'Handbook', 'body_md' => str_repeat('Prose. ', 200)]);

        DB::enableQueryLog();

        app(DocPageFinder::class)->tree($board, $team);

        $select = collect(DB::getQueryLog())
            ->pluck('query')
            ->first(fn (string $sql): bool => str_contains($sql, 'from "doc_pages"'));

        DB::disableQueryLog();

        $this->assertNotNull($select);
        $this->assertStringContainsString('"doc_pages"."icon"', (string) $select);
        $this->assertStringNotContainsString('body_md', (string) $select);
    }

    // -----------------------------------------------------------------
    // Deleting from the tree
    // -----------------------------------------------------------------

    public function test_a_page_can_be_deleted_from_its_row_in_the_tree(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $keep = $this->docPageOn($board, $team, ['title' => 'Keep me']);
        $remove = $this->docPageOn($board, $team, ['title' => 'Remove me']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $keep->slug])
            ->call('deletePage', $remove->getKey())
            ->assertHasNoErrors();

        $this->assertNull(DocPage::query()->find($remove->getKey()));

        // The page being read is untouched, and there was nowhere to redirect.
        $this->assertNotNull(DocPage::query()->find($keep->getKey()));
    }

    /**
     * Deleting the branch you are reading leaves nothing to render, so the
     * section index is where you end up rather than a 404.
     */
    public function test_deleting_an_ancestor_of_the_open_page_returns_to_the_index(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $parent = $this->docPageOn($board, $team, ['title' => 'Handbook']);
        $child = $this->docPageOn($board, $team, ['title' => 'Chapter', 'parent_id' => $parent->getKey()]);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $child->slug])
            ->call('deletePage', $parent->getKey())
            ->assertRedirect(route('docs.index', $board));

        $this->assertNull(DocPage::query()->find($child->getKey()));
    }

    public function test_a_customer_cannot_delete_a_page_from_the_tree(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $page = $this->publishedPageOn($board, $team, ['title' => 'Service levels']);

        Livewire::actingAs($customer)
            ->test(DocsShow::class, ['board' => $board])
            ->call('deletePage', $page->getKey())
            ->assertForbidden();

        $this->assertNotNull(DocPage::query()->find($page->getKey()));
    }

    /**
     * An id is a request, not a fact. A page on a board this viewer has nothing
     * to do with is a 404, and looks exactly like one that does not exist.
     */
    public function test_a_page_on_another_board_cannot_be_deleted(): void
    {
        $team = $this->teamMember();
        $stranger = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $otherBoard = $this->boardWithColumns([$stranger]);

        $page = $this->docPageOn($otherBoard, $stranger, ['title' => 'Not yours']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board])
            ->call('deletePage', $page->getKey())
            ->assertNotFound();

        $this->assertNotNull(DocPage::query()->find($page->getKey()));
    }

    // -----------------------------------------------------------------
    // Creating
    // -----------------------------------------------------------------

    public function test_a_child_page_is_created_under_the_row_it_was_started_from(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $parent = $this->docPageOn($board, $team, ['title' => 'Handbook']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board])
            ->call('startCreating', $parent->getKey())
            ->assertSet('newParentId', $parent->getKey())
            ->set('newTitle', 'Chapter one')
            ->set('newIcon', "\u{1F680}")
            ->call('createPage')
            ->assertHasNoErrors();

        $child = DocPage::query()->where('title', 'Chapter one')->sole();

        $this->assertSame($parent->getKey(), $child->parent_id);
        $this->assertSame("\u{1F680}", (string) $child->icon);

        // Internal until somebody publishes it, whatever the form said.
        $this->assertFalse((bool) $child->customer_visible);
    }

    public function test_a_new_page_refuses_a_text_icon(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board])
            ->call('startCreating')
            ->set('newTitle', 'Handbook')
            ->set('newIcon', 'Read me')
            ->call('createPage')
            ->assertHasNoErrors();

        $this->assertNull(DocPage::query()->where('title', 'Handbook')->sole()->icon);
    }

    // -----------------------------------------------------------------
    // The document
    // -----------------------------------------------------------------

    /**
     * The client's requirement, as a test: a writer lands in the editor with
     * nothing to click first.
     */
    public function test_a_writer_opens_straight_into_the_editor(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook', 'body_md' => 'Prose.']);

        $html = Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->assertSet('editing', true)
            ->html();

        // The editor surface, and the title as an editable field rather than a
        // heading somebody has to leave to change.
        $this->assertStringContainsString('richEditor', $html);
        $this->assertStringContainsString('id="page-title"', $html);

        // An internal page is simply kept; there is no publish step to explain.
        $this->assertStringContainsString('Changes are saved automatically', $html);
    }

    public function test_a_published_page_offers_publishing_rather_than_silent_saving(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->publishedPageOn($board, $team, ['title' => 'Service levels']);

        $html = Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->html();

        $this->assertStringContainsString('Publish changes', $html);
        $this->assertStringNotContainsString('Changes are saved automatically', $html);
    }

    public function test_a_customer_gets_the_rendered_document_and_no_editor(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $page = $this->publishedPageOn($board, $team, [
            'title' => 'Service levels',
            'body_md' => 'We answer within a day.',
        ]);

        $html = Livewire::actingAs($customer)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->assertSet('editing', false)
            ->assertSee('We answer within a day.')
            ->html();

        // Not merely disabled — not present. A control a reader cannot use is
        // not markup they should be sent.
        $this->assertStringNotContainsString('richEditor', $html);
        $this->assertStringNotContainsString('id="page-title"', $html);
        $this->assertStringNotContainsString('Publish changes', $html);
        $this->assertStringNotContainsString('Delete page', $html);
    }

    /**
     * The contents list is mounted for a page and reads the document element it
     * is pointed at. It renders nothing until there are enough headings, which
     * is decided in the browser.
     */
    public function test_the_document_mounts_the_contents_list(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook']);

        $html = Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->html();

        $this->assertStringContainsString("docToc('#doc-content')", $html);
        $this->assertStringContainsString('id="doc-content"', $html);
        $this->assertStringContainsString('On this page', $html);
    }

    /**
     * Through the real route, so the layout around the component is exercised
     * too — a Blade error in the wrapper, a missing facade alias or a bad
     * component reference is a 500 here and invisible to a component test.
     */
    public function test_the_workspace_renders_through_the_real_route_for_a_writer(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $parent = $this->docPageOn($board, $team, ['title' => 'Handbook']);
        $page = $this->docPageOn($board, $team, [
            'title' => 'Deployment',
            'parent_id' => $parent->getKey(),
            'body_md' => "## Steps\n\nOne command.",
        ]);

        $this->actingAs($team)
            ->get(route('docs.show', ['board' => $board, 'slug' => $page->slug]))
            ->assertOk()
            // The three regions of the workspace.
            ->assertSee('id="doc-tree"', escape: false)
            ->assertSee('id="doc-content"', escape: false)
            ->assertSee('On this page')
            // The breadcrumb reflects the nesting.
            ->assertSee('Handbook')
            // And the editor is already there.
            ->assertSee('richEditor', escape: false);
    }

    public function test_the_index_mounts_no_contents_list(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $html = Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board])
            ->html();

        $this->assertStringNotContainsString('docToc', $html);
    }
}
