<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Attachments\AdoptDraftAttachments;
use App\Models\Attachment;
use App\Models\Board;
use App\Services\AttachmentStorage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/**
 * Files dropped into a form whose record does not exist yet.
 *
 * Shared by the two places a ticket can be created — App\Livewire\Tickets\Create
 * and the inline quick add in App\Livewire\Boards\Show — because the lifecycle
 * is identical in both and is the part that is easy to get subtly wrong:
 *
 *   upload    stored immediately, as a draft (no owner), so the file has its
 *             final authorized URL from the first second. See the
 *             allow_draft_attachments migration for why it is not held in
 *             temporary storage instead.
 *   adopt     once the ticket exists, every draft this form collected is given
 *             to it, through the action that re-checks each one.
 *   discard   cancelling the form takes its drafts down with it, rather than
 *             waiting for the pruner to notice.
 *
 * The ids are kept on the component rather than in a token column, because the
 * component *is* the grouping: one open form, one list. Nothing else needs to
 * find them, and a column would be a second place for the answer to live.
 *
 * None of these ids is trusted on the way back in. They round-trip through the
 * browser like any other Livewire property, so AdoptDraftAttachments re-resolves
 * each one against the uploader and the board before it is allowed to become
 * anything.
 */
trait CollectsDraftAttachments
{
    /**
     * Drafts collected by this form, oldest first.
     *
     * @var array<int, int>
     */
    public array $draftAttachmentIds = [];

    /**
     * The board the drafts are filed under.
     *
     * Already required by EditsRichText for the same reason, so a component
     * using both supplies it once.
     */
    abstract protected function uploadBoard(): Board;

    /**
     * Store uploads as drafts and record them against this form.
     *
     * @param  array<int, UploadedFile>  $files
     * @return Collection<int, Attachment>
     */
    protected function storeDraftUploads(array $files, AttachmentStorage $storage): Collection
    {
        $board = $this->uploadBoard();
        $user = auth()->user();
        $stored = new Collection;

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $attachment = $storage->storeDraft($file, $board, $user);

            $this->draftAttachmentIds[] = (int) $attachment->getKey();
            $stored->push($attachment);
        }

        return $stored;
    }

    /**
     * Hand this form's drafts to the record it just created.
     *
     * The list is cleared whatever happened, including when nothing was adopted.
     * Leaving ids behind would mean a second save on the same open form trying
     * to adopt rows that already have an owner — refused, but refused once per
     * file, for ever.
     *
     * @return Collection<int, Attachment>
     */
    protected function adoptDraftUploads(Model $owner, AdoptDraftAttachments $adopt): Collection
    {
        if ($this->draftAttachmentIds === []) {
            return new Collection;
        }

        $adopted = $adopt->handle(
            $this->draftAttachmentIds,
            $owner,
            $this->uploadBoard(),
            auth()->user(),
        );

        $this->draftAttachmentIds = [];

        return $adopted;
    }

    /**
     * Throw away the drafts this form collected.
     *
     * Scoped the same way adoption is, so a tampered id cannot delete somebody
     * else's file — and still a draft, so a file that has since been adopted by
     * a save in another tab is left alone rather than deleted out from under the
     * ticket that now owns it.
     */
    protected function discardDraftUploads(AttachmentStorage $storage): void
    {
        foreach ($this->draftAttachments() as $draft) {
            $storage->delete($draft);
        }

        $this->draftAttachmentIds = [];
    }

    /**
     * The drafts this form is currently holding, for rendering.
     *
     * @return Collection<int, Attachment>
     */
    protected function draftAttachments(): Collection
    {
        if ($this->draftAttachmentIds === []) {
            return new Collection;
        }

        return Attachment::query()
            ->draft()
            ->whereIn('id', $this->draftAttachmentIds)
            ->where('uploaded_by_id', auth()->id())
            ->where('board_id', $this->uploadBoard()->getKey())
            ->orderBy('id')
            ->get();
    }
}
