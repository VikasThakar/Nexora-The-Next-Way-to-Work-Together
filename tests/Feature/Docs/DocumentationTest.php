<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Actions\Boards\DeleteBoard;
use App\Actions\Docs\CreatePage;
use App\Actions\Docs\DeletePage;
use App\Actions\Docs\MovePage;
use App\Livewire\Docs\Components\Attachments;
use App\Livewire\Docs\Show as DocsShow;
use App\Models\Attachment;
use App\Models\DocPage;
use App\Services\AttachmentStorage;
use App\Services\DocPageFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class DocumentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_staff_member_can_create_a_page_and_it_starts_internal(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board])
            ->call('startCreating')
            ->set('newTitle', 'How we deploy')
            ->call('createPage')
            ->assertHasNoErrors()
            ->assertRedirect(route('docs.show', ['board' => $board, 'slug' => 'how-we-deploy']));

        $page = $board->docPages()->sole();

        $this->assertSame('How we deploy', $page->title);
        $this->assertSame('how-we-deploy', $page->slug);
        $this->assertFalse($page->customer_visible, 'Documentation is internal by default.');
        $this->assertNull($page->parent_id);
        $this->assertSame($team->id, $page->created_by_id);
    }

    public function test_slugs_are_unique_per_board(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $other = $this->boardWithColumns([$team]);

        $first = $this->docPageOn($board, $team, ['title' => 'Runbook']);
        $second = $this->docPageOn($board, $team, ['title' => 'Runbook']);
        $elsewhere = $this->docPageOn($other, $team, ['title' => 'Runbook']);

        $this->assertSame('runbook', $first->slug);
        $this->assertSame('runbook-2', $second->slug);

        // A different board is a different namespace.
        $this->assertSame('runbook', $elsewhere->slug);
    }

    public function test_renaming_a_page_does_not_change_its_url(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Old name']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            ->set('title', 'New name')
            ->set('bodyMd', '## Contents')
            ->call('save')
            ->assertHasNoErrors();

        $page->refresh();

        $this->assertSame('New name', $page->title);
        $this->assertSame('old-name', $page->slug);
        $this->assertSame('## Contents', $page->body_md);
        $this->assertSame($team->id, $page->updated_by_id);
    }

    public function test_a_page_renders_markdown_including_tables_and_code(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $page = $this->docPageOn($board, $team, [
            'title' => 'Reference',
            'body_md' => "# Title\n\n| a | b |\n| - | - |\n| 1 | 2 |\n\n```php\n\$x = 1;\n```\n",
        ]);

        $this->actingAs($team)
            ->get(route('docs.show', ['board' => $board, 'slug' => $page->slug]))
            ->assertOk()
            ->assertSee('<h1>Title</h1>', escape: false)
            ->assertSee('<table>', escape: false)
            // Highlighted server-side, so the class list carries the language
            // and highlight.js's own hooks.
            ->assertSee('class="language-php hljs php"', escape: false);
    }

    public function test_pages_nest_and_the_tree_reflects_it(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $parent = $this->docPageOn($board, $team, ['title' => 'Handbook']);
        $child = $this->docPageOn($board, $team, ['title' => 'Chapter one', 'parent_id' => $parent->getKey()]);

        $tree = app(DocPageFinder::class)->tree($board, $team);

        $this->assertCount(1, $tree);
        $this->assertSame('Handbook', $tree->first()->page->title);
        $this->assertCount(1, $tree->first()->children);
        $this->assertSame('Chapter one', $tree->first()->children->first()->page->title);
        $this->assertSame($parent->id, $child->parent_id);
    }

    public function test_nesting_deeper_than_the_maximum_falls_back_to_the_root(): void
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

        // One level too deep: the parent is refused and the page lands at the
        // root rather than being rejected outright.
        $tooDeep = $this->docPageOn($board, $team, [
            'title' => 'Too deep',
            'parent_id' => $current->getKey(),
        ]);

        $this->assertNull($tooDeep->parent_id);
    }

    public function test_a_page_can_be_reordered_among_its_siblings(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $first = $this->docPageOn($board, $team, ['title' => 'First']);
        $second = $this->docPageOn($board, $team, ['title' => 'Second']);
        $third = $this->docPageOn($board, $team, ['title' => 'Third']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board])
            ->call('movePage', $third->getKey(), 0, null);

        $this->assertSame(0, $third->refresh()->position);
        $this->assertSame(1, $first->refresh()->position);
        $this->assertSame(2, $second->refresh()->position);
    }

    public function test_a_page_can_be_dragged_under_another_page(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $parent = $this->docPageOn($board, $team, ['title' => 'Parent']);
        $loose = $this->docPageOn($board, $team, ['title' => 'Loose']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board])
            ->call('movePage', $loose->getKey(), 0, $parent->getKey());

        $this->assertSame($parent->id, $loose->refresh()->parent_id);
        $this->assertSame(0, $loose->position);
    }

    public function test_a_page_cannot_be_moved_inside_itself(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $parent = $this->docPageOn($board, $team, ['title' => 'Parent']);
        $child = $this->docPageOn($board, $team, ['title' => 'Child', 'parent_id' => $parent->getKey()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('inside itself');

        app(MovePage::class)->handle($parent, $child, 0, $team);
    }

    public function test_a_page_cannot_be_moved_to_another_board(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $other = $this->boardWithColumns([$team]);

        $page = $this->docPageOn($board, $team, ['title' => 'Here']);
        $elsewhere = $this->docPageOn($other, $team, ['title' => 'There']);

        $this->expectException(RuntimeException::class);

        app(MovePage::class)->handle($page, $elsewhere, 0, $team);
    }

    public function test_publishing_and_retracting_a_page(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $page = $this->docPageOn($board, $team, ['title' => 'Service levels']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('toggleVisibility');

        $this->assertTrue($page->refresh()->customer_visible);

        $this->actingAs($customer)
            ->get(route('docs.show', ['board' => $board, 'slug' => $page->slug]))
            ->assertOk()
            ->assertSee('Service levels');

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('toggleVisibility');

        $this->assertFalse($page->refresh()->customer_visible);
    }

    public function test_deleting_a_page_removes_its_whole_subtree(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $root = $this->docPageOn($board, $team, ['title' => 'Root']);
        $child = $this->docPageOn($board, $team, ['title' => 'Child', 'parent_id' => $root->getKey()]);
        $grandchild = $this->docPageOn($board, $team, ['title' => 'Grandchild', 'parent_id' => $child->getKey()]);
        $survivor = $this->docPageOn($board, $team, ['title' => 'Unrelated']);

        $removed = app(DeletePage::class)->handle($root);

        $this->assertSame(3, $removed);
        $this->assertNull(DocPage::query()->find($root->getKey()));
        $this->assertNull(DocPage::query()->find($child->getKey()));
        $this->assertNull(DocPage::query()->find($grandchild->getKey()));
        $this->assertNotNull(DocPage::query()->find($survivor->getKey()));
    }

    public function test_deleting_a_page_removes_its_attachments(): void
    {
        Storage::fake('local');

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $page = $this->docPageOn($board, $team, ['title' => 'With files']);

        $attachment = app(AttachmentStorage::class)
            ->store(UploadedFile::fake()->image('diagram.png'), $page, $board, $team);

        $path = $attachment->path;

        app(DeletePage::class)->handle($page);

        $this->assertSame(0, Attachment::query()->count());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_deleting_a_board_takes_its_documentation_with_it(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $root = $this->docPageOn($board, $team, ['title' => 'Root']);
        $child = $this->docPageOn($board, $team, ['title' => 'Child', 'parent_id' => $root->getKey()]);
        $this->docPageOn($board, $team, ['title' => 'Grandchild', 'parent_id' => $child->getKey()]);
        $this->ticketOn($board, $team);

        // The RESTRICT constraint on parent_id means this only works because
        // DeleteBoard peels the tree off leaves-first.
        app(DeleteBoard::class)->handle($board);

        $this->assertSame(0, DocPage::query()->count());
    }

    public function test_a_page_can_carry_attachments(): void
    {
        Storage::fake('local');

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Architecture']);

        Livewire::actingAs($team)
            ->test(Attachments::class, ['page' => $page])
            ->set('files', [UploadedFile::fake()->image('diagram.png')])
            ->assertHasNoErrors();

        $attachment = $page->attachments()->sole();

        $this->assertSame('diagram.png', $attachment->filename);
        $this->assertSame($board->id, $attachment->board_id);
    }

    public function test_the_docs_index_shows_an_empty_state(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->actingAs($team)
            ->get(route('docs.index', $board))
            ->assertOk()
            ->assertSee('Nothing has been written for this board yet.');
    }

    public function test_a_title_is_required(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board])
            ->call('startCreating')
            ->set('newTitle', '')
            ->call('createPage')
            ->assertHasErrors('newTitle');

        $this->assertSame(0, $board->docPages()->count());
    }

    public function test_a_page_with_no_usable_title_still_gets_a_slug(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        // A title of only punctuation slugs to an empty string.
        $page = app(CreatePage::class)->handle($board, ['title' => '???'], $team);

        $this->assertSame('page', $page->slug);
    }
}
