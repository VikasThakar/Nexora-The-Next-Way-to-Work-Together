<?php

declare(strict_types=1);

namespace App\Livewire\Ai;

use App\Actions\AI\ExecuteChatAction;
use App\Models\AiChatMessage;
use App\Models\Board;
use App\Services\AI\Exceptions\AiProviderException;
use App\Services\AI\WorkspaceChatService;
use App\Services\ContentRenderer;
use App\Support\BoardAiSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/**
 * The team-only workspace AI chat, for one board.
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
    public Board $board;

    public string $draft = '';

    public bool $sending = false;

    public ?int $confirmingMessageId = null;

    public function mount(Board $board): void
    {
        // 404 rather than 403: a customer must not learn the chat exists.
        $this->authorize('useAiChat', $board);

        $this->board = $board;
    }

    // -----------------------------------------------------------------
    // Asking
    // -----------------------------------------------------------------

    public function send(WorkspaceChatService $chat): void
    {
        $this->authorize('useAiChat', $this->board);

        if (! BoardAiSettings::providerConfigured()) {
            session()->flash('error', 'No AI provider is configured for this deployment.');

            return;
        }

        $validated = $this->validate([
            'draft' => ['required', 'string', 'max:8000'],
        ], attributes: ['draft' => 'message']);

        $question = $validated['draft'];

        // Cleared before the call, so a slow answer cannot be resubmitted by a
        // second click on a page that still shows the text.
        $this->draft = '';

        try {
            $chat->ask($this->board, auth()->user(), $question);
        } catch (AiProviderException $exception) {
            // The user's own turn is already stored, so the transcript shows
            // what was asked and that it did not get through.
            session()->flash('error', $exception->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // Proposed actions
    // -----------------------------------------------------------------

    public function startConfirming(int $messageId): void
    {
        $this->authorize('useAiChat', $this->board);

        $this->confirmingMessageId = $this->message($messageId)->getKey();
    }

    public function cancelConfirming(): void
    {
        $this->confirmingMessageId = null;
    }

    /**
     * Carry out a proposed action.
     *
     * Authorization happens twice and they are different questions: `useAiChat`
     * asks whether this person may be on this screen at all, and
     * ExecuteChatAction then authorizes the actual write — `create` on a ticket,
     * `update` on a page — against the same abilities the ordinary screens use.
     * Being allowed to chat grants nothing.
     */
    public function confirm(ExecuteChatAction $execute): void
    {
        $this->authorize('useAiChat', $this->board);

        $message = $this->message((int) $this->confirmingMessageId);

        try {
            $result = $execute->handle($this->board, $message, auth()->user());
        } catch (AuthorizationException) {
            $this->confirmingMessageId = null;
            session()->flash('error', 'You are not allowed to make that change.');

            return;
        } catch (RuntimeException $exception) {
            $this->confirmingMessageId = null;
            session()->flash('error', $exception->getMessage());

            return;
        }

        $this->confirmingMessageId = null;

        session()->flash('status', $result['label'].'.');
    }

    public function discard(int $messageId, ExecuteChatAction $execute): void
    {
        $this->authorize('useAiChat', $this->board);

        $execute->discard($this->message($messageId), auth()->user());

        $this->confirmingMessageId = null;
    }

    public function clearHistory(WorkspaceChatService $chat): void
    {
        // Clearing a shared transcript removes other people's questions, so it
        // is held to the higher bar of the two: configuring the board's AI,
        // rather than merely being allowed to chat.
        $this->authorize('manageAiSettings', $this->board);

        $chat->clear($this->board);

        session()->flash('status', 'Conversation cleared.');
    }

    /**
     * Resolve a message id from the browser within this board.
     *
     * Read through the scoped query, so a swapped id cannot reach a transcript on
     * another board: it 404s instead of resolving.
     */
    private function message(int $messageId): AiChatMessage
    {
        $message = AiChatMessage::query()
            ->visibleTo(auth()->user())
            ->forBoard($this->board)
            ->whereKey($messageId)
            ->first();

        abort_unless($message instanceof AiChatMessage, 404);

        return $message;
    }

    // -----------------------------------------------------------------

    public function render(WorkspaceChatService $chat, ContentRenderer $renderer)
    {
        $this->authorize('useAiChat', $this->board);

        $user = auth()->user();

        $messages = $chat->transcript($this->board, $user, 100);

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
