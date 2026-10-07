<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Actions\Attachments\AdoptDraftAttachments;
use App\Livewire\Tickets\Create;
use App\Models\Attachment;
use App\Services\AttachmentStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The one attachment with no owner to inherit from.
 *
 * Every other file in this product is protected by delegation: AttachmentPolicy
 * resolves the owning record and asks that record's own policy, so a file on an
 * internal ticket is internal because the ticket is. A draft — a file dropped
 * into a creation form before the record exists — has nothing to delegate to,
 * and is therefore the one place AttachmentPolicy holds a rule of its own.
 *
 * That makes it worth its own file in this suite. Two distinct things can go
 * wrong, and they are tested separately:
 *
 *   READING    a draft id is a small integer. If knowing one were enough to
 *              fetch the file, every half-written upload in the workspace would
 *              be readable by anybody prepared to count.
 *   ADOPTING   the ids round-trip through the browser as an ordinary Livewire
 *              property. If adoption trusted them, a crafted request could pull
 *              a colleague's upload onto a ticket of its own — and then read it
 *              legitimately, because the ticket would own it.
 */
class DraftAttachmentSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['attachments.disk' => 'local']);
    }

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    /**
     * Nobody but the uploader, and that includes an administrator.
     *
     * An administrator may see every board and every ticket on it. They may not
     * see a file somebody is part-way through attaching to a ticket that does
     * not exist, because a draft carries no signal about what it will become —
     * so the only safe reading is the narrowest one.
     */
    public function test_a_draft_is_unreadable_by_everybody_except_its_uploader(): void
    {
        $author = $this->teamMember();
        $colleague = $this->teamMember();
        $admin = $this->admin();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$author, $colleague, $customer]);

        $draft = app(AttachmentStorage::class)->storeDraft(
            UploadedFile::fake()->image('half-written.png'),
            $board,
            $author,
        );

        $this->actingAs($author)->get(route('attachments.show', $draft))->assertOk();

        foreach ([$colleague, $admin, $customer] as $other) {
            $this->actingAs($other)
                ->get(route('attachments.show', $draft))
                ->assertNotFound();
        }
    }

    /**
     * Membership revoked between the upload and the fetch takes the draft with
     * it.
     *
     * A stale draft must not outlive the access that created it: the file was
     * filed under a board, and somebody who can no longer open that board has no
     * business pulling bytes out of it.
     */
    public function test_a_draft_stops_being_readable_when_the_uploader_loses_the_board(): void
    {
        $author = $this->teamMember();
        $board = $this->boardWithColumns([$author]);

        $draft = app(AttachmentStorage::class)->storeDraft(
            UploadedFile::fake()->image('revoked.png'),
            $board,
            $author,
        );

        $this->actingAs($author)->get(route('attachments.show', $draft))->assertOk();

        $board->members()->detach($author->getKey());

        $this->actingAs($author)
            ->get(route('attachments.show', $draft))
            ->assertNotFound();
    }

    /**
     * A deactivated account loses its drafts along with everything else.
     */
    public function test_a_deactivated_uploader_cannot_fetch_their_own_draft(): void
    {
        $author = $this->teamMember();
        $board = $this->boardWithColumns([$author]);

        $draft = app(AttachmentStorage::class)->storeDraft(
            UploadedFile::fake()->image('gone.png'),
            $board,
            $author,
        );

        $author->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($author)
            ->get(route('attachments.show', $draft))
            ->assertRedirect(route('login'));
    }

    // -----------------------------------------------------------------
    // Adopting
    // -----------------------------------------------------------------

    /**
     * The attack this action exists to refuse.
     *
     * An attacker who guesses a draft id and submits it with their own ticket
     * would, if adoption trusted the list, end up owning somebody else's file —
     * and could then read it perfectly legitimately, because the policy would
     * delegate to a ticket they own.
     */
    public function test_a_draft_cannot_be_adopted_by_somebody_who_did_not_upload_it(): void
    {
        $author = $this->teamMember();
        $attacker = $this->teamMember();
        $board = $this->boardWithColumns([$author, $attacker]);

        $draft = app(AttachmentStorage::class)->storeDraft(
            UploadedFile::fake()->image('not-yours.png'),
            $board,
            $author,
        );

        Livewire::actingAs($attacker)
            ->test(Create::class, ['board' => $board])
            // Exactly what a crafted request would do: the ids are an ordinary
            // Livewire property and round-trip through the browser.
            ->set('draftAttachmentIds', [$draft->getKey()])
            ->set('title', 'Mine now')
            ->call('save')
            ->assertHasNoErrors();

        $draft->refresh();

        $this->assertTrue($draft->isDraft(), 'The draft must not have gained an owner.');
        $this->assertNull($draft->attachable_id);

        // And the attacker's ticket got nothing.
        $this->assertSame(0, $board->tickets()->sole()->attachments()->count());
    }

    /**
     * Adoption does not move a file between boards.
     *
     * The stored path is laid out per board and is not rewritten, so a file
     * adopted across boards would sit under one board's prefix while belonging
     * to another's ticket — and would be missed by that board's cleanup.
     */
    public function test_a_draft_cannot_be_adopted_onto_a_ticket_on_another_board(): void
    {
        $author = $this->teamMember();
        $source = $this->boardWithColumns([$author]);
        $elsewhere = $this->boardWithColumns([$author]);

        $draft = app(AttachmentStorage::class)->storeDraft(
            UploadedFile::fake()->image('wrong-board.png'),
            $source,
            $author,
        );

        Livewire::actingAs($author)
            ->test(Create::class, ['board' => $elsewhere])
            ->set('draftAttachmentIds', [$draft->getKey()])
            ->set('title', 'Filed on the other board')
            ->call('save')
            ->assertHasNoErrors();

        $draft->refresh();

        $this->assertTrue($draft->isDraft());
        $this->assertSame(0, $elsewhere->tickets()->sole()->attachments()->count());
    }

    /**
     * A file that already belongs to something cannot be re-pointed.
     *
     * This is what makes a replayed submission harmless: the second attempt
     * finds a row that is no longer a draft and leaves it where it is, rather
     * than moving a ticket's attachment onto a newer one.
     */
    public function test_an_already_adopted_attachment_cannot_be_re_adopted(): void
    {
        $author = $this->teamMember();
        $board = $this->boardWithColumns([$author]);
        $original = $this->ticketOn($board, $author);

        $attachment = app(AttachmentStorage::class)->store(
            UploadedFile::fake()->image('settled.png'),
            $original,
            $board,
            $author,
        );

        $second = $this->ticketOn($board, $author, ['title' => 'Second ticket']);

        app(AdoptDraftAttachments::class)->handle(
            [$attachment->getKey()],
            $second,
            $board,
            $author,
        );

        $attachment->refresh();

        $this->assertTrue($original->is($attachment->attachable));
        $this->assertSame(0, $second->attachments()->count());
    }

    /**
     * Adoption asks the owner's own rule, rather than restating it.
     *
     * A customer may attach to a ticket they raised and to nothing else, so a
     * draft of theirs cannot land on somebody else's ticket even though they
     * uploaded it and it is on a board they belong to.
     */
    public function test_adoption_refuses_when_the_user_cannot_manage_the_owners_attachments(): void
    {
        $customer = $this->customer();
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team, $customer]);

        $somebodyElses = $this->ticketOn($board, $team, ['customer_visible' => true]);

        $draft = app(AttachmentStorage::class)->storeDraft(
            UploadedFile::fake()->image('mine.png'),
            $board,
            $customer,
        );

        $adopted = app(AdoptDraftAttachments::class)->handle(
            [$draft->getKey()],
            $somebodyElses,
            $board,
            $customer,
        );

        $this->assertCount(0, $adopted);
        $this->assertTrue($draft->refresh()->isDraft());
        $this->assertSame(0, $somebodyElses->attachments()->count());
    }

    /**
     * A draft that has gone must not stop a ticket being filed.
     *
     * Pruned overnight, or removed in another tab: the person pressed Save on a
     * form, and the ticket is the point of it.
     */
    public function test_a_missing_draft_does_not_block_the_save(): void
    {
        $author = $this->teamMember();
        $board = $this->boardWithColumns([$author]);

        Livewire::actingAs($author)
            ->test(Create::class, ['board' => $board])
            ->set('draftAttachmentIds', [999999])
            ->set('title', 'Still gets filed')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Still gets filed', $board->tickets()->sole()->title);
        $this->assertSame(0, Attachment::query()->count());
    }
}
