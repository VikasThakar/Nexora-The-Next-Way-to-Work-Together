<?php

declare(strict_types=1);

namespace App\Actions\Docs;

use App\Models\DocPage;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\DocPageTree;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reparent and reorder a page.
 *
 * Four things are refused, and the first is the one that matters:
 *
 *   1. Moving a customer-visible page (or a subtree containing one) under an
 *      internal parent. That would leave a published page whose path a customer
 *      must not see — the invariant App\Services\DocPageFinder exists to
 *      protect. The move is refused rather than silently unpublishing the page,
 *      because quietly changing what a customer can see is worse than an error
 *      message.
 *   2. A parent on another board.
 *   3. A parent that is the page itself or one of its own descendants, which
 *      would make a loop.
 *   4. A move that pushes any part of the subtree past DocPage::MAX_DEPTH.
 *
 * Positions are rewritten as a dense 0..n-1 sequence for the levels involved,
 * the same way ticket columns are.
 */
class MovePage
{
    public function __construct(
        private readonly DocPageTree $tree,
        private readonly ActivityLogger $activity,
    ) {}

    public function handle(DocPage $page, ?DocPage $parent, int $position, ?User $actor = null): DocPage
    {
        $page->loadMissing('board');

        if ($parent !== null && $parent->board_id !== $page->board_id) {
            throw new RuntimeException('A page cannot be moved to another board.');
        }

        if ($this->tree->wouldCycle($page, $parent)) {
            throw new RuntimeException('A page cannot be moved inside itself.');
        }

        if ($parent !== null && $this->tree->depthOf($parent) + 1 + $this->tree->heightOf($page) > DocPage::MAX_DEPTH) {
            throw new RuntimeException('That would nest pages deeper than the documentation tree allows.');
        }

        if ($parent !== null && ! $parent->customer_visible && $this->publishesAnything($page)) {
            throw new RuntimeException(
                'This page is published to customers, so it cannot be filed under an internal page. '
                .'Publish "'.$parent->title.'" first, or make this page internal.'
            );
        }

        return DB::transaction(function () use ($page, $parent, $position, $actor): DocPage {
            $originalParentId = $page->parent_id === null ? null : (int) $page->parent_id;
            $newParentId = $parent?->getKey();

            $page->parent_id = $newParentId;
            $page->save();

            $this->tree->resequence($page->board_id, $newParentId, $page->getKey(), $position);

            if ($originalParentId !== $newParentId) {
                $this->tree->resequence($page->board_id, $originalParentId);

                // Only a change of parent reaches the feed. Dragging a page up
                // or down among its siblings happens constantly while somebody
                // tidies a tree, and a feed that reported each one would bury
                // everything else — which is the failure mode this feature is
                // meant to avoid.
                $this->activity->pageMoved($page, $actor);
            }

            return $page->refresh();
        });
    }

    /**
     * Is this page, or anything beneath it, visible to customers?
     */
    private function publishesAnything(DocPage $page): bool
    {
        if ($page->customer_visible) {
            return true;
        }

        return $this->tree->descendants($page)->contains(
            fn (DocPage $child): bool => (bool) $child->customer_visible
        );
    }
}
