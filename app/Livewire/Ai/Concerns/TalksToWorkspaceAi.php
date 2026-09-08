<?php

declare(strict_types=1);

namespace App\Livewire\Ai\Concerns;

use App\Actions\AI\ExecuteChatAction;
use App\Models\AiChatMessage;
use App\Models\Board;
use App\Services\AI\AiContextScope;
use App\Services\AI\Exceptions\AiProviderException;
use App\Services\AI\WorkspaceChatService;
use App\Support\BoardAiSettings;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

/**
 * Everything two AI surfaces do identically.
 *
 * There are two: the global panel that lives in the layout, and the full-page
 * board chat that predates it and keeps its route. They differ in where they
 * are rendered and in how they decide their scope — nothing else. Asking,
 * streaming, confirming, discarding and clearing are the same operations, so
 * they are defined once here rather than twice.
 *
 * A host supplies two things:
 *
 *   contextScope()  which board, or the whole workspace
 *   pageHint()      what the person has open, if the host knows
 *
 * Authorization is not in that list, on purpose. Each request re-authorizes
 * through requireAccess(), and the hosts differ in what failure should mean: a
 * page 404s, because a customer must not learn the chat exists at that URL,
 * while the panel lives in the layout of every page and must therefore fail
 * closed to an empty state — a 404 raised from the layout would take the whole
 * page with it. So the trait asks the question and lets the host answer it.
 */
trait TalksToWorkspaceAi
{
    public string $draft = '';

    /**
     * The answer being streamed, as plain text.
     *
     * Held so a re-render mid-exchange does not blank the panel. It is not the
     * durable artefact: when the turn is stored, render() shows the stored row
     * through ContentRenderer instead, per viewer.
     */
    public string $streamingAnswer = '';

    public bool $sending = false;

    public ?string $aiError = null;

    public ?int $confirmingMessageId = null;

    /**
     * The scope of the next question. Supplied by the host.
     */
    abstract protected function contextScope(): AiContextScope;

    /**
     * Whether this request may use the assistant at all.
     *
     * Returns false rather than throwing. See the class comment.
     */
    abstract protected function requireAccess(): bool;

    /**
     * What the person is looking at, as an untrusted hint.
     *
     * Re-resolved server-side by App\Services\AI\PageContextResolver, so a
     * host may pass browser-supplied values straight through.
     *
     * @return array<string, mixed>
     */
    protected function pageHint(): array
    {
        return [];
    }

    // -----------------------------------------------------------------
    // Asking
    // -----------------------------------------------------------------

    public function send(WorkspaceChatService $chat): void
    {
        if (! $this->requireAccess()) {
            return;
        }

        /*
         * The re-entry guard.
         *
         * Livewire rehydrates public properties from the browser, so `sending`
         * arriving as true means this request is a second submission of a
         * question already in flight — a double-click, or an Enter keypress
         * landing while the first response streams. Dropping it here is what
         * makes duplicate submission impossible rather than merely unlikely;
         * the disabled button is the courtesy.
         */
        if ($this->sending) {
            return;
        }

        if (! BoardAiSettings::providerConfigured()) {
            $this->aiError = 'No AI provider is configured for this deployment.';

            return;
        }

        $validated = $this->validate([
            'draft' => ['required', 'string', 'max:8000'],
        ], attributes: ['draft' => 'message']);

        $question = $validated['draft'];

        $this->aiError = null;
        $this->streamingAnswer = '';

        // Cleared before the call, so a slow answer cannot be resubmitted by a
        // second click on a page that still shows the text.
        $this->draft = '';
        $this->sending = true;

        /*
         * Streaming holds this one request open for the length of the answer,
         * which is longer than php.ini's global max_execution_time allows. That
         * global stays low on purpose — it is what bounds every other request —
         * so the allowance is raised here, on this path only.
         *
         * PHP does not count time spent waiting on a socket towards the limit
         * on Unix, so this is belt as much as braces; it is set explicitly
         * rather than relied upon implicitly.
         */
        if (function_exists('set_time_limit')) {
            @set_time_limit(max(60, (int) config('ai.chat.stream_time_limit', 360)));
        }

        try {
            $this->stream(name: 'answer', content: $this->thinkingPlaceholder(), replace: true);

            $accumulated = '';

            $chat->askStreamed(
                $this->contextScope(),
                auth()->user(),
                $question,
                function (string $chunk) use (&$accumulated): void {
                    $accumulated .= $chunk;

                    /*
                     * Escaped, because Livewire's client assigns streamed
                     * content with innerHTML. An unescaped model answer would
                     * be script injection with extra steps, and the model is
                     * repeating text written by users.
                     *
                     * Sent on empty chunks too: the write is what keeps the
                     * connection warm past nginx's read timeout while the
                     * model is still thinking.
                     */
                    $this->stream(
                        name: 'answer',
                        content: $accumulated === ''
                            ? $this->thinkingPlaceholder()
                            : e($accumulated),
                        replace: true,
                    );
                },
                $this->pageHint(),
            );

            $this->streamingAnswer = '';
        } catch (AiProviderException $exception) {
            // The user's own turn is already stored, so the transcript shows
            // what was asked and that it did not get through.
            $this->aiError = $exception->getMessage();
            $this->streamingAnswer = '';
        } finally {
            $this->sending = false;

            // The stored turn is about to render in the transcript, so the
            // live preview is cleared rather than left duplicating it.
            $this->stream(name: 'answer', content: '', replace: true);
        }
    }

