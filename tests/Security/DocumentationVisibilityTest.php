<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Actions\Docs\MovePage;
use App\Actions\Docs\SetPageVisibility;
use App\Livewire\Docs\Show as DocsShow;
use App\Models\DocPage;
use App\Services\DocPageFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * A customer must never receive an internal documentation page.
 *
 * Documentation adds a failure mode tickets do not have: a tree. A page can be
 * published while its parent is not, and then the breadcrumb, the sidebar path
 * and the sibling list all leak titles the customer must not see. Several tests
 * below attack precisely that, from both directions — publishing a child under
 * an internal parent, and moving a published page under one.
 */
class DocumentationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_cannot_open_an_internal_page_by_url(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $page = $this->docPageOn($board, $team, ['title' => 'Production runbook']);

        $this->actingAs($customer)
            ->get(route('docs.show', ['board' => $board, 'slug' => $page->slug]))
            ->assertNotFound();
    }

    public function test_an_internal_page_and_a_nonexistent_one_are_indistinguishable(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $page = $this->docPageOn($board, $team, ['title' => 'Production runbook']);

        $forInternal = $this->actingAs($customer)
            ->get(route('docs.show', ['board' => $board, 'slug' => $page->slug]));

        $forMissing = $this->actingAs($customer)
            ->get(route('docs.show', ['board' => $board, 'slug' => 'no-such-page']));

        $this->assertSame(404, $forInternal->status());
        $this->assertSame(404, $forMissing->status());
        $this->assertStringNotContainsString('Production runbook', $forInternal->getContent());
    }

    public function test_the_tree_never_shows_an_internal_page_to_a_customer(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $this->docPageOn($board, $team, ['title' => 'Internal deployment notes']);
        $this->publishedPageOn($board, $team, ['title' => 'Getting started']);

        $this->actingAs($customer)
            ->get(route('docs.index', $board))
            ->assertOk()
            ->assertSee('Getting started')
            ->assertDontSee('Internal deployment notes');
    }

    /**
     * The case the spec calls out: a published parent with an internal child.
     */
    public function test_an_internal_child_of_a_published_parent_stays_hidden(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $parent = $this->publishedPageOn($board, $team, ['title' => 'Onboarding']);
        $child = $this->docPageOn($board, $team, [
            'title' => 'Credential rotation',
            'parent_id' => $parent->getKey(),
        ]);

        $response = $this->actingAs($customer)
            ->get(route('docs.show', ['board' => $board, 'slug' => $parent->slug]))
            ->assertOk();

        $this->assertStringNotContainsString('Credential rotation', $response->getContent());

        $this->actingAs($customer)
            ->get(route('docs.show', ['board' => $board, 'slug' => $child->slug]))
            ->assertNotFound();
    }

    /**
     * The dangerous inverse, forced into existence with a direct write so the
     * read side has to defend itself without help from the actions.
     */
    public function test_a_published_page_under_an_internal_parent_is_still_unreachable(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $parent = $this->docPageOn($board, $team, ['title' => 'Security posture']);
        $child = $this->docPageOn($board, $team, [
            'title' => 'Incident log',
            'parent_id' => $parent->getKey(),
        ]);

        // Bypass the actions entirely: this is the broken state the read side
        // must survive, however it came about.
        DocPage::query()->whereKey($child->getKey())->update(['customer_visible' => true]);

        $finder = app(DocPageFinder::class);

        $this->assertFalse($finder->isVisible($child->refresh(), $customer));

        $this->actingAs($customer)
            ->get(route('docs.show', ['board' => $board, 'slug' => $child->slug]))
            ->assertNotFound();

        // And it is pruned out of the tree along with its subtree, so it cannot
        // appear as an orphan in the sidebar either.
        $titles = $this->flattenTitles($finder->tree($board, $customer));

        $this->assertNotContains('Incident log', $titles);
        $this->assertNotContains('Security posture', $titles);
    }

    public function test_publishing_under_an_internal_parent_is_refused(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $parent = $this->docPageOn($board, $team, ['title' => 'Internal handbook']);
        $child = $this->docPageOn($board, $team, [
            'title' => 'Support rota',
            'parent_id' => $parent->getKey(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Internal handbook');

        app(SetPageVisibility::class)->handle($child, true, $team);
    }

    public function test_making_a_page_internal_hides_everything_beneath_it(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $parent = $this->publishedPageOn($board, $team, ['title' => 'Handbook']);
        $child = $this->publishedPageOn($board, $team, [
            'title' => 'Release process',
            'parent_id' => $parent->getKey(),
        ]);
        $grandchild = $this->publishedPageOn($board, $team, [
            'title' => 'Hotfixes',
            'parent_id' => $child->getKey(),
        ]);

        $this->assertTrue(app(DocPageFinder::class)->isVisible($grandchild, $customer));

        $changed = app(SetPageVisibility::class)->handle($parent, false, $team);

        $this->assertSame(3, $changed);
        $this->assertFalse($child->refresh()->customer_visible);
        $this->assertFalse($grandchild->refresh()->customer_visible);

        $this->actingAs($customer)
            ->get(route('docs.show', ['board' => $board, 'slug' => $grandchild->slug]))
            ->assertNotFound();
    }

    public function test_a_published_page_cannot_be_moved_under_an_internal_parent(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $internalParent = $this->docPageOn($board, $team, ['title' => 'Internal area']);
        $published = $this->publishedPageOn($board, $team, ['title' => 'Public page']);

        $this->expectException(RuntimeException::class);

        app(MovePage::class)->handle($published, $internalParent, 0, $team);
    }

    public function test_a_breadcrumb_never_names_an_internal_ancestor(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $internalParent = $this->docPageOn($board, $team, ['title' => 'Confidential root']);
        $child = $this->docPageOn($board, $team, [
            'title' => 'Child',
            'parent_id' => $internalParent->getKey(),
        ]);

        DocPage::query()->whereKey($child->getKey())->update(['customer_visible' => true]);

        // Nothing at all, rather than a partial trail: a trail with a gap would
        // still tell the customer that something sits above this page.
        $this->assertCount(0, app(DocPageFinder::class)->ancestors($child->refresh(), $customer));
        $this->assertCount(1, app(DocPageFinder::class)->ancestors($child, $team));
    }

    public function test_search_does_not_surface_internal_pages_to_a_customer(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $this->docPageOn($board, $team, ['title' => 'Zebra internal notes']);
        $this->publishedPageOn($board, $team, ['title' => 'Zebra public notes']);

        Livewire::actingAs($customer)
            ->test(DocsShow::class, ['board' => $board])
            ->set('search', 'Zebra')
            ->assertSee('Zebra public notes')
            ->assertDontSee('Zebra internal notes');
    }

    public function test_a_non_member_receives_no_documentation_at_all(): void
    {
        $team = $this->teamMember();
        $outsider = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $page = $this->publishedPageOn($board, $team, ['title' => 'Members only']);

        $this->actingAs($outsider)
            ->get(route('docs.index', $board))
            ->assertNotFound();

        $this->actingAs($outsider)
            ->get(route('docs.show', ['board' => $board, 'slug' => $page->slug]))
            ->assertNotFound();

        $this->assertCount(0, app(DocPageFinder::class)->tree($board, $outsider));
    }

    public function test_a_customer_cannot_write_documentation(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $page = $this->publishedPageOn($board, $team, ['title' => 'Readable']);

        Livewire::actingAs($customer)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startCreating')
            ->assertForbidden();

        Livewire::actingAs($customer)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            ->assertForbidden();

        Livewire::actingAs($customer)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('toggleVisibility')
            ->assertForbidden();

        Livewire::actingAs($customer)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('confirmDelete')
            ->assertForbidden();
    }

    /**
     * The editor added three write paths that are reachable without going
     * through startEditing() — a timer, a blurred field and a button on the
     * read view. Each has to refuse a customer on its own.
     */
    public function test_a_customer_cannot_reach_the_editors_own_write_paths(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $page = $this->publishedPageOn($board, $team, [
            'title' => 'Readable',
            'body_md' => 'As published.',
        ]);

        // The autosave. Marked as editing first, because a customer forging a
        // request would.
        Livewire::actingAs($customer)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->set('editing', true)
            ->set('descriptionHtml', '<p>Injected.</p>')
            ->call('autosave')
            ->assertForbidden();

        // The title field's blur hook.
        Livewire::actingAs($customer)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->set('editing', true)
            ->set('title', 'Renamed by a customer')
            ->assertForbidden();

        Livewire::actingAs($customer)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('cancelEditing')
            ->assertForbidden();

        Livewire::actingAs($customer)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('discardDraft')
            ->assertForbidden();

        $page->refresh();

        $this->assertSame('Readable', $page->title);
        $this->assertSame('As published.', (string) $page->body_md);
        $this->assertNull($page->draft_saved_at);
    }

    /**
     * A file pasted into the editor is an attachment on the page, so it is
     * exactly as protected as the page — and a customer may not add one at all.
     */
    public function test_a_customer_cannot_attach_a_file_through_the_editor(): void
    {
        Storage::fake('local');

        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $page = $this->publishedPageOn($board, $team, ['title' => 'Readable']);

        Livewire::actingAs($customer)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->set('pendingUploads', [UploadedFile::fake()->image('theirs.png')])
            ->call('attachUploads')
            // manageAttachments on a page is DocPagePolicy::update, which is
            // staff only. The upload never reaches AttachmentStorage.
            ->assertForbidden();

        $this->assertSame(0, $page->attachments()->count());

        // And the control is not offered either.
        $this->actingAs($customer)
            ->get(route('docs.show', ['board' => $board, 'slug' => $page->slug]))
            ->assertOk()
            ->assertDontSee('attachUploads');
    }

    public function test_a_customer_cannot_reorder_the_tree(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $first = $this->publishedPageOn($board, $team, ['title' => 'First']);
        $second = $this->publishedPageOn($board, $team, ['title' => 'Second']);

        Livewire::actingAs($customer)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $first->slug])
            ->call('movePage', $second->getKey(), 0, null)
            ->assertForbidden();

        $this->assertSame(0, $first->refresh()->position);
        $this->assertSame(1, $second->refresh()->position);
    }

    /**
     * @param  Collection<int, object>  $nodes
     * @return array<int, string>
     */
    private function flattenTitles($nodes): array
    {
        $titles = [];

        foreach ($nodes as $node) {
            $titles[] = $node->page->title;
            $titles = array_merge($titles, $this->flattenTitles($node->children));
        }

        return $titles;
    }
}
