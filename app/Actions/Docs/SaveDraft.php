<?php

declare(strict_types=1);

namespace App\Actions\Docs;

use App\Models\DocPage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The documentation editor's autosave buffer.
 *
 * Writes `doc_pages.draft_title` and `draft_md` and nothing a reader can see.
 * A page's own `title` and `body_md` are only ever changed by
 * App\Actions\Docs\UpdatePage, which is reached from the Save button — so a
 * page keeps saying what its author last decided it should say, however long
 * they leave the editor open.
 *
 * No activity is recorded. A draft is not an event: it is the same person
 * still working, and the feed already gets one entry per editing session from
 * UpdatePage. See App\Services\ActivityLogger::continuingEdit().
 *
 * Three properties are load-bearing:
 *
 *   the page's timestamps are not touched. A draft write that bumped
 *   `updated_at` would make the "last updated" line on the page report an
 *   edit nobody has committed, and would reorder anything sorted by it. The
 *   write therefore goes through the query builder rather than the model.
 *
 *   an unchanged draft writes nothing. The editor calls this on a timer, so
 *   most calls have nothing to do, and a LONGTEXT column is not something to
 *   rewrite for the sake of it.
 *
 *   a draft equal to what is saved is stored as nothing at all. Otherwise
 *   typing one character and deleting it again would leave a page permanently
 *   claiming to hold unsaved work.
 */
class SaveDraft
{
    /**
     * Record where an editor has got to.
     *
     * @return bool whether anything was written
     */
    public function store(DocPage $page, string $title, ?string $markdown, User $actor): bool
    {
        $title = trim($title);
        $body = (string) $markdown;

        if ($title === (string) $page->title && $body === (string) $page->body_md) {
            return $this->discard($page);
        }

        if ($page->draft_saved_at !== null
            && (string) $page->draft_title === $title
            && (string) $page->draft_md === $body) {
            return false;
        }

        return $this->write($page, [
            'draft_title' => $title,
            'draft_md' => $body,
            'draft_saved_at' => now(),
            'draft_by_id' => $actor->getKey(),
        ]);
    }

    /**
     * Throw the draft away — on an explicit save, or on leaving the editor.
     *
     * @return bool whether anything was written
     */
    public function discard(DocPage $page): bool
    {
        if ($page->draft_saved_at === null && $page->draft_md === null && $page->draft_title === null) {
            return false;
        }

        return $this->write($page, [
            'draft_title' => null,
            'draft_md' => null,
            'draft_saved_at' => null,
            'draft_by_id' => null,
        ]);
    }

    /**
     * Update the row without touching `updated_at`.
     *
     * The query builder, not the Eloquent one. `Model::save()` maintains
     * timestamps, and so does `Eloquent\Builder::update()` — it quietly adds
     * `updated_at` to whatever it is given, which is exactly the write this
     * method exists to avoid. `$page->timestamps = false` would work and then
     * leave a model behind whose timestamps are still off for whatever runs
     * next.
     *
     * @param  array<string, mixed>  $values
     */
    private function write(DocPage $page, array $values): bool
    {
        DB::table($page->getTable())
            ->where($page->getKeyName(), $page->getKey())
            ->update($values);

        foreach ($values as $column => $value) {
            $page->setAttribute($column, $value);
        }

        // The columns were just written, so marking them clean keeps a later
        // Model::save() on this instance from writing them a second time.
        $page->syncOriginalAttributes(array_keys($values));

        return true;
    }
}
