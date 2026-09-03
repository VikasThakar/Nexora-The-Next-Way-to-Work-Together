<?php

declare(strict_types=1);

namespace App\Actions\Boards;

use App\Models\Attachment;
use App\Models\Board;
use App\Models\DocPage;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Permanently delete a board and everything on it.
 *
 * The deletion order matters and is the whole reason this is an action rather
 * than a $board->delete() call:
 *
 *   tickets.board_column_id is restrictOnDelete, so that removing a column can
 *   never destroy the tickets inside it. That protection also means the
 *   database refuses to cascade a board deletion into its columns while tickets
 *   still reference them. Tickets are therefore deleted explicitly first, and
 *   the remaining cascades then run cleanly.
 *
 * Stored objects are removed after the transaction commits. A rolled-back
 * transaction must not leave files deleted; the reverse failure only leaves
 * unreferenced bytes in the bucket, which is the cheaper mistake.
 */
class DeleteBoard
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function handle(Board $board): void
    {
        /*
         * Recorded before anything is removed, for two reasons.
         *
         * The board's name is still readable now, and the activity row has to
         * carry it — after this method there is nothing left to look it up
         * from.
         *
         * And `activity_log.board_id` cascades with the board, so the row is
         * filed with no board at all: a row pointing at this board would be
         * deleted by the same statement that deletes the board, which would
         * make a board deletion the one change the feed could never show. A
         * board-less row is workspace-level and administrator-only, which is
         * the right audience for it in any case. See
         * App\Services\ActivityLogger::boardDeleted().
         *
         * Every other activity on this board goes with the board, which is
         * intended: none of it names anything a reader could still open.
         */
        $this->activity->boardDeleted($board);

        /** @var array<int, array{disk: string, path: string}> $files */
        $files = Attachment::query()
            ->where('board_id', $board->getKey())
            ->get(['disk', 'path'])
            ->map(fn (Attachment $attachment): array => [
                'disk' => $attachment->disk,
                'path' => $attachment->path,
            ])
            ->all();

        DB::transaction(function () use ($board): void {
            // Explicit, in dependency order. Subtasks, labels, links, events,
            // comments and attachments all cascade from the rows removed here.
            $this->deleteDocumentation($board);

            $board->tickets()->delete();
            $board->columns()->delete();

            $board->delete();
        });

        foreach ($files as $file) {
            Storage::disk($file['disk'])->delete($file['path']);
        }
    }

    /**
     * Remove the documentation tree from the leaves inwards.
     *
     * `doc_pages.parent_id` is RESTRICT, so the board row cannot simply cascade
     * into the pages: the database would refuse to remove a page that still has
     * children. Deleting whatever currently has no children, repeatedly, peels
     * the tree off one level at a time. The loop is bounded by the maximum
     * nesting depth, so a cycle from a bad write cannot hang a deletion.
     */
    private function deleteDocumentation(Board $board): void
    {
        for ($level = 0; $level <= DocPage::MAX_DEPTH + 1; $level++) {
            $parentIds = DocPage::query()
                ->where('board_id', $board->getKey())
                ->whereNotNull('parent_id')
                ->distinct()
                ->pluck('parent_id')
                ->all();

            $leafIds = DocPage::query()
                ->where('board_id', $board->getKey())
                ->when($parentIds !== [], fn ($query) => $query->whereNotIn('id', $parentIds))
                ->pluck('id')
                ->all();

            if ($leafIds === []) {
                return;
            }

            DocPage::query()->whereIn('id', $leafIds)->delete();
        }
    }
}
