<?php

declare(strict_types=1);

namespace App\Livewire\Ai;

use App\Livewire\Ai\Concerns\TalksToWorkspaceAi;
use App\Models\Board;
use App\Services\AI\AiContextScope;
use App\Services\AI\PageContextResolver;
use App\Services\AI\WorkspaceChatService;
use App\Services\BoardAccess;
use App\Services\ContentRenderer;
use App\Support\BoardAiSettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Isolate;
use Livewire\Attributes\Session;
use Livewire\Component;

/**
 * The global assistant panel.
 *
 * Mounted once in the application layout and persisted across wire:navigate, so
 * it is reachable from the dashboard, a board, a ticket, the documentation, the
 * statistics screens and settings without leaving the page. Asking, streaming
 * and confirming come from TalksToWorkspaceAi and are shared with the full-page
 * board chat.
 *
 * Three things make a layout component different from a page, and all three are
 * about failing safely rather than loudly.
 *
 * It never aborts
 * ---------------
 * App\Livewire\Ai\Chat 404s when authorization fails, which is right for a page
 * a customer must not know exists. Doing that here would take down whatever
 * page the panel happens to be rendered on: a team member whose board
 * membership was revoked mid-session would find the *dashboard* returning 404.
 * So every failure here narrows the panel to its board picker instead. The
 * security outcome is the same — they see nothing — with no collateral damage.
 *
 * It is not rendered for people who cannot use it
 * -----------------------------------------------
 * The layout asks eligibleFor() before mounting the component at all, so a
 * customer's page contains no panel and no component id for a crafted Livewire
 * request to address. That is defence in depth, not the defence: the checks in
 * this class and AiChatMessage's SQL scope are.
 *
 * It costs nothing until it is opened
 * -----------------------------------
 * `revealed` starts false and render() returns an empty shell, so every page
 * load in the application does not pay for a transcript query and a per-viewer
 * render of a panel nobody opened. The first open spends one round trip.
 */
#[Isolate]
class Assistant extends Component
{
    use TalksToWorkspaceAi;

    /**
     * Which context the panel is asking about: a board slug, or 'workspace'.
     *
     * A slug rather than a key, because this application never puts a primary
     * key in a URL or a form. Held in the session so the choice survives
     * navigation, a reload and a new tab.
     *
     * Browser-writable, and therefore never trusted: every render re-resolves
     * it through BoardAccess and falls back to the workspace scope if it does
     * not resolve. See selectScope().
     */
    #[Session('ai.assistant.scope')]
    public string $scopeValue = AiContextScope::MODE_WORKSPACE;

    public bool $revealed = false;

    /**
     * What the person has open, as reported by the browser.
     *
     * The panel is persisted, so it is not re-rendered on navigation and cannot
     * read the current route itself. These arrive from the trigger button,
     * which *is* re-rendered on every page, and are re-resolved server-side by
     * PageContextResolver before they reach a prompt.
     */
    public ?string $pageBoard = null;

    public ?int $pageTicket = null;

    public ?string $pageDoc = null;

    /**
     * Is this person able to use the assistant anywhere at all?
     *
     * Exactly equivalent to "there is at least one board where
     * BoardPolicy::useAiChat would allow", because that policy is
     * `canView && canSeeInternalContent` and BoardAccess::query() already
     * encodes canView. One indexed EXISTS per page load.
     *
     * Called by the layout to decide whether to mount the component, and by the
     * top bar to decide whether to show the trigger.
     */
    public static function eligibleFor(?object $user): bool
    {
        if ($user === null) {
            return false;
        }

        return app(BoardAccess::class)->canSeeInternalContent($user)
            && app(BoardAccess::class)->query($user)->notArchived()->exists();
    }

    /**
     * Open the panel for the first time and adopt the current page.
     *
     * Idempotent, so a second call from a re-opened panel is harmless. The
     * board hint may pre-select the context only when nothing has been
     * selected yet — see selectScope() for why a later navigation must not
     * silently change the subject.
     */
    public function reveal(?string $board = null, ?int $ticket = null, ?string $doc = null): void
    {
        $this->pageBoard = $board;
        $this->pageTicket = $ticket;
        $this->pageDoc = $doc;

        if (! $this->revealed && $this->scopeValue === AiContextScope::MODE_WORKSPACE && $board !== null) {
            $resolved = app(PageContextResolver::class)->resolveBoard($board, auth()->user());

            if ($resolved instanceof Board && Gate::allows('useAiChat', $resolved)) {
                $this->scopeValue = $resolved->slug;
            }
        }

        $this->revealed = true;
    }

    /**
     * Tell the panel where the person now is, without changing the subject.
     *
     * Called on every navigation. It deliberately does not touch the selected
     * scope: swapping the context out from under somebody mid-conversation
     * would be a data-confusion bug wearing a convenience costume. The panel
     * offers the switch in its header instead.
     */
    public function syncPage(?string $board = null, ?int $ticket = null, ?string $doc = null): void
    {
        $this->pageBoard = $board;
        $this->pageTicket = $ticket;
        $this->pageDoc = $doc;
    }

