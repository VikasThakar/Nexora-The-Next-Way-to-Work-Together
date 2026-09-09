<?php

declare(strict_types=1);

namespace App\Livewire\Docs;

use App\Actions\Docs\CreatePage;
use App\Actions\Docs\DeletePage;
use App\Actions\Docs\MovePage;
use App\Actions\Docs\SaveDraft;
use App\Actions\Docs\SetPageVisibility;
use App\Actions\Docs\UpdatePage;
use App\Livewire\Concerns\EditsRichText;
use App\Livewire\Concerns\ListensForBoardUpdates;
use App\Models\Board;
use App\Models\DocPage;
use App\Services\BoardAccess;
use App\Services\BoardBroadcaster;
use App\Services\ContentRenderer;
use App\Services\DocPageFinder;
use App\Support\RichText\RichText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * The documentation section of a board: page tree on the left, document on the
 * right.
 *
 * One component serves both /docs and /docs/{slug}, because the tree is on
 * screen either way and reordering it has to work from both. A separate index
 * component would mean two copies of the drag-and-drop handler, which is
 * exactly the kind of duplication that lets one copy forget a check.
 *
 * Everything read here goes through DocPageFinder, which applies board
 * membership, the customer flag AND the ancestor rule — a published page under
 * an internal parent is not reachable, is not in the tree, and is not in the
 * breadcrumb. Livewire rehydrates $page by primary key on every request, which
 * bypasses all of that, so the page is re-authorized on every render.
 *
 *
 * The editor is not a mode
 * ------------------------
 * Anybody who may edit a page gets the editor, immediately, on the page they
 * opened. There is no Edit button and no read-only step in front of it: a
 * documentation tool whose default state is "looking at" rather than "working
 * on" makes every small correction a four-click job, which is how
 * documentation goes stale. `$editing` therefore means "the writing surface is
 * live", and it is decided by the policy in mount() rather than by a click.
 *
 * A reader who may not edit still gets the rendered document, and never so
 * much as the markup of a control they could not use.
 *
 *
 * Saving
 * ------
 * Where an autosave lands depends on who can read the page, and that is the
 * one genuinely load-bearing decision in this class:
 *
 *   internal page    autosave writes `body_md` through
 *                    App\Actions\Docs\UpdatePage. Nobody outside the delivery
 *                    team can read it, so there is no audience to show
 *                    half-finished prose to, and the text is simply kept the
 *                    way a document editor keeps text.
 *
 *   published page   autosave writes `draft_md` through
 *                    App\Actions\Docs\SaveDraft, and stays there until
 *                    somebody presses Publish. A page a customer is reading
 *                    changes when its author says so, not between keystrokes.
 *
 * save() is that publish step. It moves the draft into `body_md`, records the
 * edit, tells other clients and discards the draft — and on an internal page,
 * where the text is already committed, it is a harmless no-op.
 *
 * Two properties of the write path make the internal case safe on a timer, and
 * neither is incidental: UpdatePage returns early unless the row is actually
 * dirty, and ActivityLogger coalesces an editing session into one feed entry
 * per person per page. An afternoon's typing is therefore a handful of writes
 * and one line in the feed.
 *
 * Autosave never broadcasts, on either branch. A colleague reading this board
 * should not have their screen pulled out from under them every few seconds
 * because somebody two desks away is typing.
 */
#[Layout('layouts.app')]
class Show extends Component
{
    use EditsRichText;
    use ListensForBoardUpdates;

    public Board $board;

    public ?DocPage $page = null;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * Is the writing surface live?
     *
     * Not a mode the reader toggles — see the class comment. Set in mount()
     * from the page's own policy, and false for somebody who may only read.
     */
    public bool $editing = false;

    public bool $previewing = false;

    public string $title = '';

    public string $icon = '';

    public string $bodyMd = '';

    /**
     * Set when the editor opened on a recovered draft rather than on the saved
     * document, so the banner explaining that can be shown.
     */
    public bool $recoveredDraft = false;

    /**
     * Who left the recovered draft and when, as it read at the moment it was
     * picked up.
     *
     * Frozen here rather than read from the page in the template, because the
     * autosave overwrites both the timestamp and the author the moment typing
     * resumes — so a banner reading them live would say "restored from your
     * changes four seconds ago", which explains nothing.
     */
    public string $recoveredFrom = '';

