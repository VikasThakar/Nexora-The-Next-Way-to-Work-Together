<?php

declare(strict_types=1);

namespace App\Actions\Docs;

use App\Models\Attachment;
use App\Models\DocPage;
use App\Services\ActivityLogger;
use App\Support\DocPageTree;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Delete a page and everything filed under it.
 *
 * `doc_pages.parent_id` is RESTRICT, deliberately: a page must never disappear
 * as a side effect of something else being deleted. That makes the deletion
 * order part of the operation rather than something the database improvises —
 * children go first, deepest level upwards, and the page itself last.
 *
 * (Self-referencing ON DELETE CASCADE was rejected when the table was designed:
 * InnoDB does not recurse cascades through a self-referencing key, so it would
 * appear to work on a two-level tree and fail on a three-level one.)
 *
 * Stored objects are removed only after the transaction commits, matching
 * App\Actions\Boards\DeleteBoard: a rolled-back transaction must not leave
 * files deleted, whereas the reverse only leaves unreferenced bytes.
 */
class DeletePage
{
    public function __construct(
        private readonly DocPageTree $tree,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @return int the number of pages removed, including the page itself
     */
    public function handle(DocPage $page): int
    {
        $subtree = $this->tree->descendantsDeepestFirst($page);

        $ids = $subtree->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $ids[] = (int) $page->getKey();

        /** @var array<int, array{disk: string, path: string}> $files */
        $files = Attachment::query()
            ->where('attachable_type', $page->getMorphClass())
            ->whereIn('attachable_id', $ids)
            ->get(['disk', 'path'])
            ->map(fn (Attachment $attachment): array => [
                'disk' => $attachment->disk,
                'path' => $attachment->path,
            ])
            ->all();

        // Recorded before the transaction, while the page and its title are
        // still there to name. The count says how much went with it: deleting a
        // parent takes its children, and "deleted one page" would understate it.
        $this->activity->pageDeleted($page, count($ids));

        DB::transaction(function () use ($subtree, $page, $ids): void {
            Attachment::query()
                ->where('attachable_type', $page->getMorphClass())
                ->whereIn('attachable_id', $ids)
                ->delete();

            // Deepest first: a child still pointing at its parent would make
            // the parent's delete fail against the RESTRICT constraint.
            foreach ($subtree as $descendant) {
                $descendant->delete();
            }

            $page->delete();
        });

        foreach ($files as $file) {
            Storage::disk($file['disk'])->delete($file['path']);
        }

        return count($ids);
    }
}
