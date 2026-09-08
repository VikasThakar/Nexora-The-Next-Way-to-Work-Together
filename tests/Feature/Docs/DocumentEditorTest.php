<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Livewire\Docs\Show as DocsShow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Js;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The rich text editor on a documentation page.
 *
 * The editor itself — the round trip, the sanitizer, the checklist handling —
 * is covered by Tests\Feature\Tickets\RichTextEditorTest and
 * Tests\Security\RichTextSanitizationTest, and none of it is duplicated here.
 * Documentation reuses the same App\Support\RichText and the same
 * App\Livewire\Concerns\EditsRichText, deliberately, so that those tests keep
 * covering this screen too.
 *
 * What is worth testing here is the wiring that differs: a page stores its
 * prose in `body_md` rather than `description_md`, and a page being edited
 * already exists — so unlike a ticket being created, a screenshot pasted into
 * it has something to hang off.
 */
class DocumentEditorTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Wiring
    // -----------------------------------------------------------------

    public function test_the_editor_is_wired_into_the_page_for_staff(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, [
            'title' => 'Runbook',
            'body_md' => "## Existing\n\nProse.",
        ]);

        $html = Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            ->assertHasNoErrors()
            ->html();

        // ProseMirror owns the DOM inside this container; without wire:ignore
        // Livewire's morph would reconcile a live editor against server markup.
        $this->assertStringContainsString('wire:ignore', $html);
        $this->assertStringContainsString('richEditor(', $html);
        $this->assertStringContainsString('role="toolbar"', $html);

        // The autosave, which is what makes this screen different from a
        // ticket's description.
        $this->assertStringContainsString("autosave: 'autosave'", $html);

        // Files go into the editor as well as the panel below it.
        $this->assertStringContainsString('pendingUploads', $html);

        // The stored document reaches the browser as HTML, escaped the same way
        // Blade's @js does it — built with the same helper so the assertion
        // cannot drift from the template.
        $this->assertStringContainsString(trim((string) Js::from('<h2>Existing'), "'"), $html);
    }

    public function test_a_customer_is_offered_no_editor_at_all(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $page = $this->publishedPageOn($board, $team, [
            'title' => 'Service levels',
            'body_md' => 'We answer within a day.',
        ]);

        $html = $this->actingAs($customer)
            ->get(route('docs.show', ['board' => $board, 'slug' => $page->slug]))
            ->assertOk()
            ->assertSee('We answer within a day.')
            ->getContent();

        $this->assertStringNotContainsString('richEditor(', $html);
        $this->assertStringNotContainsString('startEditing', $html);
    }

    // -----------------------------------------------------------------
    // The rich surface writes Markdown
    // -----------------------------------------------------------------

    public function test_editing_on_the_rich_surface_stores_markdown(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Deploying']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            ->set('descriptionHtml', '<h2>Steps</h2><ul><li>Build</li><li>Ship</li></ul>')
            ->call('save')
            ->assertHasNoErrors();

        // Markdown in the column, not HTML. The storage format did not change.
        $this->assertSame(
            "## Steps\n\n- Build\n- Ship",
            trim((string) $page->refresh()->body_md)
        );
    }

    public function test_a_checklist_survives_a_round_trip_through_the_editor(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, [
            'title' => 'Launch',
            'body_md' => "- [ ] Write docs\n- [x] Ship it",
        ]);

        $component = Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing');

        // TipTap's task list markup, which is what the editor emits for the
        // checkboxes CommonMark rendered.
        $this->assertStringContainsString('taskList', $component->html());

        $component->call('save')->assertHasNoErrors();

        $this->assertSame(
            "- [ ] Write docs\n- [x] Ship it",
            trim((string) $page->refresh()->body_md)
        );
    }

    /**
     * The reason RichText::matches() exists, on this screen.
     */
    public function test_opening_a_page_and_saving_it_unchanged_records_no_edit(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        // `*` bullets and a padded table: both are rewritten by the conversion,
        // so without the comparison guard this would look like an edit.
        $page = $this->docPageOn($board, $team, [
            'title' => 'Reference',
            'body_md' => "* one\n* two\n\n| a | b |\n| --- | --- |\n| 1 | 2 |",
        ]);

        $original = (string) $page->body_md;
        $updatedAt = $page->updated_at;

        $this->travel(2)->minutes();

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            ->set('descriptionHtml', '<ul><li>one</li><li>two</li></ul><table><tbody><tr><td>a</td><td>b</td></tr><tr><td>1</td><td>2</td></tr></tbody></table>')
            ->call('save')
            ->assertHasNoErrors();

        $page->refresh();

        $this->assertSame($original, (string) $page->body_md);
        $this->assertTrue(
            $updatedAt->equalTo($page->updated_at),
            'Saving an unchanged document must not touch the page.'
        );
    }

    public function test_clearing_a_page_stores_nothing_rather_than_an_empty_paragraph(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Obsolete', 'body_md' => 'Delete me.']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            // What TipTap sends for an emptied document: it keeps one
            // paragraph node even with no text in it.
            ->set('descriptionHtml', '<p></p>')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($page->refresh()->body_md);
    }

    // -----------------------------------------------------------------
    // Markdown mode
    // -----------------------------------------------------------------

    public function test_markdown_mode_stores_what_was_typed_verbatim(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Source']);

        $source = "Two  \nlines with a hard break, and `*` bullets:\n\n* kept\n* exactly";

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            ->call('toggleMarkdownMode')
            ->set('bodyMd', $source)
            ->call('save')
            ->assertHasNoErrors();

        // Not normalised: somebody who chose to edit the source keeps it.
        $this->assertSame($source, (string) $page->refresh()->body_md);
    }

    public function test_switching_to_markdown_keeps_the_unsaved_document(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Notes']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            ->set('descriptionHtml', '<p>Typed on the rich surface.</p>')
            ->call('toggleMarkdownMode')
            ->assertSet('markdownMode', true)
            ->assertSet('bodyMd', 'Typed on the rich surface.')
            // Dropped, so the next save has one unambiguous source.
            ->assertSet('descriptionHtml', '');
    }

    public function test_preview_is_offered_in_markdown_mode_only(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Notes']);

        $component = Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing');

        // The rich surface already shows the document as it will read.
        $this->assertStringNotContainsString('togglePreview', $component->html());

        $component->call('toggleMarkdownMode');

        $this->assertStringContainsString('togglePreview', $component->html());
    }

    // -----------------------------------------------------------------
    // Limits
    // -----------------------------------------------------------------

    public function test_the_length_limit_applies_to_the_stored_markdown(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Enormous']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            ->set('descriptionHtml', str_repeat('<p><strong>Bold</strong> and <em>italic</em> prose here.</p>', 7000))
            ->call('save')
            ->assertHasErrors(['bodyMd']);

        $this->assertNull($page->refresh()->body_md);
    }

    public function test_a_title_longer_than_the_column_is_refused(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Fine']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            ->set('title', str_repeat('a', 201))
            ->call('save')
            ->assertHasErrors(['title']);

        $this->assertSame('Fine', $page->refresh()->title);
    }

    // -----------------------------------------------------------------
    // Attachments inside the editor
    // -----------------------------------------------------------------

    public function test_a_file_dropped_into_the_editor_becomes_an_attachment_on_the_page(): void
    {
        Storage::fake('local');

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Architecture']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            ->set('pendingUploads', [UploadedFile::fake()->image('diagram.png')])
            ->call('attachUploads')
            ->assertHasNoErrors()
            ->assertReturned(function (array $inserted) use ($page): bool {
                $attachment = $page->attachments()->sole();

                return count($inserted) === 1
                    && $inserted[0]['name'] === 'diagram.png'
                    && $inserted[0]['image'] === true
                    // The authorized route, never a storage URL: an image on an
                    // internal page must be exactly as internal as the page.
                    && $inserted[0]['url'] === route('attachments.show', $attachment);
            });

        $attachment = $page->attachments()->sole();

        $this->assertSame($board->id, $attachment->board_id);
        $this->assertSame($team->id, $attachment->uploaded_by_id);
        $this->assertSame('image/png', $attachment->mime_type);
    }

    public function test_a_second_paste_does_not_re_store_the_first(): void
    {
        Storage::fake('local');

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Architecture']);

        $component = Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            ->set('pendingUploads', [UploadedFile::fake()->image('first.png')])
            ->call('attachUploads')
            ->assertSet('pendingUploads', []);

        $component
            ->set('pendingUploads', [UploadedFile::fake()->image('second.png')])
            ->call('attachUploads');

        $this->assertSame(
            ['first.png', 'second.png'],
            $page->attachments()->orderBy('id')->pluck('filename')->all()
        );
    }

    public function test_an_unsupported_file_type_is_refused(): void
    {
        Storage::fake('local');

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Architecture']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            ->set('pendingUploads', [UploadedFile::fake()->create('payload.php', 8, 'application/x-php')])
            ->call('attachUploads')
            ->assertHasErrors(['pendingUploads.*']);

        // Same rules as the standalone panel, read from the same config.
        $this->assertSame(0, $page->attachments()->count());
    }

    // -----------------------------------------------------------------
    // Metadata
    // -----------------------------------------------------------------

    public function test_the_byline_names_the_author_and_the_last_editor(): void
    {
        $author = $this->teamMember(['name' => 'Ada Fell']);
        $reviser = $this->teamMember(['name' => 'Bo Nadir']);
        $board = $this->boardWithColumns([$author, $reviser]);

        $page = $this->docPageOn($board, $author, ['title' => 'Onboarding']);

        Livewire::actingAs($reviser)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            ->set('descriptionHtml', '<p>Revised.</p>')
            ->call('save')
            ->assertHasNoErrors();

        $this->actingAs($reviser)
            ->get(route('docs.show', ['board' => $board, 'slug' => $page->slug]))
            ->assertOk()
            ->assertSee('Created by Ada Fell')
            // Both facts, not just the most recent one: who wrote a page and
            // who last touched it are different questions.
            ->assertSee('by Bo Nadir');
    }
}