    /**
     * Change the context the assistant reasons over.
     *
     * Takes the value as an argument rather than binding it with wire:model on
     * purpose. A bound property is assigned from the browser *before* any hook
     * could validate it, so validation would be checking a value that has
     * already been accepted. An action can refuse one.
     *
     * Refusal is silent and closes down to the workspace scope: an unauthorized
     * slug produces the same panel as no selection, never another board's
     * conversation and never an error that confirms the board exists.
     */
    public function selectScope(string $value): void
    {
        $resolved = $this->resolveScope($value);

        $this->scopeValue = $resolved->value();

        // A different subject means a different thread, so nothing in flight
        // for the previous one should still be on screen.
        $this->confirmingMessageId = null;
        $this->aiError = null;
        $this->streamingAnswer = '';
    }

    // -----------------------------------------------------------------
    // TalksToWorkspaceAi
    // -----------------------------------------------------------------

    protected function contextScope(): AiContextScope
    {
        return $this->resolveScope($this->scopeValue);
    }

    /**
     * Never aborts. See the class comment.
     */
    protected function requireAccess(): bool
    {
        if (! self::eligibleFor(auth()->user())) {
            $this->scopeValue = AiContextScope::MODE_WORKSPACE;

            return false;
        }

        return true;
    }

    /** @return array<string, mixed> */
    protected function pageHint(): array
    {
        return [
            'board' => $this->pageBoard,
            'ticket' => $this->pageTicket,
            'page' => $this->pageDoc,
        ];
    }

    // -----------------------------------------------------------------

    /**
     * A scope value from anywhere, resolved against what this person may reach.
     *
     * The single place a slug becomes a board, so there is one authorization
     * decision rather than one per caller. Anything that does not resolve —
     * a stale session value, a tampered option, a board whose membership has
     * since been revoked — becomes the workspace scope.
     */
    private function resolveScope(string $value): AiContextScope
    {
        if ($value === '' || $value === AiContextScope::MODE_WORKSPACE) {
            return AiContextScope::workspace();
        }

        $user = auth()->user();

        $board = app(BoardAccess::class)->query($user)
            ->where('boards.slug', $value)
            ->first();

        if (! $board instanceof Board || ! Gate::forUser($user)->allows('useAiChat', $board)) {
            return AiContextScope::workspace();
        }

        return AiContextScope::board($board);
    }

    /**
     * Boards offered in the context picker.
     *
     * The same query the sidebar uses, without its display limit: a picker that
     * silently omits the ninth board is a bug rather than a tidy list.
     *
     * @return Collection<int, Board>
     */
    private function selectableBoards(): Collection
    {
        return app(BoardAccess::class)->query(auth()->user())
            ->notArchived()
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'ticket_prefix']);
    }

    public function render(WorkspaceChatService $chat, ContentRenderer $renderer)
    {
        $user = auth()->user();

        // Closed down rather than aborted. A revoked membership empties the
        // panel; it does not break the page the panel is sitting on.
        if (! self::eligibleFor($user)) {
            // Forget the selection too, so it is not restored from the session
            // if access is later granted back on a different board.
            $this->scopeValue = AiContextScope::MODE_WORKSPACE;

            return view('livewire.ai.assistant', [
                'eligible' => false,
                'scope' => AiContextScope::workspace(),
                'boards' => new Collection,
                'messages' => new Collection,
                'rendered' => [],
                'confirming' => null,
                'providerConfigured' => false,
            ]);
        }

        $scope = $this->contextScope();

        // Keep the stored value honest, so a slug that no longer resolves is
        // not remembered across requests.
        $this->scopeValue = $scope->value();

        if (! $this->revealed) {
            // Nothing is read until the panel is actually opened.
            return view('livewire.ai.assistant', [
                'eligible' => true,
                'scope' => $scope,
                'boards' => new Collection,
                'messages' => new Collection,
                'rendered' => [],
                'confirming' => null,
                'providerConfigured' => BoardAiSettings::providerConfigured(),
            ]);
        }

        $messages = $chat->transcript($scope, $user, 60);

        // Rendered per viewer, exactly as the board chat does: ContentRenderer
        // links a ticket reference only for somebody who may open that ticket.
        $rendered = [];

        foreach ($messages as $message) {
            $rendered[$message->getKey()] = $renderer->render(
                $message->content,
                $user,
                $scope->board,
                mentionScope: false,
            );
        }

        return view('livewire.ai.assistant', [
            'eligible' => true,
            'scope' => $scope,
            'boards' => $this->selectableBoards(),
            'messages' => $messages,
            'rendered' => $rendered,
            'confirming' => $this->confirmingMessageId === null
                ? null
                : $messages->firstWhere('id', $this->confirmingMessageId),
            'providerConfigured' => BoardAiSettings::providerConfigured(),
        ]);
    }
}