    private function thinkingPlaceholder(): string
    {
        return '<span class="text-slate-400">Thinking…</span>';
    }

    // -----------------------------------------------------------------
    // Proposed actions
    // -----------------------------------------------------------------

    public function startConfirming(int $messageId): void
    {
        if (! $this->requireAccess()) {
            return;
        }

        $this->confirmingMessageId = $this->message($messageId)->getKey();
    }

    public function cancelConfirming(): void
    {
        $this->confirmingMessageId = null;
    }

    /**
     * Carry out a proposed action.
     *
     * Authorization happens twice and they are different questions: access to
     * the assistant asks whether this person may be talking to it at all, and
     * ExecuteChatAction then authorizes the actual write — `create` on a
     * ticket, `update` on a page — against the same abilities the ordinary
     * screens use. Being allowed to chat grants nothing.
     */
    public function confirm(ExecuteChatAction $execute): void
    {
        if (! $this->requireAccess()) {
            return;
        }

        $message = $this->message((int) $this->confirmingMessageId);

        // A proposal is always filed against the board it would change. The
        // workspace thread cannot execute one, because there would be no board
        // to authorize the write against.
        $board = $message->board;

        if (! $board instanceof Board) {
            $this->confirmingMessageId = null;
            $this->aiError = 'Select the board this change belongs to before confirming it.';

            return;
        }

        try {
            $result = $execute->handle($board, $message, auth()->user());
        } catch (AuthorizationException) {
            $this->confirmingMessageId = null;
            $this->aiError = 'You are not allowed to make that change.';

            return;
        } catch (RuntimeException $exception) {
            $this->confirmingMessageId = null;
            $this->aiError = $exception->getMessage();

            return;
        }

        $this->confirmingMessageId = null;
        $this->aiError = null;

        session()->flash('status', $result['label'].'.');
    }

    public function discard(int $messageId, ExecuteChatAction $execute): void
    {
        if (! $this->requireAccess()) {
            return;
        }

        $execute->discard($this->message($messageId), auth()->user());

        $this->confirmingMessageId = null;
    }

    /**
     * Delete this person's own conversation in the current scope.
     *
     * No longer gated on configuring the board: the transcript is per person,
     * so there is nobody else's history to destroy.
     */
    public function clearHistory(WorkspaceChatService $chat): void
    {
        if (! $this->requireAccess()) {
            return;
        }

        $chat->clear($this->contextScope(), auth()->user());

        $this->confirmingMessageId = null;
        $this->aiError = null;
        $this->streamingAnswer = '';
    }

    /**
     * Resolve a message id supplied by the browser.
     *
     * Three filters, and each closes a different door. `visibleTo` and
     * `ownedBy` mean a swapped id cannot reach a board this person is not on or
     * somebody else's conversation. The scope filter means it cannot reach
     * *their own* other conversation either: a proposal drafted against board A
     * must not be confirmable while the assistant is pointed at board B, or the
     * change would land on the wrong board. It 404s instead of resolving.
     */
    private function message(int $messageId): AiChatMessage
    {
        $scope = $this->contextScope();

        $query = AiChatMessage::query()
            ->visibleTo(auth()->user())
            ->ownedBy(auth()->user())
            ->whereKey($messageId);

        $scope->board instanceof Board
            ? $query->forBoard($scope->board)
            : $query->workspaceScoped();

        $message = $query->first();

        abort_unless($message instanceof AiChatMessage, 404);

        return $message;
    }
}
