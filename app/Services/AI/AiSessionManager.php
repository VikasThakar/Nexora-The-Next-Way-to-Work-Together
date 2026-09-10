<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\AiChatMode;
use App\Enums\AiKnowledgeScope;
use App\Models\AiSession;
use App\Models\Board;
use App\Models\User;
use App\Support\AiConfiguration;
use App\Support\AiModelCatalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Starts, finds, ends and meters assistant sessions.
 *
 * The problem this solves is context, not filing. A transcript that has been
 * running for a month is re-sent in full on every question: slow, expensive,
 * and worse at answering, because what matters is buried under what does not.
 * A session is the unit that lets somebody draw a line and start again, and
 * everything else here follows from that.
 *
 * Ownership is the whole authorization model
 * ------------------------------------------
 * Every read goes through `AiSession::visibleTo()`, which requires internal
 * access, then ownership, then that the board is still reachable. A uuid
 * supplied by a browser is resolved through that scope and 404s when it does
 * not match — so a swapped uuid cannot reach a colleague's conversation, an
 * administrator's, or one on a board the asker has been removed from. There is
 * no code path here that loads a session by key without it.
 *
 * The scope is part of a session's identity
 * -----------------------------------------
 * A session belongs to one board, or to the workspace. Asking about board B
 * inside a session started on board A is not a thing the product allows,
 * because the answer would be built from B's context and filed under A's
 * history — and because a proposal drafted in one scope must not be
 * confirmable in another. `for()` therefore resolves per scope, and switching
 * the panel's context switches session.
 *
 * Limits are enforced here, server-side
 * -------------------------------------
 * Two ceilings, both from the effective configuration: what one session may
 * spend, and what one person may spend in a day. The first ends the session and
 * asks for a new one, which is the useful remedy. The second refuses, because
 * there is no remedy the person can apply themselves. Both are checked before
 * the provider is called; a disabled button is not a limit.
 */
class AiSessionManager
{
    public function __construct(
        private readonly AiConfigurationResolver $configuration,
        private readonly AiUsageRecorder $usage,
    ) {}

    /**
     * The session a question should be filed under, created if there is none.
     *
     * "Continue the current session" resolves to the most recently active open
     * session in this scope. Most recently active rather than most recently
     * created, because somebody who started a session yesterday and returned to
     * it this morning means the one they returned to.
     */
    public function current(AiContextScope $scope, User $user): AiSession
    {
        $existing = $this->query($scope, $user)->open()->recentFirst()->first();

        return $existing instanceof AiSession ? $existing : $this->start($scope, $user);
    }

    /**
     * Start a fresh session, ending the open one in this scope.
     *
     * The previous session is *ended*, not deleted: it and its turns stay
     * readable, which is the point of having sessions at all. Ending it also
     * means `current()` will not drift back to it on the next question.
     *
     * The provider, model and mode are snapshotted from the configuration in
     * force now. They are what this conversation ran under, and they are never
     * refreshed afterwards — see AiSession.
     *
     * The knowledge scope is the one setting a new session does NOT inherit,
     * and the parameter exists only so a caller can be explicit about that
     * rather than to invite passing something else. Carrying the chat mode over
     * is a convenience — somebody who has been drafting tickets wants to carry
     * on drafting tickets — but carrying "may reach outside this project" over
     * into a conversation nobody has asked that of is a widening that happens
     * silently, so it does not happen. See AiKnowledgeScope.
     */
    public function start(
        AiContextScope $scope,
        User $user,
        ?AiChatMode $chatMode = null,
        ?AiKnowledgeScope $knowledgeScope = null,
    ): AiSession {
        $configuration = $this->configuration->forBoard($scope->board);
        $chatMode ??= AiChatMode::default();
        $knowledgeScope ??= AiKnowledgeScope::default();

        return DB::transaction(function () use ($scope, $user, $chatMode, $knowledgeScope, $configuration): AiSession {
            $this->query($scope, $user)->open()->each(
                fn (AiSession $open) => $this->end($open)
            );

            $session = new AiSession;

            // Assigned, never mass assigned: a request that could choose its
            // own user_id could adopt somebody else's conversation.
            $session->uuid = (string) Str::uuid();
            $session->user_id = $user->getKey();
            $session->board_id = $scope->boardKey();
            $session->title = null;
            $session->provider = $configuration->provider;
            /*
             * The model is derived from what the conversation is for, never
             * chosen directly. `ai.chat.modes` names one per mode; a name this
             * provider does not serve resolves to the effective default, so a
             * workspace running on OpenAI is never sent an Anthropic id.
             */
            $session->model = $this->resolveModel($configuration, $chatMode->preferredModel());
            $session->capability_mode = $configuration->mode;
            $session->chat_mode = $chatMode;
            $session->knowledge_scope = $knowledgeScope;
            $session->tokens_input = null;
            $session->tokens_output = null;
            $session->estimated_cost = null;
            $session->message_count = 0;
            $session->started_at = now();
            $session->last_activity_at = now();

            $session->save();

            return $session;
        });
    }

