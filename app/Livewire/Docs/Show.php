<?php

declare(strict_types=1);

namespace App\Livewire\Docs;

use App\Actions\Docs\CreatePage;
use App\Actions\Docs\DeletePage;
use App\Actions\Docs\MovePage;
use App\Actions\Docs\SetPageVisibility;
use App\Actions\Docs\UpdatePage;
use App\Livewire\Concerns\ListensForBoardUpdates;
use App\Models\Board;
use App\Models\DocPage;
use App\Services\BoardAccess;
use App\Services\BoardBroadcaster;
use App\Services\ContentRenderer;
use App\Services\DocPageFinder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * The documentation section of a board: sidebar tree on the left, page on the
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
 */
#[Layout('layouts.app')]
class Show extends Component
{
    use ListensForBoardUpdates;

    public Board $board;

    public ?DocPage $page = null;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    // Editing the open page
    public bool $editing = false;

    public bool $previewing = false;

    public string $title = '';

    public string $bodyMd = '';

    // Creating a new page
    public bool $creating = false;

    public string $newTitle = '';

    public ?int $newParentId = null;

    public bool $confirmingDelete = false;

    public function mount(Board $board, DocPageFinder $finder, ?string $slug = null): void
    {
        $this->authorize('viewAny', [DocPage::class, $board]);

        $this->board = $board;

        if ($slug !== null && $slug !== '') {
            $this->page = $finder->findOrFail($board, $slug, auth()->user());
            $this->syncFromPage();
        }
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return $this->boardUpdateListeners(isset($this->board) ? (int) $this->board->getKey() : null);
    }

    private function syncFromPage(): void
    {
        $this->title = (string) $this->page?->title;
        $this->bodyMd = (string) $this->page?->body_md;
    }

    // -----------------------------------------------------------------
    // Editing
    // -----------------------------------------------------------------

    public function startEditing(): void
    {
        $this->requirePage();
        $this->authorize('update', $this->page);

        $this->editing = true;
        $this->previewing = false;
        $this->syncFromPage();
    }

    public function cancelEditing(): void
    {
        $this->editing = false;
        $this->previewing = false;
        $this->resetValidation();
        $this->syncFromPage();
    }

    public function togglePreview(): void
    {
        $this->previewing = ! $this->previewing;
    }

    public function save(UpdatePage $updatePage, BoardBroadcaster $broadcaster): void
    {
        $this->requirePage();
        $this->authorize('update', $this->page);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:200'],
            'bodyMd' => ['nullable', 'string', 'max:200000'],
        ], attributes: ['bodyMd' => 'content']);

        $updatePage->handle($this->page, [
            'title' => $validated['title'],
            'body_md' => $validated['bodyMd'],
        ], auth()->user());

        $this->page->refresh();
        $this->editing = false;
        $this->previewing = false;

        $broadcaster->documentationChanged((int) $this->board->getKey(), (bool) $this->page->customer_visible);

        session()->flash('status', 'Page saved.');
    }

    // -----------------------------------------------------------------
    // Creating
    // -----------------------------------------------------------------

    public function startCreating(?int $parentId = null): void
    {
        $this->authorize('create', [DocPage::class, $this->board]);

        $this->creating = true;
        $this->newTitle = '';
        $this->newParentId = $parentId;
        $this->resetValidation();
    }

    public function cancelCreating(): void
    {
        $this->creating = false;
        $this->newTitle = '';
        $this->newParentId = null;
        $this->resetValidation();
    }

    public function createPage(CreatePage $createPage): void
    {
        $this->authorize('create', [DocPage::class, $this->board]);

        $this->validate(['newTitle' => ['required', 'string', 'max:200']], attributes: ['newTitle' => 'title']);

        $page = $createPage->handle($this->board, [
            'title' => $this->newTitle,
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

    public function render(DocPageFinder $finder, ContentRenderer $renderer, BoardAccess $access)
    {
        $user = auth()->user();

        // Defence in depth: $page was rehydrated by primary key, which does not
        // re-apply the visibility scope or the ancestor rule.
        if ($this->page !== null) {
            $this->authorize('view', $this->page);
            $this->page->loadMissing(['creator', 'editor']);
        }

        $canManage = $access->canManageBoardContent($user, $this->board);

        return view('livewire.docs.show', [
            'tree' => $finder->tree($this->board, $user, $this->search),
            'searching' => trim($this->search) !== '',
            'breadcrumb' => $this->page === null ? collect() : $finder->ancestors($this->page, $user),
            'bodyHtml' => $this->page === null ? '' : $renderer->render(
                $this->editing ? $this->bodyMd : $this->page->body_md,
                $user,
                $this->board,
            ),
            'canCreate' => $user->can('create', [DocPage::class, $this->board]),
            'canManage' => $canManage,
            // Asked here rather than in the template: a reader with no upload
            // rights should not see an empty attachments panel, but working
            // that out is not the view's job.
            'showAttachments' => $this->page !== null
                && ($canManage || $this->page->attachments()->exists()),
            'canEditPage' => $this->page !== null && $user->can('update', $this->page),
            'canPublish' => $this->page !== null && $user->can('changeVisibility', $this->page),
            'canDeletePage' => $this->page !== null && $user->can('delete', $this->page),
            'canSeeInternal' => $access->canSeeInternalContent($user),
        ])->title($this->page?->title ?? ($this->board->name.' documentation'));
    }

    private function requirePage(): void
    {
        abort_unless($this->page instanceof DocPage, 404);
    }
}
