<?php

declare(strict_types=1);

namespace Tests\Feature\Attachments;

use App\Actions\Users\UpdateAvatar;
use App\Models\Attachment;
use App\Services\AttachmentStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeFiles;
use Tests\TestCase;

/**
 * Collecting files dropped into a form that was never saved.
 *
 * A draft is an attachment with no owner, and it only exists between the drop
 * and the Save that adopts it. A form abandoned instead — the tab closed, the
 * mind changed — leaves one behind that nothing in the application can reach
 * again: the only list of its id was the Livewire component that has since gone.
 *
 * So `workspace:prune` is the thing that collects them, and these tests pin down
 * the two halves that matter: that it takes the bytes as well as the row, and
 * that it cannot touch a file some ticket already owns.
 */
class PruneAbandonedDraftsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['attachments.disk' => 'local']);
    }

    public function test_an_abandoned_draft_is_removed_with_its_file(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $draft = app(AttachmentStorage::class)->storeDraft(
            UploadedFile::fake()->image('forgotten.png'),
            $board,
            $team,
        );

        Storage::disk('local')->assertExists($draft->path);

        // Older than the window. Written directly rather than by travelling,
        // so the test says what it is about.
        $draft->forceFill(['created_at' => now()->subHours(48)])->save();

        $this->artisan('workspace:prune')->assertSuccessful();

        $this->assertNull(Attachment::query()->find($draft->getKey()));
        Storage::disk('local')->assertMissing($draft->path);
    }

    public function test_a_recent_draft_is_left_alone(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $draft = app(AttachmentStorage::class)->storeDraft(
            UploadedFile::fake()->image('still-writing.png'),
            $board,
            $team,
        );

        $this->artisan('workspace:prune')->assertSuccessful();

        // Somebody who left a half-written ticket open over lunch still has
        // their screenshot.
        $this->assertNotNull(Attachment::query()->find($draft->getKey()));
        Storage::disk('local')->assertExists($draft->path);
    }

    /**
     * The scope is "no owner", so a file a ticket owns is not a candidate
     * however old it is.
     */
    public function test_an_adopted_attachment_is_never_pruned(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $storage = app(AttachmentStorage::class);

        $attachment = $storage->store(
            UploadedFile::fake()->image('kept.png'),
            $ticket,
            $board,
            $team,
        );

        $attachment->forceFill(['created_at' => now()->subYear()])->save();

        $this->artisan('workspace:prune')->assertSuccessful();

        $this->assertNotNull(Attachment::query()->find($attachment->getKey()));
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_a_dry_run_reports_without_removing(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $draft = app(AttachmentStorage::class)->storeDraft(
            UploadedFile::fake()->image('counted.png'),
            $board,
            $team,
        );

        $draft->forceFill(['created_at' => now()->subHours(48)])->save();

        $this->artisan('workspace:prune --dry-run')->assertSuccessful();

        $this->assertNotNull(Attachment::query()->find($draft->getKey()));
        Storage::disk('local')->assertExists($draft->path);
    }

    /**
     * A profile picture nothing points at is collected too.
     *
     * App\Actions\Users\UpdateAvatar writes the new object before it updates
     * the row and deletes the old one afterwards, so that an interruption
     * always leaves somebody with a picture. The cost of that ordering is a
     * file stranded on either side of a failure, and this is what collects it.
     */
    public function test_an_unreferenced_profile_picture_is_removed(): void
    {
        $user = $this->teamMember();

        app(UpdateAvatar::class)
            ->handle($user, FakeFiles::png(60, 60, 'face.png'));

        $kept = $user->refresh()->avatar_path;

        // A file that no row names — what a crash between the write and the
        // column update would leave behind.
        Storage::disk('local')->put('avatars/01STRANDEDSTRANDEDSTRAND.jpg', 'orphaned bytes');

        $this->artisan('workspace:prune')->assertSuccessful();

        Storage::disk('local')->assertMissing('avatars/01STRANDEDSTRANDEDSTRAND.jpg');

        // And the one somebody is actually wearing is untouched.
        Storage::disk('local')->assertExists($kept);
        $this->assertTrue($user->refresh()->hasAvatar());
    }

    public function test_a_dry_run_does_not_remove_an_unreferenced_picture(): void
    {
        Storage::disk('local')->put('avatars/01STRANDEDSTRANDEDSTRAND.jpg', 'orphaned bytes');

        $this->artisan('workspace:prune --dry-run')->assertSuccessful();

        Storage::disk('local')->assertExists('avatars/01STRANDEDSTRANDEDSTRAND.jpg');
    }
}