    /**
     * Close a session to further questions.
     *
     * Idempotent, so ending an already-ended session is harmless — which
     * matters because two requests can race to start a new one.
     */
    public function end(AiSession $session): AiSession
    {
        if ($session->isOpen()) {
            $session->ended_at = now();
            $session->save();
        }

        return $session;
    }

    /**
     * Change what a session is for, from here on.
     *
     * The one way the mode — and therefore the model — is written, so the two
     * cannot fall out of step: there is no path that sets a model without
     * setting the mode it came from, which is what makes "writing runs on the
     * deep-reasoning model" a property of the system rather than of the screen
     * that happened to set it.
     *
     * The session's snapshot is deliberately *not* rewritten backwards. The
     * columns record what the conversation is running on now, and each
     * exchange's own model is recorded on its usage row, so a session switched
     * from reading to writing halfway through still reads accurately.
     */
    public function useChatMode(AiSession $session, AiChatMode $chatMode): AiSession
    {
        $configuration = $this->configuration->forBoard($session->board);

        $session->chat_mode = $chatMode;
        $session->model = $this->resolveModel($configuration, $chatMode->preferredModel());
        $session->save();

        return $session;
    }

    /**
     * Change where a session may source its answers from, from here on.
     *
     * Deliberately not folded into useChatMode(). That method exists because
     * the mode and the model must move together; this one has no such coupling
     * — the knowledge scope does not change which model answers, does not
     * change what the person may read, and does not change what the AI may
     * attempt. It writes one column, and keeping it separate is what stops
     * somebody later assuming that changing one of the three implies anything
     * about the other two.
     *
     * There is no authorization check here, and that is not an omission. Both
     * values are permitted for everybody, including a customer: a customer in
     * Outside may ask what Laravel is, and every project row they can reach is
     * the same set of rows either way. What a customer may see is decided by
     * the readers each tool calls, and what they may change is decided by
     * AiCapabilityGuard — neither of which consults this column.
     */
    public function useKnowledgeScope(AiSession $session, AiKnowledgeScope $scope): AiSession
    {
        $session->knowledge_scope = $scope;
        $session->save();

        return $session;
    }

    /**
     * Rename a session.
     *
     * Trimmed, length-capped, and an empty name clears it back to the
     * reference rather than storing a blank title.
     */
    public function rename(AiSession $session, ?string $title): AiSession
    {
        $title = trim((string) $title);

        $session->title = $title === '' ? null : mb_substr($title, 0, 120);
        $session->save();

        return $session;
    }

    /**
     * One person's sessions in one scope, most recently active first.
     *
     * @return Collection<int, AiSession>
     */
    public function history(AiContextScope $scope, User $user, ?int $limit = null): Collection
    {
        return $this->query($scope, $user)
            ->recentFirst()
            ->limit($limit ?? max(1, (int) config('ai.limits.session_history', 25)))
            ->get();
    }

