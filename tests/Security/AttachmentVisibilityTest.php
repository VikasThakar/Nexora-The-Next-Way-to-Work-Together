<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Actions\Comments\DeleteComment;
use App\Actions\Docs\SetPageVisibility;
use App\Enums\CommentStream;
use App\Models\DocPage;
use App\Services\AttachmentStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * An attachment is exactly as private as the thing it hangs off.
 *
 * There is no visibility flag on an attachment, deliberately: AttachmentPolicy
 * asks the owner's policy instead, so a file cannot end up more readable than
 * its parent through the two drifting apart. These tests check that for each
 * kind of owner, including the ones added in Phase 3.
 */
class AttachmentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_a_customer_cannot_download_a_file_attached_to_an_internal_note(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        // A ticket the customer can read, so only the note's stream protects
        // the file.
        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);
        $note = $this->commentOn($ticket, $team, 'See attached', CommentStream::Internal);

        $attachment = app(AttachmentStorage::class)
            ->store(UploadedFile::fake()->create('margins.pdf', 8, 'application/pdf'), $note, $board, $team);

        $this->actingAs($customer)
            ->get(route('attachments.show', $attachment))
            ->assertNotFound();

        $allowed = $this->actingAs($team)->get(route('attachments.show', $attachment));

        $this->assertTrue(
            $allowed->isSuccessful() || $allowed->isRedirect(),
            'The delivery team must still receive the file, got '.$allowed->status()
        );
    }

    public function test_a_customer_can_download_a_file_on_a_comment_they_can_read(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);
        $reply = $this->commentOn($ticket, $team, 'Here is the report', CommentStream::Customer);

        $attachment = app(AttachmentStorage::class)
            ->store(UploadedFile::fake()->create('report.pdf', 8, 'application/pdf'), $reply, $board, $team);

        $response = $this->actingAs($customer)->get(route('attachments.show', $attachment));

        $this->assertTrue($response->isSuccessful() || $response->isRedirect());
    }

    public function test_a_customer_cannot_download_a_file_on_an_internal_documentation_page(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $page = $this->docPageOn($board, $team, ['title' => 'Architecture']);

        $attachment = app(AttachmentStorage::class)
            ->store(UploadedFile::fake()->image('topology.png'), $page, $board, $team);

        $this->actingAs($customer)
            ->get(route('attachments.show', $attachment))
            ->assertNotFound();
    }

    /**
     * A page can be published and then retracted. The file must follow.
     */
    public function test_retracting_a_page_takes_its_attachments_with_it(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $page = $this->publishedPageOn($board, $team, ['title' => 'Release notes']);

        $attachment = app(AttachmentStorage::class)
            ->store(UploadedFile::fake()->image('chart.png'), $page, $board, $team);

        $before = $this->actingAs($customer)->get(route('attachments.show', $attachment));
        $this->assertTrue($before->isSuccessful() || $before->isRedirect());

        app(SetPageVisibility::class)->handle($page, false, $team);

        $this->actingAs($customer)
            ->get(route('attachments.show', $attachment))
            ->assertNotFound();
    }

    /**
     * A published page under an internal parent is unreachable, and so are its
     * files: the policy asks the finder, which walks the ancestor chain.
     */
    public function test_a_file_on_a_page_with_an_internal_ancestor_is_unreachable(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $parent = $this->docPageOn($board, $team, ['title' => 'Internal root']);
        $child = $this->docPageOn($board, $team, ['title' => 'Child', 'parent_id' => $parent->getKey()]);

        DocPage::query()->whereKey($child->getKey())->update(['customer_visible' => true]);

        $attachment = app(AttachmentStorage::class)
            ->store(UploadedFile::fake()->image('leak.png'), $child->refresh(), $board, $team);

        $this->actingAs($customer)
            ->get(route('attachments.show', $attachment))
            ->assertNotFound();
    }

    public function test_a_non_member_cannot_download_a_documentation_file(): void
    {
        $team = $this->teamMember();
        $outsider = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $page = $this->publishedPageOn($board, $team, ['title' => 'Anything']);

        $attachment = app(AttachmentStorage::class)
            ->store(UploadedFile::fake()->image('x.png'), $page, $board, $team);

        $this->actingAs($outsider)
            ->get(route('attachments.show', $attachment))
            ->assertNotFound();
    }

    /**
     * Deleting a comment makes its files unreachable without any extra
     * bookkeeping: the soft-deleted owner no longer resolves, and an
     * attachment whose owner cannot be resolved is denied.
     */
    public function test_files_on_a_deleted_comment_become_unreachable(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team);
        $note = $this->commentOn($ticket, $team, 'With a file', CommentStream::Internal);

        $attachment = app(AttachmentStorage::class)
            ->store(UploadedFile::fake()->create('notes.txt', 2, 'text/plain'), $note, $board, $team);

        $before = $this->actingAs($team)->get(route('attachments.show', $attachment));
        $this->assertTrue($before->isSuccessful() || $before->isRedirect());

        app(DeleteComment::class)->handle($note, $team);

        $this->actingAs($team)
            ->get(route('attachments.show', $attachment))
            ->assertNotFound();
    }
}
