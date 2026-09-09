<?php

declare(strict_types=1);

namespace App\Actions\Docs;

use App\Models\Board;
use App\Models\DocPage;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\DocPageTree;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Create a documentation page.
 *
 * Two rules are fixed here rather than left to the form:
 *
 *   - a new page is always internal. Publishing is a separate, deliberate act
 *     (App\Actions\Docs\SetPageVisibility) with its own ability, so nobody
 *     exposes a page to a customer by leaving a checkbox alone.
 *   - a parent is only accepted when it is on the same board and the result
 *     stays within DocPage::MAX_DEPTH. A tampered parent id therefore lands the
 *     page at the root of the board the request was made against, never on
 *     somebody else's board.
 */
class CreatePage
{
    public function __construct(
        private readonly DocPageTree $tree,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @param  array{title: string, icon?: ?string, body_md?: ?string, parent_id?: int|string|null}  $attributes
     */
    public function handle(Board $board, array $attributes, User $author): DocPage
    {
        $title = trim($attributes['title']);

        if ($title === '') {
            throw new RuntimeException('A documentation page needs a title.');
        }

        $parent = $this->resolveParent($board, $attributes['parent_id'] ?? null);

        return DB::transaction(function () use ($board, $attributes, $author, $title, $parent): DocPage {
            $page = new DocPage([
                'title' => $title,
                'icon' => $this->nullIfBlank($attributes['icon'] ?? null),
                'body_md' => $this->nullIfBlank($attributes['body_md'] ?? null),
            ]);

            $page->board_id = $board->getKey();
            $page->parent_id = $parent?->getKey();
            $page->slug = $this->uniqueSlug($board, $title);
            $page->position = $this->nextPosition($board, $parent);
            $page->created_by_id = $author->getKey();
            $page->updated_by_id = $author->getKey();

            // Internal until somebody publishes it, on purpose.
            $page->customer_visible = false;

            $page->save();

            $this->activity->pageCreated($page, $author);

            return $page;
        });
    }

    /**
     * A parent must be on this board and must leave room below it.
     */
    private function resolveParent(Board $board, int|string|null $parentId): ?DocPage
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }

        $parent = $board->docPages()->whereKey((int) $parentId)->first();

        if (! $parent instanceof DocPage) {
            return null;
        }

        return $this->tree->depthOf($parent) + 1 > DocPage::MAX_DEPTH ? null : $parent;
    }

    /**
     * Slugs are unique per board and never change afterwards, so a link to a
     * page keeps working when the page is renamed.
     */
    private function uniqueSlug(Board $board, string $title): string
    {
        $base = Str::slug($title);
        $base = $base === '' ? 'page' : Str::limit($base, 200, '');

        $slug = $base;
        $suffix = 2;

        while ($board->docPages()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function nextPosition(Board $board, ?DocPage $parent): int
    {
        $query = $board->docPages();

        $parent === null
            ? $query->whereNull('parent_id')
            : $query->where('parent_id', $parent->getKey());

        $max = $query->max('position');

        return $max === null ? 0 : ((int) $max) + 1;
    }

    private function nullIfBlank(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return trim((string) $value) === '' ? null : (string) $value;
    }
}