    /**
     * Resolve a session uuid supplied by a browser.
     *
     * Scoped by visibility, ownership *and* the current context, then 404s.
     * The context filter is the one that is easy to leave out and matters most:
     * a session from another board must not become the current one while the
     * panel is pointed here, or the next answer would be built from this
     * board's context and filed under that board's history.
     */
    public function findOrFail(AiContextScope $scope, User $user, string $uuid): AiSession
    {
        $session = $this->query($scope, $user)->where('ai_sessions.uuid', $uuid)->first();

        if (! $session instanceof AiSession) {
            throw new NotFoundHttpException;
        }

        return $session;
    }

    /**
     * The same lookup, answering with null instead of aborting.
     *
     * For the panel, which holds a remembered uuid in the session and must
     * degrade to "start a new one" when it no longer resolves — a board
     * membership revoked, a stale value from another deployment — rather than
     * 404ing the page it is rendered on.
     */
    public function find(AiContextScope $scope, User $user, ?string $uuid): ?AiSession
    {
        if ($uuid === null || trim($uuid) === '') {
            return null;
        }

        return $this->query($scope, $user)->where('ai_sessions.uuid', $uuid)->first();
    }

    // -----------------------------------------------------------------
    // Limits
    // -----------------------------------------------------------------

    /**
     * Refuse a question that would exceed a ceiling, and say which.
     *
     * Returns null when the question may proceed. Called before the provider,
     * so nothing is spent proving the limit.
     *
     * The session ceiling ends the session as it refuses. That is the remedy
     * and the message names it: the conversation is full, start a new one. The
     * daily ceiling does not, because starting a new session would not help and
     * pretending it might would be worse than saying no.
     */
    public function refusalFor(AiSession $session, User $user, AiConfiguration $configuration): ?string
    {
        if ($session->hasReachedTokenLimit($configuration->sessionTokenLimit)) {
            $this->end($session);

            return 'This conversation has reached its '
                .number_format($configuration->sessionTokenLimit)
                .'-token limit. Start a new session to carry on with a fresh context.';
        }

        $dailyLimit = $configuration->dailyUserTokenLimit;

        if ($dailyLimit > 0 && $this->usage->spentTodayBy($user) >= $dailyLimit) {
            return 'You have reached your daily AI limit of '
                .number_format($dailyLimit).' tokens. It resets at midnight.';
        }

        return null;
    }

    /**
     * Note that a turn was stored against a session.
     *
     * Kept separate from the token accounting because the two have different
     * lifetimes: a question is stored before the provider is called (so a
     * failed question still appears in the transcript), and its tokens are
     * known only afterwards.
     */
    public function noteMessage(AiSession $session): void
    {
        $session->message_count = (int) $session->message_count + 1;
        $session->last_activity_at = now();

        $session->save();
    }

    // -----------------------------------------------------------------

    /**
     * The base query: visible, owned, and in this scope.
     *
     * Every read in this class goes through it. `visibleTo` already requires
     * ownership, and the scope filter is applied on top.
     *
     * @return Builder<AiSession>
     */
    private function query(AiContextScope $scope, User $user)
    {
        $query = AiSession::query()->visibleTo($user);

        $scope->board instanceof Board
            ? $query->forBoard($scope->board)
            : $query->workspaceScoped();

        return $query;
    }

    /**
     * A model name from anywhere, resolved to one this provider serves.
     *
     * Falls back to the effective model rather than throwing, because the
     * callers are a mode's configured preference and a stale session — a
     * deployment that points writing at an Anthropic model and then switches
     * the workspace to OpenAI must keep answering. It throws only when the
     * configuration has no model at all, which is a deployment that has named
     * a provider it has not filled in.
     */
    private function resolveModel(AiConfiguration $configuration, ?string $requested): string
    {
        $requested = trim((string) $requested);

        if ($requested !== '' && AiModelCatalogue::serves($configuration->provider, $requested)) {
            return $requested;
        }

        $model = $configuration->model;

        if ($model === null) {
            throw new RuntimeException(
                'No model is configured for the '.$configuration->provider->label().' provider.'
            );
        }

        return $model;
    }
}
