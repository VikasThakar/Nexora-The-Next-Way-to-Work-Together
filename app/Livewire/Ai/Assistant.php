<?php

declare(strict_types=1);

namespace App\Livewire\Ai;

use App\Livewire\Ai\Concerns\TalksToWorkspaceAi;
use App\Models\Board;
use App\Models\User;
use App\Services\AI\AiContextScope;
use App\Services\AI\PageContextResolver;
use App\Services\AI\WorkspaceChatService;
use App\Services\BoardAccess;
use App\Services\ContentRenderer;
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
 * statistics screens and settings without leaving the page. Asking, streaming,
 * confirming, and choosing what the conversation is for come from
 * TalksToWorkspaceAi and are shared with the full-page board chat.
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
 * The layout asks eligibleFor() before mounting the component at all, so
 * somebody with no reachable board has no panel and no component id for a
 * crafted Livewire request to address. That is defence in depth, not the
 * defence: the checks in this class and AiChatMessage's SQL scope are.
 *
 * It serves customers as well as staff
 * ------------------------------------
 * A customer who is a member of a board gets this panel, read-only. Nothing in
 * this class implements that distinction, which is the point — the difference
 * lives in the layers that decide what an answer is built from:
 *
 *   the context   BoardContextBuilder and WorkspaceContextBuilder read through
 *                 TicketFinder and DocPageFinder with the asker as the viewer,
 *                 so a customer's context is the customer-visible subset;
 *   the tools     App\Services\AI\Tools\AiToolRegistry withholds the
 *                 staff-only lookups — activity, repositories, code — from a
 *                 customer entirely, so the model is never offered them;
 *   the writes    AiCapabilityGuard::allowsProposals refuses a customer in
 *                 every mode, AI Agent included, so no write tool is sent and
 *                 no proposal can exist to confirm.
 *
 * All three are server-side and none of them is a branch in this component. The
 * only thing the panel itself does differently is say so, in a line under the
 * composer, so a customer knows what they are talking to.
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
     * "There is at least one board they can reach" — equivalent to
     * BoardPolicy::useAssistant allowing somewhere, because that policy is
     * canView() and BoardAccess::query() already encodes canView. One indexed
     * EXISTS per page load.
     *
     * Customers included. That is the change this phase makes here, and it is
     * worth being precise about what it does and does not do: it decides
     * whether the panel is *mounted*, not what the panel may contain. A
     * customer who opens it gets a conversation built from what they can
     * already read, with no write tools in any mode and no staff-only lookups —
     * see BoardPolicy::useAssistant for where each of those is enforced.
     *
     * A person with no board membership still gets nothing, because a panel
     * with no reachable context has nothing to answer from.
     *
     * Called by the layout to decide whether to mount the component, and by the
     * top bar to decide whether to show the trigger.
     */
    public static function eligibleFor(?object $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user instanceof User && ! $user->isActive()) {
            return false;
        }

        return app(BoardAccess::class)->query($user)->notArchived()->exists();
    }

    /**
     * Is the person using it a customer?
     *
     * Read from the same place every other customer decision in the product is
     * read from, so there is one definition. Used only to decide what the panel
     * says about itself — the restrictions themselves are server-side and
     * elsewhere.
     */
    public function isCustomer(): bool
    {
        return ! app(BoardAccess::class)->canSeeInternalContent(auth()->user());
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

            if ($resolved instanceof Board && Gate::allows('useAssistant', $resolved)) {
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

        /*
         * A different subject means a different conversation.
         *
         * The remembered session uuid is dropped rather than carried over,
         * because a session belongs to one scope: keeping it would make the
         * next request resolve nothing and fall back anyway, and clearing it
         * here is what makes that intentional rather than incidental.
         *
         * The mode is not cleared with it. It is what the person wants the
         * assistant to be doing, which does not change because they changed the
         * subject — and session() re-reads it from whichever session this scope
         * resolves to anyway, so a carried-over value cannot survive a session
         * that says otherwise.
         */
        $this->sessionUuid = null;

        $this->confirmingMessageId = null;
        $this->aiError = null;
        $this->sessionExhausted = false;
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

        if (! $board instanceof Board || ! Gate::forUser($user)->allows('useAssistant', $board)) {
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

    public function render(
        WorkspaceChatService $chat,
        ContentRenderer $renderer,
    ) {
        $user = auth()->user();

        // Closed down rather than aborted. A revoked membership empties the
        // panel; it does not break the page the panel is sitting on.
        if (! self::eligibleFor($user)) {
            // Forget the selection too, so it is not restored from the session
            // if access is later granted back on a different board.
            $this->scopeValue = AiContextScope::MODE_WORKSPACE;

            return view('livewire.ai.assistant', $this->emptyState());
        }

        $scope = $this->contextScope();

        // Keep the stored value honest, so a slug that no longer resolves is
        // not remembered across requests.
        $this->scopeValue = $scope->value();

        $configuration = $this->configuration();

        if (! $this->revealed) {
            // Nothing is read until the panel is actually opened — not even
            // the current session, which would otherwise be created by the act
            // of rendering a closed panel on every page in the application.
            return view('livewire.ai.assistant', array_merge($this->emptyState(), [
                'eligible' => true,
                'scope' => $scope,
                'configuration' => $configuration,
                'providerConfigured' => $configuration->isUsable(),
            ]));
        }

        $session = $this->session();

        $messages = $chat->transcript($session, $user, 60);

        /*
         * Rendered per viewer, exactly as the board chat does: ContentRenderer
         * links a ticket reference only for somebody who may open that ticket.
         * Answers carrying a table or a chart come back as blocks instead.
         */
        $transcript = $this->renderTranscript($messages, $renderer, $scope->board);

        $attachments = $this->attachments();

        return view('livewire.ai.assistant', [
            'eligible' => true,
            'customer' => $this->isCustomer(),
            'voice' => $this->voiceStatus(),
            'scope' => $scope,
            'boards' => $this->selectableBoards(),
            'messages' => $messages,
            'rendered' => $transcript['rendered'],
            'blocks' => $transcript['blocks'],
            'attachments' => $attachments,
            'canAttach' => $this->canAttach(),
            'attachmentsSettling' => $this->attachmentsSettling($attachments),
            'confirming' => $this->confirmingMessageId === null
                ? null
                : $messages->firstWhere('id', $this->confirmingMessageId),
            'providerConfigured' => $configuration->isUsable(),
            'configuration' => $configuration,
            'session' => $session,
            // What this conversation may be set to, and why not, if not. The
            // model is not offered separately — it follows from the mode.
            'chatModes' => $this->chatModeOptions(),
            'chatModeRefusal' => $this->chatModeRefusal(),
        ]);
    }

    /**
     * The shape render() returns when there is nothing to show.
     *
     * One definition, because there are three of these — ineligible, closed,
     * and closed-but-eligible — and a missing key in any of them is a Blade
     * error on somebody's dashboard.
     *
     * @return array<string, mixed>
     */
    private function emptyState(): array
    {
        return [
            'eligible' => false,
            'customer' => $this->isCustomer(),
            'voice' => $this->voiceStatus(),
            'scope' => AiContextScope::workspace(),
            'boards' => new Collection,
            'messages' => new Collection,
            'rendered' => [],
            'blocks' => [],
            'attachments' => new Collection,
            'canAttach' => false,
            'attachmentsSettling' => false,
            'confirming' => null,
            'providerConfigured' => false,
            'configuration' => $this->configuration(),
            'session' => null,
            'chatModes' => $this->chatModeOptions(),
            'chatModeRefusal' => $this->chatModeRefusal(),
        ];
    }
}
