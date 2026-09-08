<?php

declare(strict_types=1);

namespace App\Livewire\Ai;

use App\Livewire\Ai\Concerns\TalksToWorkspaceAi;
use App\Models\Board;
use App\Services\AI\AiContextScope;
use App\Services\AI\WorkspaceChatService;
use App\Services\ContentRenderer;
use App\Support\BoardAiSettings;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The full-page workspace AI chat, for one board.
 *
 * Since the assistant moved into the top bar this is no longer the primary way
 * in — App\Livewire\Ai\Assistant is. It is kept because it is still the right
 * surface for a long session on one board: a whole-window transcript, a
 * bookmarkable URL, and a link from the board's AI settings screen. The asking,
 * streaming and confirming are shared with the panel through
 * TalksToWorkspaceAi, so the two cannot drift apart.
 *
 * Customers are excluded twice over and neither check is the UI: the route group
 * carries `role:admin,team`, and BoardPolicy::useAiChat denies as 404 on mount
 * and on every render. `AiChatMessage::visibleTo()` then refuses them in SQL, so
 * even a component that forgot to authorize would read nothing.
 *
 * Authorization is re-run on every request because each Livewire call is its own
 * HTTP request. A member of staff whose board membership is removed mid-session
 * loses the chat on their next keystroke, not at their next login.
 *
 * The confirmation flow
 * --------------------
 * A proposal is a *message*, not a queued write. `send()` may store one on the
 * assistant's turn; `confirm()` is a separate request, separately authorized,
 * that hands it to ExecuteChatAction. Nothing between those two points touches
 * the database on the proposal's behalf, so a user who closes the tab has
 * changed nothing.
 */
#[Layout('layouts.app')]
class Chat extends Component
{
    use TalksToWorkspaceAi;

    public Board $board;

    public function mount(Board $board): void
    {
        // 404 rather than 403: a customer must not learn the chat exists.
        $this->authorize('useAiChat', $board);

        $this->board = $board;
    }

    protected function contextScope(): AiContextScope
    {
        return AiContextScope::board($this->board);
    }

    /**
     * A page may abort, and should.
     *
     * The panel cannot — it renders inside every layout — but this component
     * *is* the page, so the historic 404 is both safe and correct here.
     */
    protected function requireAccess(): bool
    {
        $this->authorize('useAiChat', $this->board);

        return true;
    }

    /**
     * The board being read is the page context, and it is not browser-supplied.
     *
     * @return array<string, mixed>
     */
    protected function pageHint(): array
    {
        return ['board' => $this->board->slug];
    }

    public function render(WorkspaceChatService $chat, ContentRenderer $renderer)
    {
        $this->authorize('useAiChat', $this->board);

        $user = auth()->user();

        $messages = $chat->transcript($this->contextScope(), $user, 100);

        // Rendered per viewer, because ContentRenderer resolves ticket
        // references and mentions against what *this* reader may open. The same
        // stored answer therefore links AQD-42 for somebody who can see it and
        // leaves it as plain text for anybody who cannot.
        $rendered = [];

        foreach ($messages as $message) {
            $rendered[$message->getKey()] = $renderer->render(
                $message->content,
                $user,
                $this->board,
                mentionScope: false,
            );
        }

        return view('livewire.ai.chat', [
            'messages' => $messages,
            'rendered' => $rendered,
            'confirming' => $this->confirmingMessageId === null
                ? null
                : $messages->firstWhere('id', $this->confirmingMessageId),
            'providerConfigured' => BoardAiSettings::providerConfigured(),
            'canConfigure' => $user->can('manageAiSettings', $this->board),
            'model' => $this->board->aiSettings()->model,
        ])->title('AI chat · '.$this->board->name);
    }
}