    // Creating a new page
    public bool $creating = false;

    public string $newTitle = '';

    public string $newIcon = '';

    public ?int $newParentId = null;

    public bool $confirmingDelete = false;

    public function mount(Board $board, DocPageFinder $finder, ?string $slug = null): void
    {
        $this->authorize('viewAny', [DocPage::class, $board]);

        $this->board = $board;

        if ($slug === null || $slug === '') {
            return;
        }

        $this->page = $finder->findOrFail($board, $slug, auth()->user());
        $this->syncFromPage();

        /*
         * The editor is live from the first paint for anybody who may write.
         * Asked here rather than in render() because the answer decides what
         * $title and $bodyMd are seeded with, and render() is too late for
         * that.
         */
        $this->editing = auth()->user()->can('update', $this->page);

        if ($this->editing) {
            $this->adoptDraft();
        }
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return $this->boardUpdateListeners(isset($this->board) ? (int) $this->board->getKey() : null);
    }

    // -----------------------------------------------------------------
    // Rich text wiring
    // -----------------------------------------------------------------

    /**
     * A page's prose lives in $bodyMd, not the trait's ticket default.
     */
    protected function markdownProperty(): string
    {
        return 'bodyMd';
    }

    /**
     * Unlike a ticket being created, a page being edited already exists — so a
     * screenshot pasted into the editor has a record to hang off, and is
     * stored immediately under the page's own policy.
     */
    protected function uploadOwner(): ?Model
    {
        return $this->page;
    }

    protected function uploadBoard(): Board
    {
        return $this->board;
    }

    // -----------------------------------------------------------------
    // Editing
    // -----------------------------------------------------------------

    private function syncFromPage(): void
    {
        $this->title = (string) $this->page?->title;
        $this->icon = (string) $this->page?->icon;
        $this->bodyMd = (string) $this->page?->body_md;
    }

    /**
     * Open on unpublished work rather than on the published text.
     *
     * Only published pages accumulate drafts now — an internal page commits as
     * it is typed — so in practice this is the editor picking up changes to a
     * customer-facing page that nobody has pressed Publish on yet. Leaving them
     * behind would be the one case where opening the editor loses work.
     *
     * Anybody who may edit the page may continue its draft: they are all on the
     * same delivery team and there is no locking here. The banner names whose
     * work it is and when, so nobody silently adopts somebody else's
     * half-finished paragraph believing it to be their own.
     */
    private function adoptDraft(): void
    {
        if (! $this->page instanceof DocPage || ! $this->page->hasDraft()) {
            return;
        }

        // Strict mode forbids a lazy load, and render() may not have run yet.
        $this->page->loadMissing('draftBy');

        $this->title = (string) $this->page->draft_title;
        $this->bodyMd = (string) $this->page->draft_md;
        $this->recoveredDraft = true;
        $this->recoveredFrom = trim(implode(', ', array_filter([
            $this->page->draftBy?->name,
            $this->page->draft_saved_at?->format('j M Y \a\t H:i'),
        ])));
    }

    /**
     * Bring the editor back to the draft, from the "Resume editing" button on
     * the unpublished-changes notice.
     *
     * The surface itself is already live; what this does is re-seed it. Kept as
     * its own action rather than folded into mount() because the notice offers
     * the opposite choice too, and discarding has to be reachable from the same
     * place.
     */
    public function startEditing(): void
    {
        $this->requirePage();
        $this->authorize('update', $this->page);

        $this->editing = true;
        $this->previewing = false;
        $this->descriptionHtml = '';
        $this->syncFromPage();
        $this->adoptDraft();
    }

    /**
     * Throw away unpublished changes and put the published text back in the
     * editor.
     *
     * The editor stays live — there is nowhere to "leave" it to any more. What
     * ends is the draft: it goes with the discard, or the next load would
     * restore the work that was just abandoned. The button asks first whenever
     * there is anything to lose.
     */
    public function cancelEditing(SaveDraft $drafts): void
    {
        $this->requirePage();
        $this->authorize('update', $this->page);

        $drafts->discard($this->page);

        $this->previewing = false;
        $this->recoveredDraft = false;
        $this->recoveredFrom = '';
        $this->descriptionHtml = '';
        $this->resetValidation();
        $this->syncFromPage();
    }

