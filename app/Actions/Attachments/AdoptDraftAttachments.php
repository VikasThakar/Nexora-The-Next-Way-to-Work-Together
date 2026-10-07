<?php

declare(strict_types=1);

namespace App\Actions\Attachments;

use App\Models\Attachment;
use App\Models\Board;
use App\Models\User;
use App\Services\AttachmentStorage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Give the files dropped into a creation form the record they were meant for.
 *
 * This is the only path by which an attachment ever gains an owner, and it runs
 * immediately after the owner is created — in App\Livewire\Tickets\Create for
 * the full form, and in App\Livewire\Boards\Show for the board's inline quick
 * add. Both call it with a list of ids that came from a browser, so none of
 * them is trusted.
 *
 * Four conditions, and a row that fails any of them is skipped rather than
 * raising. A draft that has gone — pruned overnight, or removed in another tab
 * — must not stop a ticket being filed; the person pressed Save on a form, and
 * the ticket is the point of it.
 *
 *   it is still a draft       so a replayed request cannot re-point a file that
 *                             already belongs to something else. Re-checked in
 *                             SQL here and again in AttachmentStorage::adopt().
 *   this user uploaded it     the same rule AttachmentPolicy applies to reading
 *                             one. Without it, knowing an id would be enough to
 *                             pull a colleague's half-finished upload onto your
 *                             own ticket, where you could then read it.
 *   it is on this board       a draft is filed under the board it was uploaded
 *                             on, and adoption must not move a file across
 *                             boards. The storage path says which board it is
 *                             laid out under, and that path is not rewritten.
 *   the user may manage       the owner's own rule, asked of the owner rather
 *   attachments on the owner  than restated. A customer filing their own ticket
 *                             passes it; anyone who could not attach a file to
 *                             the record by hand cannot attach one this way.
 *
 * The caller is expected to have authorized the *creation* already. This asks a
 * different question — may this person put a file on the thing they just made —
 * because the two are genuinely different and the second is the one a future
 * owner type might answer differently.
 */
class AdoptDraftAttachments
{
    /**
     * @param  array<int, int|string>  $attachmentIds
     * @return Collection<int, Attachment> those actually adopted, in upload order
     */
    public function handle(array $attachmentIds, Model $owner, Board $board, User $user): Collection
    {
        $ids = array_values(array_filter(array_map('intval', $attachmentIds)));

        if ($ids === [] || $user->cannot('manageAttachments', $owner)) {
            return new Collection;
        }

        return DB::transaction(function () use ($ids, $owner, $board, $user): Collection {
            /*
             * Re-resolved rather than taken from the caller: the ids arrived
             * from a browser and the conditions below are the whole defence.
             * lockForUpdate keeps two submissions of the same form from both
             * believing they adopted the file.
             */
            $drafts = Attachment::query()
                ->draft()
                ->whereIn('id', $ids)
                ->where('uploaded_by_id', $user->getKey())
                ->where('board_id', $board->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $storage = app(AttachmentStorage::class);

            foreach ($drafts as $draft) {
                $storage->adopt($draft, $owner);
            }

            return $drafts;
        });
    }
}
