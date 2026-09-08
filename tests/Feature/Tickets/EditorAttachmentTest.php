<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Livewire\Tickets\Create;
use App\Livewire\Tickets\Show as TicketShow;
use App\Models\Attachment;
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

    /**
     * There is no ticket to hang a file off until the creation form is saved,
     * and AttachmentPolicy resolves owners from an explicit allow-list — so the
     * editor there offers no upload rather than inventing a draft owner.
     */
    public function test_the_creation_form_offers_no_upload(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Create::class, ['board' => $board])
            ->assertSee('once the ticket exists')
            // And driving it directly stores nothing rather than erroring.
            ->set('pendingUploads', [UploadedFile::fake()->image('early.png')])
            ->call('attachUploads')
            ->assertHasNoErrors();

        $this->assertSame(0, Attachment::query()->count());
    }
}
