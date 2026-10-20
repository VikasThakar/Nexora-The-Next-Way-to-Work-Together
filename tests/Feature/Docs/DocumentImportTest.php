<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Jobs\ImportDocumentFile;
use App\Livewire\Docs\Show as DocsShow;
use App\Models\Attachment;
use App\Models\DocPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\FakeFiles;
use Tests\TestCase;

/**
 * Importing files as documentation pages, from the documentation screen.
 *
 * The Import buttons and the drop areas all end in the same two calls — files
 * land in `imports`, then finishImport() — so that is what these drive.
 */
class DocumentImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('attachments.disk'));
    }

    public function test_a_file_becomes_an_internal_page_with_the_original_attached(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board])
            ->set('imports', [FakeFiles::text("# Sprint notes\n\nBugs:\n- Login fails\n\n[ ] Write tests", 'sprint.txt')])
            ->call('finishImport')
            ->assertHasNoErrors()
            ->assertRedirect(route('docs.show', ['board' => $board, 'slug' => 'sprint-notes']));

        $page = $board->docPages()->sole();

        $this->assertSame('Sprint notes', $page->title);
        $this->assertFalse($page->customer_visible, 'An imported page is internal until published.');
        $this->assertStringContainsString("## Bugs\n\n- Login fails", (string) $page->body_md);
        $this->assertStringContainsString('- [ ] Write tests', (string) $page->body_md);

        $original = $page->attachments()->sole();
        $this->assertSame('sprint.txt', $original->filename);
        $this->assertFalse($original->isDraft());
        Storage::disk($original->disk)->assertExists($original->path);

        $this->assertSame('"Sprint notes" was imported. It is internal until you publish it.', session('status'));
    }

    public function test_several_files_become_several_pages_and_one_bad_file_does_not_stop_the_rest(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board])
            ->set('imports', [
                FakeFiles::text('First body.', 'first.txt'),
                UploadedFile::fake()->create('old.doc', 8, 'application/msword'),
                FakeFiles::docx("Second body\nMore text.", 'second.docx'),
            ])
            ->call('finishImport')
            ->assertRedirect(route('docs.show', ['board' => $board, 'slug' => 'first']));

        $this->assertSame(['First', 'Second'], $board->docPages()->orderBy('id')->pluck('title')->all());
        $this->assertStringContainsString('2 files were imported as pages', (string) session('status'));
        $this->assertStringContainsString('old.doc: That is an older Word document (.doc). Save it as .docx', (string) session('error'));
    }

    public function test_when_nothing_could_be_imported_the_reasons_stay_on_screen(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board])
            ->set('imports', [FakeFiles::scannedPdf('scan.pdf')])
            ->call('finishImport')
            ->assertNoRedirect()
            ->assertSee('Not imported')
            ->assertSee('no text layer')
            ->call('dismissImportErrors')
            ->assertDontSee('Not imported');

        $this->assertSame(0, $board->docPages()->count());
        $this->assertSame(0, Attachment::query()->count(), 'A file that could not be read is not kept.');
    }

    public function test_a_file_can_be_imported_inside_a_page(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $parent = $this->docPageOn($board, $team, ['title' => 'Handbook']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $parent->slug])
            ->set('importParentId', $parent->getKey())
            ->set('imports', [FakeFiles::text('Body.', 'onboarding.txt')])
            ->call('finishImport');

        $this->assertSame($parent->getKey(), DocPage::query()->where('title', 'Onboarding')->sole()->parent_id);
    }

    public function test_a_page_from_another_board_cannot_be_the_parent(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $foreign = $this->docPageOn($this->boardWithColumns([$this->teamMember()]), $this->teamMember(), ['title' => 'Theirs']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board])
            ->set('importParentId', $foreign->getKey())
            ->set('imports', [FakeFiles::text('Body.', 'mine.txt')])
            ->call('finishImport');

        $this->assertNull(DocPage::query()->where('title', 'Mine')->sole()->parent_id);
    }

    public function test_a_drop_larger_than_the_limit_imports_only_the_first_files(): void
    {
        config(['attachments.imports.max_files' => 2]);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board])
            ->set('imports', [
                FakeFiles::text('1', 'one.txt'),
                FakeFiles::text('2', 'two.txt'),
                FakeFiles::text('3', 'three.txt'),
            ])
            ->call('finishImport');

        $this->assertSame(2, $board->docPages()->count());
        $this->assertStringContainsString('three.txt: only 2 files can be imported at once.', (string) session('error'));
    }

    public function test_a_large_file_is_converted_on_the_queue(): void
    {
        config(['attachments.imports.sync_max_kb' => 1]);
        Queue::fake();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $component = Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board])
            ->set('imports', [FakeFiles::text(str_repeat("A line of the report.\n", 200), 'report.txt')])
            ->call('finishImport')
            ->assertNoRedirect()
            ->assertSee('Importing report.txt');

        Queue::assertPushedOn((string) config('ai.attachments.queue'), ImportDocumentFile::class);

        // Stored, but owned by nobody until the job makes the page.
        $draft = Attachment::query()->sole();
        $this->assertTrue($draft->isDraft());
        $this->assertSame(0, $board->docPages()->count());

        // The worker runs it.
        Queue::pushed(ImportDocumentFile::class)->first()->handle(
            app(\App\Services\Docs\Import\DocumentImporter::class),
            app(\App\Actions\Docs\ImportDocument::class),
            app(\App\Services\AttachmentStorage::class),
            app(\App\Services\BoardBroadcaster::class),
            app(\App\Services\DocPageFinder::class),
        );

        $page = $board->docPages()->sole();
        $this->assertSame($page->getKey(), $draft->refresh()->attachable_id);

        $component->call('checkImports')
            ->assertRedirect(route('docs.show', ['board' => $board, 'slug' => $page->slug]));
    }

    public function test_a_queued_file_that_cannot_be_read_reports_why_and_leaves_nothing(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $file = FakeFiles::scannedPdf('scan.pdf');
        $draft = app(\App\Services\AttachmentStorage::class)->storeDraft($file, $board, $team);

        (new ImportDocumentFile((int) $draft->getKey(), (int) $board->getKey(), (int) $team->getKey()))->handle(
            app(\App\Services\Docs\Import\DocumentImporter::class),
            app(\App\Actions\Docs\ImportDocument::class),
            app(\App\Services\AttachmentStorage::class),
            app(\App\Services\BoardBroadcaster::class),
            app(\App\Services\DocPageFinder::class),
        );

        $status = ImportDocumentFile::status((int) $draft->getKey());

        $this->assertSame('failed', $status['status']);
        $this->assertStringContainsString('no text layer', $status['error']);
        $this->assertSame(0, Attachment::query()->count());
        $this->assertSame(0, $board->docPages()->count());
    }

    public function test_staff_see_the_import_controls_and_customers_do_not(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board])
            ->assertSee('Import file')
            ->assertSee('Import a file')
            ->assertSee('Drop files to import them as pages')
            ->assertSee('Or drop files here');

        Livewire::actingAs($customer)
            ->test(DocsShow::class, ['board' => $board])
            ->assertDontSee('Import file')
            ->assertDontSee('Drop files to import them as pages');
    }

    public function test_a_customer_cannot_import(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$this->teamMember(), $customer]);

        Livewire::actingAs($customer)
            ->test(DocsShow::class, ['board' => $board])
            ->set('imports', [FakeFiles::text('Body.', 'mine.txt')])
            ->assertForbidden();

        $this->assertSame(0, $board->docPages()->count());
        $this->assertSame(0, Attachment::query()->count());
    }

    /**
     * A page with open to-dos offers to turn them into tickets, to somebody the
     * assistant would let propose changes.
     */
    public function test_a_page_with_open_to_dos_offers_to_create_tickets_with_ai(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $page = $this->docPageOn($board, $team, ['title' => 'Plan', 'body_md' => "- [ ] Ship it\n- [ ] Test it\n- [x] Plan it"]);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->assertSee('Create tickets with AI')
            ->assertSee('2 to-dos')
            ->assertSeeHtml('doc-tickets');

        $plain = $this->docPageOn($board, $team, ['title' => 'Notes', 'body_md' => 'No to-dos here.']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $plain->slug])
            ->assertDontSee('2 to-dos')
            // Still offered from the page's More menu.
            ->assertSee('Create tickets with AI');
    }
}
