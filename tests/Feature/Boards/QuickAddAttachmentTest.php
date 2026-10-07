<?php

declare(strict_types=1);

namespace Tests\Feature\Boards;

use App\Enums\TicketEventType;
use App\Livewire\Boards\Show;
use App\Models\Attachment;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Files dropped onto the board's inline quick add.
 *
 * The quick add has no description and no editor — a Kanban column is 18rem
 * wide, and a writing surface in it would make the fast path a form. So a
 * dropped file does not become an inline image here; it becomes an attachment
 * on the ticket that is about to be created.
 *
 * Everything underneath is the same machinery the full creation form uses: the
 * file is stored as a draft (an attachment with no owner), held by the open
 * card, and adopted when Add is pressed. These tests aim at the three moments
 * that are specific to this surface — adoption on quick add, discard on cancel,
 * and discard when the card moves to another column.
 */
class QuickAddAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['attachments.disk' => 'local']);
    }

    public function test_a_file_dropped_on_the_card_becomes_an_attachment_on_the_new_ticket(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $column = $board->columns()->ordered()->first();

        Livewire::actingAs($team)
            ->test(Show::class, ['board' => $board])
            ->call('startQuickAdd', $column->id)
            ->set('quickAddUploads', [UploadedFile::fake()->image('safari.png')])
            ->assertHasNoErrors()
            ->set('quickAddTitle', 'Login fails on Safari')
            ->call('quickAdd')
            ->assertHasNoErrors();

        $ticket = $board->tickets()->sole();
        $attachment = Attachment::query()->sole();

        $this->assertFalse($attachment->isDraft());
        $this->assertTrue($ticket->is($attachment->attachable));
        $this->assertSame('safari.png', $attachment->filename);
    }

    /**
     * A card that arrives with a screenshot should read, a week later, as a card
     * somebody attached a screenshot to — the same event a file added from the
     * ticket page writes.
     */
    public function test_an_adopted_file_is_recorded_in_the_ticket_history(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $column = $board->columns()->ordered()->first();

        Livewire::actingAs($team)
            ->test(Show::class, ['board' => $board])
            ->call('startQuickAdd', $column->id)
            ->set('quickAddUploads', [UploadedFile::fake()->image('trace.png')])
            ->set('quickAddTitle', 'Trace attached')
            ->call('quickAdd');

        $ticket = $board->tickets()->sole();

        $event = $ticket->events()
            ->where('type', TicketEventType::AttachmentChanged->value)
            ->sole();

        $this->assertSame('added', $event->payload['action']);
        $this->assertSame('trace.png', $event->payload['filename']);
    }

    /**
     * One file off, the rest kept.
     *
     * Addressed by attachment id rather than by position in a list: the card
     * re-renders between the drop and the press, so an index would be a
     * reference to whatever happened to be in that slot by then.
     */
    public function test_a_file_can_be_taken_off_the_card_before_adding(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $column = $board->columns()->ordered()->first();

        $component = Livewire::actingAs($team)
            ->test(Show::class, ['board' => $board])
            ->call('startQuickAdd', $column->id)
            ->set('quickAddUploads', [
                UploadedFile::fake()->image('one.png'),
                UploadedFile::fake()->image('two.png'),
            ]);

        $unwanted = Attachment::query()->where('filename', 'one.png')->sole();

        $component->call('removeQuickAddUpload', $unwanted->id)
            ->set('quickAddTitle', 'One picture')
            ->call('quickAdd')
            ->assertHasNoErrors();

        // The removed draft is gone, row and object together, and the ticket
        // carries only what was left on the card.
        Storage::disk('local')->assertMissing($unwanted->path);

        $this->assertSame(
            ['two.png'],
            Ticket::query()->sole()->attachments()->pluck('filename')->all()
        );
    }

    /**
     * Cancelling takes the file down with the form.
     *
     * Not left for the pruner: somebody who presses Cancel has said what should
     * happen to the screenshot they just dropped, and a file that outlives the
     * form holding it is a file nothing on screen can reach.
     */
    public function test_cancelling_the_card_removes_the_files_dropped_on_it(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $column = $board->columns()->ordered()->first();

        Livewire::actingAs($team)
            ->test(Show::class, ['board' => $board])
            ->call('startQuickAdd', $column->id)
            ->set('quickAddUploads', [UploadedFile::fake()->image('abandoned.png')]);

        $path = Attachment::query()->sole()->path;
        Storage::disk('local')->assertExists($path);

        Livewire::actingAs($team)
            ->test(Show::class, ['board' => $board])
            ->call('startQuickAdd', $column->id)
            ->set('quickAddUploads', [UploadedFile::fake()->image('abandoned.png')])
            ->call('cancelQuickAdd');

        // The second component's own draft is gone, row and object together.
        $this->assertSame(1, Attachment::query()->count());
    }

    /**
     * Opening the card on another column abandons the one that was open.
     *
     * Carrying the files across would put a screenshot on a ticket nobody
     * dropped it on.
     */
    public function test_moving_the_card_to_another_column_discards_its_files(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $columns = $board->columns()->ordered()->get();

        Livewire::actingAs($team)
            ->test(Show::class, ['board' => $board])
            ->call('startQuickAdd', $columns[0]->id)
            ->set('quickAddUploads', [UploadedFile::fake()->image('wrong-column.png')])
            ->call('startQuickAdd', $columns[1]->id)
            ->assertSet('draftAttachmentIds', [])
            ->set('quickAddTitle', 'Something else')
            ->call('quickAdd');

        $ticket = $board->tickets()->sole();

        $this->assertSame(0, $ticket->attachments()->count());
        $this->assertSame(0, Attachment::query()->count());
    }

    /**
     * A customer may file a request and attach the screenshot that explains it.
     *
     * Their ticket is still forced customer-visible, into the first column and
     * unassigned by CreateTicket — attaching a file changes none of that.
     */
    public function test_a_customer_can_attach_to_the_request_they_raise(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $column = $board->columns()->ordered()->first();

        Livewire::actingAs($customer)
            ->test(Show::class, ['board' => $board])
            ->call('startQuickAdd', $column->id)
            ->set('quickAddUploads', [UploadedFile::fake()->image('broken.png')])
            ->set('quickAddTitle', 'This page will not load')
            ->call('quickAdd')
            ->assertHasNoErrors();

        $ticket = $board->tickets()->sole();

        $this->assertTrue($ticket->customer_visible);
        $this->assertSame(1, $ticket->attachments()->count());

        // Streamed rather than redirected: a signed URL is only ever issued for
        // a remote disk, and this is a local one. A denial would be 404.
        $this->actingAs($customer)
            ->get(route('attachments.show', $ticket->attachments()->sole()))
            ->assertOk();
    }

    public function test_a_disallowed_file_type_is_refused(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $column = $board->columns()->ordered()->first();

        Livewire::actingAs($team)
            ->test(Show::class, ['board' => $board])
            ->call('startQuickAdd', $column->id)
            ->set('quickAddUploads', [UploadedFile::fake()->create('payload.exe', 10)])
            ->assertHasErrors('quickAddUploads.*');

        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_a_file_over_the_size_limit_is_refused(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $column = $board->columns()->ordered()->first();

        config(['attachments.max_size_kb' => 100]);

        Livewire::actingAs($team)
            ->test(Show::class, ['board' => $board])
            ->call('startQuickAdd', $column->id)
            ->set('quickAddUploads', [UploadedFile::fake()->create('huge.png', 500)])
            ->assertHasErrors('quickAddUploads.*');

        $this->assertSame(0, Attachment::query()->count());
    }
}