    /**
     * Throw away a draft without opening the editor, from the notice on the
     * read view.
     */
    public function discardDraft(SaveDraft $drafts): void
    {
        $this->requirePage();
        $this->authorize('update', $this->page);

        $drafts->discard($this->page);
        $this->syncFromPage();

        session()->flash('status', 'Unsaved changes discarded.');
    }

    /**
     * Preview is offered in Markdown mode only: the rich surface already shows
     * the document as it will read, so previewing it would be a copy of what
     * is already on screen.
     */
    public function togglePreview(): void
    {
        $this->previewing = ! $this->previewing;
    }

    /**
     * Commit the editor's text to the page.
     */
    public function save(
        UpdatePage $updatePage,
        SaveDraft $drafts,
        RichText $rich,
        BoardBroadcaster $broadcaster,
    ): void {
        $this->requirePage();
        $this->authorize('update', $this->page);

        // Converted before validation, so `max` applies to the Markdown that
        // will actually be stored rather than to the HTML the browser sent.
        $this->bodyMd = $this->markdownFromEditor($rich, $this->page->body_md);

        $validated = $this->validate($this->saveRules(), attributes: ['bodyMd' => 'content']);

        $updatePage->handle($this->page, [
            'title' => $validated['title'],
            'body_md' => $validated['bodyMd'],
        ], auth()->user());

        // Saved, so there is nothing left to recover.
        $drafts->discard($this->page);

        $this->page->refresh();

        // The surface stays live. Publishing is something done to the document,
        // not a way out of writing it.
        $this->previewing = false;
        $this->recoveredDraft = false;
        $this->recoveredFrom = '';
        $this->descriptionHtml = '';
        $this->syncFromPage();

        $broadcaster->documentationChanged((int) $this->board->getKey(), (bool) $this->page->customer_visible);

        session()->flash('status', $this->page->customer_visible
            ? 'Changes published. Customers on this board now see them.'
            : 'Page saved.');
    }

    /**
     * The editor's timer, and the title field losing focus.
     *
     * Returns a status the browser turns into the indicator in the editor
     * toolbar. Silence would be worse than any of these: an editor that says
     * nothing about whether the last few minutes are safe is the failure this
     * method exists to prevent.
     *
     * Which write it performs is decided by the page's audience, not by a
     * setting — see the class comment for why.
     *
     * @return string one of saved, unchanged, invalid, idle
     */
    public function autosave(SaveDraft $drafts, RichText $rich, UpdatePage $updatePage): string
    {
        if (! $this->editing || ! $this->page instanceof DocPage) {
            return 'idle';
        }

        $this->authorize('update', $this->page);

        $markdown = $this->markdownFromEditor($rich, $this->page->body_md);

        /*
         * Validated by hand rather than with $this->validate(), which throws.
         * A throw here would abandon the request and leave the browser unable
         * to tell "your title is too long" from "the network dropped" — and
         * the whole point of the indicator is that those two look different.
         */
        $validator = Validator::make(
            ['title' => $this->title, 'bodyMd' => $markdown],
            $this->saveRules(),
            attributes: ['bodyMd' => 'content'],
        );

        if ($validator->fails()) {
            $this->setErrorBag($validator->errors());

            return 'invalid';
        }

        $this->resetValidation();
        $this->bodyMd = $markdown;

        /*
         * A published page's autosave stops at the draft. Everything below
         * this line would be visible to a customer the moment it was written,
         * which is the one thing the draft column exists to prevent.
         */
        if ($this->page->customer_visible) {
            return $drafts->store($this->page, $this->title, $markdown, auth()->user())
                ? 'saved'
                : 'unchanged';
        }

        /*
         * An internal page is committed as it is typed.
         *
         * Safe on a timer because of two things UpdatePage already does: it
         * returns without writing unless the row is dirty, and the feed entry
         * it records is coalesced per person per page. No broadcast, though —
         * see the class comment.
         */
        $page = $updatePage->handle($this->page, [
            'title' => $this->title,
            'body_md' => $markdown,
        ], auth()->user());

        $this->recoveredDraft = false;
        $this->recoveredFrom = '';

        /*
         * `unchanged` rather than `saved` when the row was already up to date,
         * so the indicator does not flash "Saved" at somebody who has typed
         * nothing since the last pass.
         *
         * Read from the model rather than by comparing `updated_at`: that
         * column has one-second resolution, so two passes inside the same
         * second would be indistinguishable — which is precisely the interval
         * an autosave runs at.
         */
        return $page->wasChanged() ? 'saved' : 'unchanged';
    }

