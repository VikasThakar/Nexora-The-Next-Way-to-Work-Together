<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Livewire\Tickets\Create;
use App\Livewire\Tickets\Show as TicketShow;
use App\Models\Attachment;
use App\Models\Ticket;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Files dropped, pasted or chosen inside the editor.
 *
 * Nothing here is a new storage mechanism: uploads go through the same
 * App\Services\AttachmentStorage, land on the same polymorphic `attachments`
 * table, and are served by the same authorized route as a file added through
 * the attachments panel. What is new is the entry point, so these tests aim at
 * the two things an entry point can get wrong — the URL that ends up in the
 * description, and who is allowed to put it there.
 *
 * The URL is the crux. An inline image referenced by a storage URL would be
 * readable by anyone who guessed it; referenced through
 * route('attachments.show') it is re-authorized against the owning ticket on
 * every fetch, so a screenshot on an internal ticket is exactly as internal as
 * the ticket.
 */
class EditorAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private string $root = '';

    /**
     * A real local disk in a temporary directory, rather than Storage::fake().
     *
     * Storage::fake() always installs a buildTemporaryUrlsUsing() callback, so
     * every faked disk claims it can issue signed URLs and the controller
     * always redirects. That hides the branch this file needs to test: on a
     * disk that cannot issue one — which is what a local deployment is — the
     * bytes are streamed through PHP, and it is only on that path that inline
     * versus download is decided.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/editor-attachments-'.uniqid());

        config([
            'filesystems.disks.editor-testing' => [
                'driver' => 'local',
                'root' => $this->root,
                'throw' => true,
            ],
            'attachments.disk' => 'editor-testing',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            (new Filesystem)->deleteDirectory($this->root);
        }

        parent::tearDown();
    }

    public function test_an_image_pasted_into_the_editor_becomes_an_attachment_on_the_ticket(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $component = Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('startEditing')
            ->set('pendingUploads', [UploadedFile::fake()->image('screenshot.png')])
            ->call('attachUploads')
            ->assertHasNoErrors();

        $attachment = Attachment::query()->sole();

        $this->assertSame($ticket->getKey(), $attachment->attachable_id);
        $this->assertSame($ticket->getMorphClass(), $attachment->attachable_type);
        $this->assertSame($board->getKey(), $attachment->board_id);
        $this->assertSame($team->id, $attachment->uploaded_by_id);

        // The bytes are on the disk, under a generated path.
        Storage::disk('editor-testing')->assertExists($attachment->path);
        $this->assertStringNotContainsString('screenshot', $attachment->path);
        $this->assertSame('screenshot.png', $attachment->filename);

        /*
         * What the editor is told to reference.
         *
         * The whole point of the feature's security story: an inline image
         * points at route('attachments.show'), which re-authorizes against the
         * owning ticket on every fetch. A storage URL would be readable by
         * anybody who had it.
         */
        $component->assertReturned([[
            'url' => route('attachments.show', $attachment),
            'name' => 'screenshot.png',
            'image' => true,
        ]]);
    }

    public function test_the_pending_uploads_are_cleared_so_a_second_paste_does_not_duplicate(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $component = Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('startEditing')
            ->set('pendingUploads', [UploadedFile::fake()->image('one.png')])
            ->call('attachUploads')
            ->assertSet('pendingUploads', []);

        $component
            ->set('pendingUploads', [UploadedFile::fake()->image('two.png')])
            ->call('attachUploads');

        // Two files, not three: Livewire's uploadMultiple appends by default,
        // so a form that did not reset would re-store the first one.
        $this->assertSame(2, Attachment::query()->count());
        $this->assertEqualsCanonicalizing(
            ['one.png', 'two.png'],
            Attachment::query()->pluck('filename')->all()
        );
    }

    /**
     * A file added from inside the editor is history, exactly like one added
     * through the panel: same event type, so the timeline does not care which
     * route it came in by.
     */
    public function test_an_editor_upload_is_recorded_in_the_ticket_history(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('startEditing')
            ->set('pendingUploads', [UploadedFile::fake()->image('diagram.png')])
            ->call('attachUploads');

        $event = $ticket->events()->where('type', 'attachment_changed')->sole();

        $this->assertSame('added', $event->payload['action']);
        $this->assertSame('diagram.png', $event->payload['filename']);
    }

    public function test_a_non_image_is_offered_as_a_link_rather_than_an_image(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('startEditing')
            ->set('pendingUploads', [UploadedFile::fake()->create('report.pdf', 12, 'application/pdf')])
            ->call('attachUploads')
            ->assertHasNoErrors()
            // `image` is what tells the editor whether to insert an <img> or a
            // link, so a PDF must not come back claiming to be a picture.
            ->assertReturned(static fn (array $inserted): bool => $inserted[0]['image'] === false
                && $inserted[0]['name'] === 'report.pdf');
    }

    /**
     * The same limits as the standalone panel, read from the same
     * configuration: one upload policy for the product rather than one per
     * place a file can be added.
     */
    public function test_a_disallowed_file_type_is_refused(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('startEditing')
            ->set('pendingUploads', [UploadedFile::fake()->create('payload.exe', 4)])
            ->call('attachUploads')
            ->assertHasErrors('pendingUploads.*');

        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_a_file_over_the_size_limit_is_refused(): void
    {
        config(['attachments.max_size_kb' => 100]);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('startEditing')
            ->set('pendingUploads', [UploadedFile::fake()->image('huge.png')->size(400)])
            ->call('attachUploads')
            ->assertHasErrors('pendingUploads.*');

        $this->assertSame(0, Attachment::query()->count());
    }

    // -----------------------------------------------------------------
    // Authorization
    // -----------------------------------------------------------------

    /**
     * The reason inline images use the authorized route.
     */
    public function test_an_inline_image_on_an_internal_ticket_is_not_readable_by_a_customer(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        // Internal by default.
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('startEditing')
            ->set('pendingUploads', [UploadedFile::fake()->image('internal-diagram.png')])
            ->call('attachUploads');

        $attachment = Attachment::query()->sole();

        // The author can fetch it.
        $this->actingAs($team)->get(route('attachments.show', $attachment))->assertOk();

        // The customer on the same board cannot, and gets a 404 rather than a
        // 403: the existence of the file is itself information.
        $this->actingAs($customer)->get(route('attachments.show', $attachment))->assertNotFound();
    }

    public function test_somebody_who_cannot_edit_the_ticket_cannot_attach_to_it(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        // Raised by the team, so the customer may read it but not edit it.
        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);

        Livewire::actingAs($customer)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->set('pendingUploads', [UploadedFile::fake()->image('mine.png')])
            ->call('attachUploads')
            ->assertForbidden();

        $this->assertSame(0, Attachment::query()->count());
    }

    /**
     * Attaching a screenshot is a normal part of raising a request, so a
     * customer editing their own ticket can do it — the rule
     * TicketPolicy::manageAttachments has always applied.
     */
    public function test_a_customer_can_attach_to_a_request_they_raised(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $ticket = $this->ticketOn($board, $customer);

        Livewire::actingAs($customer)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('startEditing')
            ->set('pendingUploads', [UploadedFile::fake()->image('what-i-see.png')])
            ->call('attachUploads')
            ->assertHasNoErrors();

        $this->assertSame(1, Attachment::query()->count());
    }

    /**
     * An image is served inline so it can render in a description; anything
     * else still downloads. Both are the same authorized route.
     */
    public function test_an_image_is_served_inline_and_a_document_is_not(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('startEditing')
            ->set('pendingUploads', [
                UploadedFile::fake()->image('picture.png'),
                UploadedFile::fake()->create('notes.pdf', 8, 'application/pdf'),
            ])
            ->call('attachUploads')
            ->assertHasNoErrors();

        $image = Attachment::query()->where('filename', 'picture.png')->sole();
        $document = Attachment::query()->where('filename', 'notes.pdf')->sole();

        $this->actingAs($team)
            ->get(route('attachments.show', $image))
            ->assertOk()
            ->assertHeader('content-type', 'image/png')
            // The Content-Type is the stored one and the browser may not
            // second-guess it, which is what keeps serving inline safe.
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertHeader('content-disposition', 'inline; filename=picture.png');

        $this->actingAs($team)
            ->get(route('attachments.show', $document))
            ->assertOk()
            ->assertDownload('notes.pdf');
    }

    // -----------------------------------------------------------------
    // The creation form: files before there is a ticket
    // -----------------------------------------------------------------

    /**
     * There is no ticket to hang a file off until the creation form is saved,
     * and AttachmentPolicy resolves owners from an explicit allow-list — so
     * the form does not invent a draft owner. It stages the file instead: a
     * temporary upload, a preview URL for the editor, and no attachment row
     * until save() has something for one to point at.
     */
    public function test_a_file_dropped_into_the_creation_form_is_staged_rather_than_stored(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $component = Livewire::actingAs($team)
            ->test(Create::class, ['board' => $board])
            ->assertViewHas('canAttachInEditor', true)
            ->set('pendingUploads', [UploadedFile::fake()->image('early.png')])
            ->call('attachUploads')
            ->assertHasNoErrors()
            ->assertSet('pendingUploads', [])
            ->assertCount('stagedUploads', 1)
            ->assertReturned(static fn (array $inserted): bool => count($inserted) === 1
                && $inserted[0]['name'] === 'early.png'
                && $inserted[0]['image'] === true
                && is_string($inserted[0]['url'])
                && str_contains($inserted[0]['url'], '/preview-file/'));

        // Nothing on the attachments table yet: there is no owner for a row.
        $this->assertSame(0, Attachment::query()->count());

        // The strip under the editor lists it, as a picture.
        $component->assertSee('Attached when the ticket is created')
            ->assertSeeHtml('alt="early.png"');
    }

    public function test_staged_files_are_stored_on_the_new_ticket_and_the_preview_url_is_rewritten(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $preview = null;

        $component = Livewire::actingAs($team)
            ->test(Create::class, ['board' => $board])
            ->set('pendingUploads', [UploadedFile::fake()->image('screen.png')])
            ->call('attachUploads')
            ->assertReturned(static function (array $inserted) use (&$preview): bool {
                $preview = $inserted[0]['url'] ?? null;

                return is_string($preview);
            })
            // A recording the editor cannot preview is staged with no URL and
            // inserted nowhere; it is attached and linked on save instead.
            ->set('pendingUploads', [UploadedFile::fake()->create('trace.log', 4, 'text/plain')])
            ->call('attachUploads')
            ->assertReturned(static fn (array $inserted): bool => $inserted[0]['url'] === null
                && $inserted[0]['image'] === false);

        // What the editor would have pushed: the screenshot inline, by its
        // preview URL, in the middle of the text.
        $component
            ->set('title', 'Login page is blank')
            ->set('descriptionHtml', '<p>Before:</p><img src="'.e($preview).'" alt="screen.png"><p>After nothing.</p>')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $ticket = Ticket::query()->sole();

        $this->assertSame(2, $ticket->attachments()->count());

        $image = $ticket->attachments()->where('filename', 'screen.png')->sole();
        $log = $ticket->attachments()->where('filename', 'trace.log')->sole();

        Storage::disk('editor-testing')->assertExists($image->path);
        Storage::disk('editor-testing')->assertExists($log->path);

        // The description points at the authorized route, never at the
        // temporary preview, and the file with no inline place is linked so it
        // is visible on the ticket rather than only in the panel.
        $description = (string) $ticket->description_md;

        $this->assertStringNotContainsString('/preview-file/', $description);
        $this->assertStringContainsString('![screen.png]('.route('attachments.show', $image).')', $description);
        $this->assertStringContainsString('[trace.log]('.route('attachments.show', $log).')', $description);
        $this->assertStringContainsString('Before:', $description);

        // History records both, as the panel would have.
        $this->assertSame(2, $ticket->events()->where('type', 'attachment_changed')->count());
    }

    public function test_a_staged_image_the_editor_does_not_mention_is_still_embedded(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Create::class, ['board' => $board])
            ->set('pendingUploads', [UploadedFile::fake()->image('dropped.png')])
            ->call('attachUploads')
            ->set('title', 'Just a screenshot')
            ->set('descriptionMd', 'See below.')
            ->call('save')
            ->assertHasNoErrors();

        $ticket = Ticket::query()->sole();
        $image = $ticket->attachments()->sole();

        $this->assertSame(
            'See below.'."\n\n".'![dropped.png]('.route('attachments.show', $image).')',
            $ticket->description_md
        );
    }

    public function test_a_staged_file_can_be_removed_before_saving(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Create::class, ['board' => $board])
            ->set('pendingUploads', [UploadedFile::fake()->image('one.png'), UploadedFile::fake()->image('two.png')])
            ->call('attachUploads')
            ->assertCount('stagedUploads', 2)
            ->call('removeStaged', 0)
            ->assertCount('stagedUploads', 1)
            ->set('title', 'Only one')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['two.png'], Ticket::query()->sole()->attachments()->pluck('filename')->all());
    }

    public function test_the_creation_form_applies_the_same_upload_rules(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Create::class, ['board' => $board])
            ->set('pendingUploads', [UploadedFile::fake()->create('payload.exe', 4)])
            ->call('attachUploads')
            ->assertHasErrors('pendingUploads.*')
            ->assertCount('stagedUploads', 0);
    }

    /**
     * Staging is gated on being allowed to create a ticket on the board, which
     * the form already is — but the method is callable on its own.
     */
    public function test_somebody_who_cannot_create_on_the_board_cannot_stage(): void
    {
        $team = $this->teamMember();
        $outsider = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($outsider)
            ->test(Create::class, ['board' => $board])
            ->assertNotFound();
    }

    /**
     * A screen recording is a bug report too. Served inline, like an image,
     * so the panel can play it.
     */
    public function test_a_video_is_accepted_and_served_inline(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('startEditing')
            ->set('pendingUploads', [UploadedFile::fake()->create('repro.mp4', 64, 'video/mp4')])
            ->call('attachUploads')
            ->assertHasNoErrors();

        $attachment = Attachment::query()->sole();

        // The fake's bytes are zeros, so detection cannot say "video"; the
        // stored type is what the controller decides on, so it is set here to
        // what a real recording detects as.
        $attachment->forceFill(['mime_type' => 'video/mp4'])->save();

        $this->assertTrue($attachment->isVideo());
        $this->assertTrue($attachment->isPreviewable());

        $response = $this->actingAs($team)->get(route('attachments.show', $attachment));

        $response->assertOk();
        $this->assertSame('video/mp4', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
    }
}
