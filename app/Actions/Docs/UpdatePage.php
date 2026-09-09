<?php

declare(strict_types=1);

namespace App\Actions\Docs;

use App\Models\DocPage;
use App\Models\User;
use App\Services\ActivityLogger;

/**
 * Edit a page's title, icon and body.
 *
 * Not its slug, its parent or its visibility. Those three are the things that
 * can break a link, restructure the tree or expose material to a customer, so
 * each has its own action and its own ability:
 * App\Actions\Docs\MovePage and App\Actions\Docs\SetPageVisibility.
 *
 * A rename therefore never changes the URL. Somebody who pasted a link to a
 * page in a ticket last month still lands on it.
 *
 * Called by a person through App\Livewire\Docs\Show — including from its
 * autosave, which is why the dirty check below is load-bearing rather than
 * merely tidy: it is what keeps an editor left open on screen from writing a
 * row and a feed entry every few seconds.
 */
class UpdatePage
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly SaveDraft $drafts,
    ) {}

    /**
     * @param  array{title?: string, icon?: ?string, body_md?: ?string}  $attributes
     */
    public function handle(DocPage $page, array $attributes, User $actor): DocPage
    {
        $previousTitle = (string) $page->title;

        if (array_key_exists('title', $attributes)) {
            $title = trim((string) $attributes['title']);

            if ($title !== '') {
                $page->title = $title;
            }
        }

        /*
         * An icon can be removed as well as set, so blank means null rather
         * than "leave it alone" — the caller says nothing at all by omitting
         * the key. Not recorded in the feed: picking an emoji is not an edit
         * to the documentation, and a line about it would push the entries
         * that matter off the screen.
         */
        if (array_key_exists('icon', $attributes)) {
            $icon = trim((string) $attributes['icon']);

            $page->icon = $icon === '' ? null : $icon;
        }

        if (array_key_exists('body_md', $attributes)) {
            $body = (string) $attributes['body_md'];

            $page->body_md = trim($body) === '' ? null : $body;
        }

        // Opening a page and saving it unchanged writes nothing and puts no
        // edit in the feed. The autosave relies on this: it fires on a timer,
        // so most of the calls that reach here have nothing to do.
        if (! $page->isDirty()) {
            return $page;
        }

        $renamed = $page->isDirty('title');
        $rewritten = $page->isDirty('body_md');

        $page->updated_by_id = $actor->getKey();
        $page->save();

        /*
         * A committed change supersedes whatever the editor's autosave was
         * holding. Done here rather than at the Save button so it is also true
         * of the workspace AI's page edits (App\Actions\AI\ExecuteChatAction):
         * once the document has moved on, a draft written against the old text
         * is not recovery material, it is a way to undo somebody else's work
         * by accident.
         */
        $this->drafts->discard($page);

        /*
         * A rename and a rewrite are two facts, and one save can carry both.
         * They are recorded as two entries rather than one, because "when did
         * this stop being called X" and "when was this rewritten" are
         * different questions asked of the same feed, and a single line can
         * only answer one of them.
         *
         * Both are coalesced per person per page inside ActivityLogger, so a
         * long autosaved editing session is one entry of each and not fifty.
         */
        if ($renamed) {
            $this->activity->pageRenamed($page, $previousTitle, $actor);
        }

        if ($rewritten) {
            $this->activity->pageUpdated($page, $actor);
        }

        return $page;
    }
}