    /**
     * The title field syncs on blur, and a rename is worth keeping too.
     *
     * The body is not re-read from the browser here: markdownFromEditor()
     * falls back to $bodyMd when the editor has pushed nothing since the last
     * save, which is exactly the state a title-only edit leaves behind — so a
     * rename cannot overwrite a body draft with the stored text.
     */
    public function updatedTitle(SaveDraft $drafts, RichText $rich, UpdatePage $updatePage): void
    {
        $this->autosave($drafts, $rich, $updatePage);
    }

    /**
     * The icon picker, which commits on choice rather than on a timer.
     *
     * Straight to the page on both branches, published or not. An emoji beside
     * a title is not prose a customer could catch mid-sentence, and holding it
     * in the draft would mean an icon that silently reverted every time
     * somebody discarded unrelated changes.
     */
    public function setIcon(UpdatePage $updatePage, string $icon = ''): void
    {
        $this->requirePage();
        $this->authorize('update', $this->page);

        $icon = trim($icon);

        // No letters, digits or whitespace: the picker offers emoji, and this
        // is what stops the field being used as a second, unvalidated title by
        // anything posting straight to the component.
        if ($icon !== '' && (mb_strlen($icon) > 8 || preg_match('/[\p{L}\p{N}\s]/u', $icon) === 1)) {
            return;
        }

        $updatePage->handle($this->page, ['icon' => $icon], auth()->user());

        $this->icon = (string) $this->page->icon;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function saveRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'bodyMd' => ['nullable', 'string', 'max:200000'],
        ];
    }

    // -----------------------------------------------------------------
    // Creating
    // -----------------------------------------------------------------

    public function startCreating(?int $parentId = null): void
    {
        $this->authorize('create', [DocPage::class, $this->board]);

        $this->creating = true;
        $this->newTitle = '';
        $this->newIcon = '';
        $this->newParentId = $parentId;
        $this->resetValidation();
    }

    public function cancelCreating(): void
    {
        $this->creating = false;
        $this->newTitle = '';
        $this->newIcon = '';
        $this->newParentId = null;
        $this->resetValidation();
    }

    public function createPage(CreatePage $createPage): void
    {
        $this->authorize('create', [DocPage::class, $this->board]);

        $this->validate(['newTitle' => ['required', 'string', 'max:200']], attributes: ['newTitle' => 'title']);

        $icon = trim($this->newIcon);

        // Same guard as setIcon(): the picker offers emoji, and a tampered
        // payload does not get to smuggle text in beside the title.
        if ($icon !== '' && (mb_strlen($icon) > 8 || preg_match('/[\p{L}\p{N}\s]/u', $icon) === 1)) {
            $icon = '';
        }

        $page = $createPage->handle($this->board, [
            'title' => $this->newTitle,
            'icon' => $icon,
            'parent_id' => $this->newParentId,
        ], auth()->user());

        $this->cancelCreating();

        session()->flash('status', 'Page created. It is internal until you publish it.');

        $this->redirect(
            route('docs.show', ['board' => $this->board, 'slug' => $page->slug]),
            navigate: true
        );
    }

    // -----------------------------------------------------------------
    // Tree
    // -----------------------------------------------------------------

    /**
     * Persist a drag in the sidebar tree.
     *
     * Called by wire:sort with the dragged page id and its new index; the
     * destination parent is baked into each list's handler, so the server is
     * always told where the page landed.
     *
     * None of the three arguments is trusted: both pages are re-fetched through
     * the visibility scope, the policy decides whether this user may reorganise
     * documentation, and MovePage refuses cycles, over-deep nesting and any
     * move that would file a published page under an internal one.
     */
    public function movePage(int $pageId, int $position, ?int $parentId, DocPageFinder $finder, MovePage $mover): void
    {
        $page = $finder->query($this->board, auth()->user())->whereKey($pageId)->first();

        abort_unless($page instanceof DocPage, 404);

        $this->authorize('move', $page);

        $parent = null;

        if ($parentId !== null) {
            $parent = $finder->query($this->board, auth()->user())->whereKey($parentId)->first();

            abort_unless($parent instanceof DocPage, 404);
        }

        try {
            $mover->handle($page, $parent, max(0, $position), auth()->user());
        } catch (RuntimeException $e) {
            // The tree in the browser has already moved the node; re-rendering
            // puts it back where the server says it belongs.
            $this->addError('tree', $e->getMessage());
        }

        if ($this->page !== null) {
            $this->page->refresh();
        }
    }

    /**
     * Delete a page from its row in the sidebar, which may not be the page
     * currently open.
     *
     * Resolved the same way movePage() resolves its arguments, and for the same
     * reason: an id arriving from the browser is a request, not a fact. It is
     * re-fetched through the visibility scope so a page this viewer cannot see
     * cannot be named, and then put to the policy.
     */
    public function deletePage(int $pageId, DocPageFinder $finder, DeletePage $deletePage, BoardBroadcaster $broadcaster): void
    {
        $page = $finder->query($this->board, auth()->user())->whereKey($pageId)->first();

        abort_unless($page instanceof DocPage, 404);

        $this->authorize('delete', $page);

        $wasPublished = (bool) $page->customer_visible;
        $openPageWasRemoved = $this->page !== null
            && $finder->query($this->board, auth()->user())
                ->whereKey($this->page->getKey())
                ->exists()
            && $this->isSelfOrDescendantOf($this->page, $page);

        $removed = $deletePage->handle($page);

        $broadcaster->documentationChanged((int) $this->board->getKey(), $wasPublished);

        session()->flash('status', $removed > 1
            ? $removed.' pages were deleted.'
            : 'Page deleted.');

        // Deleting the branch you were reading leaves nothing to render, so go
        // back to the section rather than to a 404.
        if ($openPageWasRemoved) {
            $this->redirect(route('docs.index', $this->board), navigate: true);
        }
    }

    /**
     * Is $page the same as $ancestor, or somewhere beneath it?
     *
     * Walks upward, bounded by MAX_DEPTH, so a cycle from a bad write cannot
     * spin here.
     */
    private function isSelfOrDescendantOf(DocPage $page, DocPage $ancestor): bool
    {
        $current = $page;
        $steps = 0;

        while (true) {
            if ($current->getKey() === $ancestor->getKey()) {
                return true;
            }

            if ($current->parent_id === null || $steps++ > DocPage::MAX_DEPTH + 1) {
                return false;
            }

            $parent = DocPage::query()->whereKey($current->parent_id)->first();

            if (! $parent instanceof DocPage) {
                return false;
            }

            $current = $parent;
        }
    }

    // -----------------------------------------------------------------
    // Publishing
    // -----------------------------------------------------------------

    /**
     * Publish the open page to customers, or take it back.
     */
    public function toggleVisibility(SetPageVisibility $setVisibility, BoardBroadcaster $broadcaster): void
    {
        $this->requirePage();
        $this->authorize('changeVisibility', $this->page);

        $target = ! $this->page->customer_visible;

        try {
            $changed = $setVisibility->handle($this->page, $target, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('visibility', $e->getMessage());

            return;
        }

        $this->page->refresh();

        $broadcaster->documentationChanged((int) $this->board->getKey(), true);

        session()->flash('status', $target
            ? 'This page is now visible to customers on this board.'
            : ($changed > 1
                ? 'This page and '.($changed - 1).' page(s) beneath it are internal again.'
                : 'This page is internal again.'));
    }

    // -----------------------------------------------------------------
    // Deleting
    // -----------------------------------------------------------------

    public function confirmDelete(): void
    {
        $this->requirePage();
        $this->authorize('delete', $this->page);

        $this->confirmingDelete = true;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDelete = false;
    }

    public function destroyPage(DeletePage $deletePage, BoardBroadcaster $broadcaster): void
    {
        $this->requirePage();
        $this->authorize('delete', $this->page);

        $wasPublished = (bool) $this->page->customer_visible;
        $removed = $deletePage->handle($this->page);

        $broadcaster->documentationChanged((int) $this->board->getKey(), $wasPublished);

        session()->flash('status', $removed > 1
            ? $removed.' pages were deleted.'
            : 'Page deleted.');

        $this->redirect(route('docs.index', $this->board), navigate: true);
    }

    // -----------------------------------------------------------------

    public function render(
        DocPageFinder $finder,
        ContentRenderer $renderer,
        BoardAccess $access,
        RichText $rich,
    ) {
        $user = auth()->user();

        // Defence in depth: $page was rehydrated by primary key, which does not
        // re-apply the visibility scope or the ancestor rule.
        if ($this->page !== null) {
            $this->authorize('view', $this->page);
            $this->page->loadMissing(['creator', 'editor', 'draftBy']);
        }

        $canManage = $access->canManageBoardContent($user, $this->board);
        $canEditPage = $this->page !== null && $user->can('update', $this->page);

        $ancestors = $this->page === null ? collect() : $finder->ancestors($this->page, $user);

        $searching = trim($this->search) !== '';

        return view('livewire.docs.show', [
            /*
             * The tree and the search results are two different shapes, so they
             * are two different variables rather than one that changes meaning.
             * Only one of them is ever queried: a search does not need the tree
             * and the tree does not need snippets.
             */
            'tree' => $searching ? collect() : $finder->tree($this->board, $user, null),
            'results' => $searching ? $finder->searchResults($this->board, $user, $this->search) : collect(),
            'searching' => $searching,
            'breadcrumb' => $ancestors,

            /*
             * Which branches of the tree are open on arrival: the path down to
             * the page being read, and the page itself so its own children
             * show.
             *
             * Everything else starts closed and is remembered per board in the
             * browser (resources/js/doc-tree.js). The active path overrides
             * that, because a link straight to a deep page must never land on
             * a sidebar that does not show where you are.
             */
            'openIds' => $this->page === null
                ? []
                : [
                    ...$ancestors->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                    (int) $this->page->getKey(),
                ],

            'bodyHtml' => $this->page === null ? '' : $renderer->render(
                $this->editing ? $this->bodyMd : $this->page->body_md,
                $user,
                $this->board,
            ),

            // The editor's starting document. Not ContentRenderer output: a
            // per-viewer render is not a thing to edit — see
            // RichText::toEditorHtml().
            'editorHtml' => $canEditPage ? $this->editorHtml($rich) : '',

            'canCreate' => $user->can('create', [DocPage::class, $this->board]),
            'canManage' => $canManage,
            // Asked here rather than in the template: a reader with no upload
            // rights should not see an empty attachments panel, but working
            // that out is not the view's job.
            'showAttachments' => $this->page !== null
                && ($canManage || $this->page->attachments()->exists()),
            'canEditPage' => $canEditPage,
            'canAttachInEditor' => $this->canAttachInEditor(),
            'canPublish' => $this->page !== null && $user->can('changeVisibility', $this->page),
            'canDeletePage' => $this->page !== null && $user->can('delete', $this->page),
            'canSeeInternal' => $access->canSeeInternalContent($user),

            // Only somebody who may edit is told there is unsaved work. To a
            // customer the page simply reads as it was last saved.
            'hasDraft' => $canEditPage && $this->page->hasDraft(),

            /*
             * Does typing reach the readers of this page?
             *
             * The one fact the document header has to get right. On an internal
             * page the answer is yes and the header says the work is simply
             * kept; on a published one it is no, and the header has to offer
             * the Publish step instead. Derived from the page rather than from a
             * property, so it cannot drift from what autosave() actually does.
             */
            'autosavesLive' => $canEditPage && ! $this->page->customer_visible,
        ])->title($this->page?->title ?? ($this->board->name.' documentation'));
    }

    private function requirePage(): void
    {
        abort_unless($this->page instanceof DocPage, 404);
    }
}
