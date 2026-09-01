<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Actions\Tickets\DeleteTicket;
use App\Enums\TicketEventType;
use App\Livewire\Tickets\Components\Attachments;
use App\Models\Attachment;
use App\Services\AttachmentStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class TicketAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_a_file_can_be_attached_to_a_ticket(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(Attachments::class, ['ticket' => $ticket])
            ->set('files', [UploadedFile::fake()->image('screenshot.png')])
            ->assertHasNoErrors();

        $attachment = $ticket->attachments()->sole();

        $this->assertSame('screenshot.png', $attachment->filename);
        $this->assertSame($board->id, $attachment->board_id);
        $this->assertSame($team->id, $attachment->uploaded_by_id);
        Storage::disk($attachment->disk)->assertExists($attachment->path);
    }

    public function test_the_stored_path_is_generated_and_never_taken_from_the_upload(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $hostile = UploadedFile::fake()->create('../../etc/passwd.txt', 4, 'text/plain');

        app(AttachmentStorage::class)->store($hostile, $ticket, $board, $team);

        $attachment = $ticket->attachments()->sole();

        $this->assertStringStartsWith('attachments/boards/'.$board->id.'/', $attachment->path);
        $this->assertStringNotContainsString('..', $attachment->path);
        $this->assertStringNotContainsString('/', $attachment->filename);
    }

    public function test_uploading_records_an_event(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(Attachments::class, ['ticket' => $ticket])
            ->set('files', [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')]);

        $event = $ticket->events()->where('type', TicketEventType::AttachmentChanged)->sole();

        $this->assertSame('added', $event->payload['action']);
        $this->assertSame('notes.pdf', $event->payload['filename']);
    }

    public function test_a_disallowed_file_type_is_rejected(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(Attachments::class, ['ticket' => $ticket])
            ->set('files', [UploadedFile::fake()->create('payload.exe', 4, 'application/octet-stream')])
            ->assertHasErrors('files.0');

        $this->assertSame(0, $ticket->attachments()->count());
    }

    public function test_an_oversized_file_is_rejected(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $tooBig = ((int) config('attachments.max_size_kb')) + 64;

        Livewire::actingAs($team)
            ->test(Attachments::class, ['ticket' => $ticket])
            ->set('files', [UploadedFile::fake()->create('huge.pdf', $tooBig, 'application/pdf')])
            ->assertHasErrors('files.0');

        $this->assertSame(0, $ticket->attachments()->count());
    }

    public function test_deleting_an_attachment_removes_the_row_and_the_object(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $attachment = app(AttachmentStorage::class)
            ->store(UploadedFile::fake()->image('a.png'), $ticket, $board, $team);

        $path = $attachment->path;

        Livewire::actingAs($team)
            ->test(Attachments::class, ['ticket' => $ticket])
            ->call('remove', $attachment->id);

        $this->assertNull(Attachment::query()->find($attachment->id));
        Storage::disk('local')->assertMissing($path);
    }

    public function test_a_customer_cannot_download_an_attachment_on_an_internal_ticket(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $internal = $this->ticketOn($board, $team);

        $attachment = app(AttachmentStorage::class)
            ->store(UploadedFile::fake()->create('secret.pdf', 4, 'application/pdf'), $internal, $board, $team);

        // Attachments inherit the visibility of the ticket that owns them.
        $this->actingAs($customer)
            ->get(route('attachments.show', $attachment))
            ->assertNotFound();

        // The authorized viewer gets the file: streamed, or a redirect to a
        // short-lived signed URL when the disk can issue one.
        $allowed = $this->actingAs($team)->get(route('attachments.show', $attachment));

        $this->assertTrue(
            $allowed->isSuccessful() || $allowed->isRedirect(),
            'An authorized viewer must receive the file, got '.$allowed->status()
        );
    }

    public function test_a_non_member_cannot_download_an_attachment(): void
    {
        $team = $this->teamMember();
        $outsider = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);

        $attachment = app(AttachmentStorage::class)
            ->store(UploadedFile::fake()->image('x.png'), $ticket, $board, $team);

        $this->actingAs($outsider)
            ->get(route('attachments.show', $attachment))
            ->assertNotFound();
    }

    public function test_a_customer_can_attach_a_file_to_a_ticket_they_raised(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $ticket = $this->ticketOn($board, $customer);

        Livewire::actingAs($customer)
            ->test(Attachments::class, ['ticket' => $ticket])
            ->set('files', [UploadedFile::fake()->image('evidence.png')])
            ->assertHasNoErrors();

        $this->assertSame(1, $ticket->attachments()->count());
    }

    public function test_a_customer_cannot_attach_a_file_to_somebody_elses_ticket(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $staffTicket = $this->ticketOn($board, $team, ['customer_visible' => true]);

        Livewire::actingAs($customer)
            ->test(Attachments::class, ['ticket' => $staffTicket])
            ->set('files', [UploadedFile::fake()->image('nope.png')])
            ->assertForbidden();

        $this->assertSame(0, $staffTicket->attachments()->count());
    }

    public function test_attachments_are_removed_with_their_ticket(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $attachment = app(AttachmentStorage::class)
            ->store(UploadedFile::fake()->image('a.png'), $ticket, $board, $team);
        $path = $attachment->path;

        app(DeleteTicket::class)->handle($ticket);

        $this->assertSame(0, Attachment::query()->count());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_production_refuses_to_boot_with_ephemeral_attachment_storage(): void
    {
        // The guard that keeps uploads off the Railway container filesystem.
        $durable = (array) config('attachments.durable_disks');

        $this->assertContains('s3', $durable);
        $this->assertNotContains('local', $durable);
    }
}
