<?php

declare(strict_types=1);

namespace App\Actions\Docs;

use App\Models\DocPage;
use App\Models\User;
use App\Support\DocPageTree;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Publish a documentation page to customers, or take it back.
 *
 * The most sensitive write in the documentation system, and the only one that
 * moves material across the customer boundary. Two asymmetric rules:
 *
 *   Publishing is refused when any ancestor is internal. A published page under
 *   an internal parent is exactly the state that would leak a path, a
 *   breadcrumb and a set of sibling titles. Refusing — rather than silently
 *   publishing the ancestors too — keeps the decision with the person: exposing
 *   a parent page is their call, not a side effect of publishing a child.
 *
 *   Un-publishing cascades down the whole subtree. Anything else would leave
 *   published children hanging off an internal parent, which is the same broken
 *   state arrived at from the other direction. Retracting always fails closed:
 *   more is hidden, never less.
 */
class SetPageVisibility
{
    public function __construct(private readonly DocPageTree $tree) {}

    /**
     * @return int the number of pages whose visibility changed
     */
    public function handle(DocPage $page, bool $customerVisible, User $actor): int
    {
        if ($customerVisible === (bool) $page->customer_visible) {
            return 0;
        }

        return $customerVisible
            ? $this->publish($page, $actor)
            : $this->retract($page, $actor);
    }

    private function publish(DocPage $page, User $actor): int
    {
        $internalAncestor = $this->tree->ancestors($page)
            ->first(fn (DocPage $ancestor): bool => ! $ancestor->customer_visible);

        if ($internalAncestor instanceof DocPage) {
            throw new RuntimeException(
                'This page sits under "'.$internalAncestor->title.'", which is internal. '
                .'Publish that page first, or move this one out from under it.'
            );
        }

        $page->customer_visible = true;
        $page->updated_by_id = $actor->getKey();
        $page->save();

        return 1;
    }

    /**
     * Hide a page and everything beneath it.
     */
    private function retract(DocPage $page, User $actor): int
    {
        return DB::transaction(function () use ($page, $actor): int {
            $page->customer_visible = false;
            $page->updated_by_id = $actor->getKey();
            $page->save();

            $descendantIds = $this->tree->descendants($page)
                ->where('customer_visible', true)
                ->pluck('id')
                ->all();

            if ($descendantIds === []) {
                return 1;
            }

            DocPage::query()
                ->whereIn('id', $descendantIds)
                ->update([
                    'customer_visible' => false,
                    'updated_by_id' => $actor->getKey(),
                    'updated_at' => now(),
                ]);

            return 1 + count($descendantIds);
        });
    }
}
