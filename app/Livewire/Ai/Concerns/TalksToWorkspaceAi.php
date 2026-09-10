<?php

declare(strict_types=1);

namespace App\Livewire\Ai\Concerns;

use App\Actions\AI\ExecuteChatAction;
use App\Enums\AiChatMode;
use App\Enums\AiChatRole;
use App\Enums\AiKnowledgeScope;
use App\Models\AiAttachment;
use App\Models\AiChatMessage;
use App\Models\AiSession;
use App\Models\Board;
use App\Services\AI\AiCapabilityGuard;
use App\Services\AI\AiConfigurationResolver;
use App\Services\AI\AiContextScope;
use App\Services\AI\AiSessionManager;
use App\Services\AI\Attachments\AiAttachmentContext;
use App\Services\AI\Attachments\AiAttachmentPipeline;
use App\Services\AI\Exceptions\AiProviderException;
use App\Services\AI\Voice\VoiceProviderInterface;
use App\Services\AI\WorkspaceChatService;
use App\Services\BoardAccess;
use App\Services\ContentRenderer;
use App\Services\TicketFinder;
use App\Support\AiConfiguration;
use App\Support\RichResponse\ChartSpec;
use App\Support\RichResponse\RichBlock;
use App\Support\RichResponse\RichResponseParser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Everything two AI surfaces do identically.
 *
 * There are two: the global panel that lives in the layout, and the full-page
 * board chat that predates it and keeps its route. They differ in where they
 * are rendered and in how they decide their scope — nothing else. Asking,
 * streaming, confirming, discarding, clearing, and choosing what the
 * conversation is for are the same operations, so they are defined once here
 * rather than twice.
 *
 * The mode owns the model
 * -----------------------
 * There is no model picker. A conversation is set to Reading, Writing or
 * Everything — see App\Enums\AiChatMode — and the model follows from that
 * through AiSessionManager::useChatMode(). Offering both would be offering two
 * controls that can contradict each other, and the one somebody actually wants
 * to think about is what the assistant is for, not which model answers.
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
 *
 * The session is held as a uuid, never as a model
 * -----------------------------------------------
 * `sessionUuid` is a public property, which means the browser can rewrite it —
 * so it is re-resolved through AiSessionManager on every request, against
 * ownership and against the current scope. A value that does not resolve
 * silently becomes "start a new session" rather than an error: the failure
 * cases are a revoked board membership and a stale value, and neither should
 * break the panel it is rendered in.
 *
 * Attachments belong to the session, not to the component
 * -------------------------------------------------------
 * `uploads` is the only file-shaped public property, and it is transient: it
 * holds Livewire's temporary files for the length of one request and is reset
 * as soon as they are stored. The durable list is read back from the database
 * on every render, through the session, so the composer's contents survive a
 * page navigation, a refresh, and switching to another conversation and back.
 *
 * Nothing here validates a file type. App\Services\AI\Attachments\
 * AiAttachmentPipeline does, against the extension *and* the detected bytes,
 * and throws prose this component shows verbatim — so the rule is enforced in
 * the service where a future caller cannot skip it, and stated once.
 *
 * Nothing here decides what the AI may do
 * ---------------------------------------
 * The mode is narrowed here as a courtesy so the screen tells the truth, but
 * the decision is made again where it counts: the session's limits are enforced
 * by AiSessionManager before the provider is called, and the capability mode is
 * applied by AiCapabilityGuard inside WorkspaceChatService — which asks it
 * before it sends a single tool definition — and again in ExecuteChatAction
 * before it authorizes a write. A component that skipped every check in this
 * file would change what is rendered and nothing about what the model is given.
 */
trait TalksToWorkspaceAi
{
    use WithFileUploads;

    public string $draft = '';

    /**
     * Files chosen but not yet stored.
     *
     * Livewire's temporary uploads, held for one request. updatedUploads()
     * stores them and resets this immediately, so the property is empty
     * whenever the component is idle — which matters because a temporary upload
     * that is never claimed is a file on a disk with nothing pointing at it.
     *
     * @var array<int, TemporaryUploadedFile>
     */
    public array $uploads = [];

    /**
     * Why the last upload was refused.
     *
     * Kept apart from `aiError`, which is about asking a question. An upload
     * that failed should not be cleared by the next answer arriving, and a
     * failed answer should not blank the explanation of why a file was
     * rejected.
     */
    public ?string $uploadError = null;

    /**
     * Which conversation is on screen. Browser-writable, never trusted.
     */
    public ?string $sessionUuid = null;

    /**
     * What this conversation is for: reading, writing, or everything.
     *
     * Browser-writable and therefore never trusted. It is coerced through
     * App\Enums\AiChatMode on every read and only ever applied through
     * AiSessionManager::useChatMode(), and it is a *request* rather than a
     * grant: what the model is actually offered is this intersected with
     * AiCapabilityGuard's answer, in WorkspaceChatService.
     *
     * The model follows from it and is not separately selectable. That is the
     * point of the setting — "writing runs on the deep-reasoning model" has to
     * be a property of the system, not of a second dropdown somebody can put
     * out of step with the first.
     */
    public string $chatMode = AiChatMode::READING;

    /**
     * May this conversation reach outside the project? The "Outside Project"
     * checkbox.
     *
     * Bound with wire:model.live rather than driven by an action, which is a
     * deliberate departure from the two selectors around it — and the reason is
     * that the objection to binding does not apply here. `selectChatMode` and
     * `selectScope` are actions because their values need CHECKING: a
     * bound property is assigned from the browser before any hook could refuse
     * it, so a tampered <option> would already have been accepted. This value
     * needs no checking. Both settings are permitted for everybody, a customer
     * included, because the scope is not an authorization setting — see
     * App\Enums\AiKnowledgeScope.
     *
     * What it does need is to be WRITTEN somewhere durable, and that is what
     * updatedOutsideProject() is for. The property is the browser's opinion;
     * the session column is the fact. session() re-reads the column into this
     * property on every render, so a value that was refused — toggled while a
     * question was in flight — is corrected on the way back rather than left
     * showing something the next question would not honour. That is exactly how
     * `chatMode` behaves, and a checkbox that keeps its keyboard focus is worth
     * the difference in mechanism.
     */
    public bool $outsideProject = false;

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

    /**
     * Was the last question refused because the conversation is full?
     *
     * The remedy for that is a new session, and a new session no longer has a
     * button of its own — the session bar was removed when the mode picker
     * replaced it. So the error itself offers one, which is where somebody is
     * actually looking when they hit the ceiling. Kept apart from `aiError`
     * because it decides whether a button renders, not what it says.
     */
    public bool $sessionExhausted = false;

    public ?int $confirmingMessageId = null;

    /**
     * What somebody has typed into the box that confirms a deletion.
     *
     * Only ever consulted for an action AiActionType::isDestructive() answers
     * true for, and it has to match the ticket's key. This is consent rather
     * than authorization — TicketPolicy::delete is still what decides — and it
     * exists because the brief is explicit that no phrase may serve as
     * confirmation for an irreversible change. A phrase is something the model
     * can produce; a key typed into a box is not.
     */
    public string $deleteConfirmation = '';

    /**
     * Which chart type each chart in the transcript is currently shown as.
     *
     * Keyed "<message id>.<block index>", holding a type from
     * ChartSpec::TYPES. This is the whole of "Change View": pressing Pie stores
     * `pie` here, the component re-renders, and the server draws the *same*
     * validated numbers a different way. No second question is asked of the
     * model, and no new data is fetched.
     *
     * Browser-writable, like every public property, and it does not need to be
     * trusted. The worst a rewritten payload achieves is a chart drawn as a
     * type this data does not suit, and ChartSpec::withType() already refuses
     * those — it returns the spec unchanged rather than a picture that
     * misrepresents the figures. So this property cannot be used to see
     * anything, only to redraw what is already on screen.
     *
     * Kept on the component rather than persisted, so it lives as long as the
     * conversation is open and a fresh visit shows what the model chose.
     *
     * @var array<string, string>
     */
    public array $chartViews = [];

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
    // Sessions
    // -----------------------------------------------------------------

    /**
     * The conversation this request is about.
     *
     * Resolves the remembered uuid within the current scope, and falls back to
     * the scope's most recent open session — creating one if there is none. The
     * uuid is written back so a session created here is remembered, and so a
     * value that no longer resolves is not remembered any longer.
     */
    protected function session(): AiSession
    {
        $sessions = app(AiSessionManager::class);
        $scope = $this->contextScope();
        $user = auth()->user();

        $session = $sessions->find($scope, $user, $this->sessionUuid)
            ?? $sessions->current($scope, $user);

        $this->sessionUuid = $session->uuid;

        /*
         * The picker shows what the conversation is actually set to.
         *
         * Read back from the session rather than from the property, so a
         * tampered value, a session resumed in another tab and a mode that was
         * narrowed for a customer all render as what the next question would
         * really do — never as what the browser asked for.
         */
        $this->chatMode = AiChatMode::coerce($session->chat_mode?->value)->value;

        /*
         * And the same for the knowledge scope, for the same reason.
         *
         * This is what makes the checkbox self-healing. The property is
         * browser-writable and its `updated` hook can refuse to persist a
         * change — mid-question, most obviously — so reading it back from the
         * stored column here means the box on screen always shows what the
         * NEXT question would actually do, never what a browser asked for and
         * did not get.
         */
        $this->outsideProject = AiKnowledgeScope::coerce($session->knowledge_scope?->value)->isOutside();

        return $session;
    }

    /**
     * Begin a fresh conversation in the current scope.
     *
     * The remedy for a context that has grown too large or has spent its token
     * allowance, which is why the composer offers it inline the moment a
     * question is refused for either reason. The previous session is ended
     * rather than deleted by AiSessionManager, so nothing is lost.
     *
     * The new session inherits the current setting rather than resetting to
     * Reading: somebody who has been drafting tickets and hits the ceiling
     * wants to carry on drafting tickets.
     *
     * The knowledge scope does NOT come with it, and the difference is
     * intentional. Carrying "may reach outside this project" into a
     * conversation nobody has asked that of is a widening that nothing on
     * screen would announce, so a new session starts project-only and the
     * checkbox comes back unticked. Somebody who wants it again ticks it
     * again, which costs one click and is the click that makes it their
     * choice. See AiKnowledgeScope and AiSessionManager::start().
     */
    public function startNewSession(AiSessionManager $sessions): void
    {
        if (! $this->requireAccess()) {
            return;
        }

        if ($this->sending) {
            return;
        }

        $session = $sessions->start(
            $this->contextScope(),
            auth()->user(),
            $this->effectiveChatMode(),
        );

        $this->sessionUuid = $session->uuid;

        $this->resetConversationState();
    }

    /**
     * Choose what this conversation is for.
     *
     * An action rather than a bound property, for the reason the scope selector
     * is one: a bound property is assigned from the browser *before* any hook
     * could check it, so validation would be inspecting a value that has
     * already been accepted. An action can refuse one.
     *
     * Refusal is quiet and narrows rather than errors. A value the enum does
     * not know becomes Reading; a write mode asked for by somebody who may not
     * propose changes becomes Reading too, and says why — because being told
     * "this workspace is set to AI Observer" is useful and being silently left
     * on a setting that does nothing is not.
     */
    public function selectChatMode(string $mode, AiSessionManager $sessions): void
    {
        if (! $this->requireAccess()) {
            return;
        }

        if ($this->sending) {
            return;
        }

        $requested = AiChatMode::coerce($mode);
        $granted = $this->narrowChatMode($requested);

        $session = $sessions->useChatMode($this->session(), $granted);

        $this->chatMode = $session->chat_mode?->value ?? AiChatMode::READING;

        $this->aiError = $granted === $requested ? null : $this->chatModeRefusal();
    }

    /**
     * Persist the "Outside Project" checkbox.
     *
     * Runs after Livewire has assigned the property, which is why the first
     * thing it does is establish whether the assignment may stand. Three
     * outcomes:
     *
     *   no access    reverted and dropped. The panel is about to close down to
     *                its empty state anyway.
     *   mid-question refused. This is edge case 6 from the brief: somebody
     *                toggles the box while an answer is streaming. Honouring it
     *                would mean the turn already in flight ran under one scope
     *                while the screen claimed another, and the transcript would
     *                record a setting the answer was not produced under. So the
     *                request is dropped, the property is put back, and the
     *                composer — already disabled while sending — simply does
     *                not appear to have changed.
     *   otherwise    written to the session, which is the only durable record
     *                and the only thing WorkspaceChatService reads.
     *
     * There is no authorization branch here and none is missing. Both values
     * are permitted for every role; the scope decides where knowledge comes
     * from, not what may be read or changed.
     */
    public function updatedOutsideProject(): void
    {
        /*
         * Read the request before anything else touches the property.
         *
         * session() re-syncs `outsideProject` from the stored column as part
         * of resolving — that is what makes the checkbox self-healing — so
         * reading the property after calling it would read the OLD value back
         * and persist that. Capturing it here is what makes the order of the
         * two lines below irrelevant.
         */
        $requested = AiKnowledgeScope::fromCheckbox($this->outsideProject);

        if (! $this->requireAccess()) {
            $this->outsideProject = false;

            return;
        }

        if ($this->sending) {
            // Put back to whatever the session says, which session() does as
            // it resolves — so the box returns to the truth rather than to a
            // guess about what it was before the click.
            $this->session();

            return;
        }

        $session = app(AiSessionManager::class)->useKnowledgeScope(
            $this->session(),
            $requested,
        );

        $this->outsideProject = AiKnowledgeScope::coerce($session->knowledge_scope?->value)->isOutside();

        /*
         * A stale refusal from the previous turn is cleared, because changing
         * the scope is one of the things that can answer it: "Outside Project
         * mode is currently disabled" is an assistant refusal somebody has
         * just acted on, and leaving it on screen beside a now-checked box
         * reads as the change not having worked.
         */
        $this->aiError = null;
    }

    /**
     * Where the next question would be allowed to source its answer from.
     *
     * The property is what the browser holds; this is the enum the rest of the
     * application thinks in. Read by anything that renders, exactly as
     * effectiveChatMode() is — and, like it, a courtesy rather than the
     * control: WorkspaceChatService reads the session column itself.
     */
    protected function effectiveKnowledgeScope(): AiKnowledgeScope
    {
        return AiKnowledgeScope::fromCheckbox($this->outsideProject);
    }

    /**
     * The mode the next question would actually run in.
     *
     * The property is what the browser asked for; this is what it comes to
     * after the enum and the capability guard have had their say. Everything
     * that renders or acts reads this rather than the property.
     */
    protected function effectiveChatMode(): AiChatMode
    {
        return $this->narrowChatMode(AiChatMode::coerce($this->chatMode));
    }

    /**
     * Which modes this person may choose here.
     *
     * Reading always. The write modes only where a proposal could actually be
     * made — which is a customer's answer in every capability mode, and
     * everybody's answer under AI Observer. The picker renders the rest as
     * disabled rather than hiding them, so the setting is discoverable and the
     * reason it is unavailable can be said out loud.
     *
     * @return array<string, bool> mode value => selectable
     */
    protected function chatModeOptions(): array
    {
        $canPropose = $this->allowsProposals();

        $options = [];

        foreach (AiChatMode::cases() as $case) {
            $options[$case->value] = ! $case->allowsWriteTools() || $canPropose;
        }

        return $options;
    }

    /**
     * Why a write mode is not on offer, in words the person can act on.
     *
     * Null when it is on offer. Two different reasons, and they matter to
     * different people: a customer is told what the assistant is for, while a
     * member of staff is told which setting an administrator would change.
     */
    protected function chatModeRefusal(): ?string
    {
        if ($this->allowsProposals()) {
            return null;
        }

        $board = $this->contextScope()->board;

        if (! app(BoardAccess::class)->canSeeInternalContent(auth()->user())) {
            return 'This assistant answers questions about what has been shared with you. It cannot draft changes.';
        }

        return 'The AI is set to '.app(AiCapabilityGuard::class)->mode($board)->label()
            .' here, so it can read and answer but not draft changes. An administrator can raise '
            .'the mode in the global AI settings.';
    }

    /**
     * A requested mode, reduced to what is permitted.
     *
     * The one place the two ceilings meet on this side of the wire. It is a
     * courtesy rather than the control: WorkspaceChatService asks
     * AiCapabilityGuard again before it sends a single tool, so a component
     * that skipped this would change what the screen says and nothing about
     * what the model is given.
     */
    private function narrowChatMode(AiChatMode $mode): AiChatMode
    {
        return $mode->allowsWriteTools() && ! $this->allowsProposals()
            ? AiChatMode::Reading
            : $mode;
    }

    /**
     * May a proposal be made at all in this context, by this person?
     */
    private function allowsProposals(): bool
    {
        return app(AiCapabilityGuard::class)
            ->allowsProposals($this->contextScope()->board, auth()->user());
    }

    /**
     * What a new question in this context would be subject to.
     */
    protected function configuration(): AiConfiguration
    {
        return app(AiConfigurationResolver::class)->forBoard($this->contextScope()->board);
    }

    private function resetConversationState(): void
    {
        $this->confirmingMessageId = null;
        $this->aiError = null;
        $this->sessionExhausted = false;
        $this->uploadError = null;
        $this->streamingAnswer = '';

        /*
         * Back to project-only, unticked.
         *
         * Set here as well as being re-read from the new session by session(),
         * because the two answers must agree and this is the one somebody
         * reading startNewSession() can see. A fresh conversation reaching
         * outside the project because the previous one did is precisely the
         * silent widening AiKnowledgeScope exists to prevent.
         */
        $this->outsideProject = AiKnowledgeScope::default()->isOutside();

        // Not the attachments: they belong to the session, and switching
        // conversations switches which ones are on screen rather than
        // discarding any. Only the transient upload slot is cleared.
        $this->reset('uploads');
    }

    // -----------------------------------------------------------------
    // Attachments
    // -----------------------------------------------------------------

    /**
     * Store the files that were just chosen.
     *
     * Livewire uploads as soon as a file is selected, so this is the hook that
     * fires — there is no separate submit, and drag-and-drop lands here too
     * because the drop zone writes into the same property.
     *
     * Each file is stored independently and its own failure reported, rather
     * than the batch failing together: somebody who drags in a specification, a
     * spreadsheet and a `.dmg` should end up with the first two attached and
     * one clear sentence about the third.
     */
    public function updatedUploads(AiAttachmentPipeline $pipeline): void
    {
        if (! $this->requireAccess()) {
            $this->reset('uploads');

            return;
        }

        $this->uploadError = null;

        /*
         * Size and count first, through validation, because those are the two
         * a browser can be told about cheaply and the two most likely to be
         * hit. Type is not validated here — see the class comment.
         */
        $validator = validator(
            ['uploads' => $this->uploads],
            [
                'uploads' => ['array', 'max:'.max(1, (int) config('ai.attachments.max_per_session', 10))],
                'uploads.*' => ['file', 'max:'.max(1, (int) config('ai.attachments.max_size_kb', 20480))],
            ],
            [],
            ['uploads.*' => 'file']
        );

        if ($validator->fails()) {
            $this->uploadError = (string) $validator->errors()->first();
            $this->reset('uploads');

            return;
        }

        $session = $this->session();
        $user = auth()->user();
        $failures = [];

        foreach ($this->uploads as $upload) {
            if (! $upload instanceof TemporaryUploadedFile) {
                continue;
            }

            try {
                $pipeline->attach($upload, $session, $user);
            } catch (RuntimeException $exception) {
                // The pipeline's message is written for this person; it is
                // shown as-is and never wrapped in a generic sentence.
                $failures[] = $exception->getMessage();
            }
        }

        // Always reset, even on failure. A temporary upload left in the
        // property would be re-stored on the next round trip.
        $this->reset('uploads');

        if ($failures !== []) {
            $this->uploadError = implode(' ', array_unique($failures));
        }
    }

    /**
     * Take a file off the conversation.
     *
     * Scoped to this session before the pipeline is asked, so a swapped id
     * cannot reach a file on another conversation — 404 rather than a policy
     * failure, because a row somebody may not touch should not be
     * distinguishable from one that does not exist.
     */
    public function removeAttachment(int $attachmentId, AiAttachmentPipeline $pipeline): void
    {
        if (! $this->requireAccess()) {
            return;
        }

        $record = AiAttachment::query()
            ->visibleTo(auth()->user())
            ->forSession($this->session())
            ->whereKey($attachmentId)
            ->first();

        abort_unless($record instanceof AiAttachment, 404);

        $this->uploadError = null;

        try {
            $pipeline->detach($record, auth()->user());
        } catch (RuntimeException $exception) {
            $this->uploadError = $exception->getMessage();
        }
    }

    /**
     * Called by the card's poll while anything is still being read.
     *
     * Deliberately empty. Livewire re-renders on any action, and the render
     * re-reads the attachment rows, so the method existing is the whole
     * mechanism — there is nothing for it to do. Polling stops as soon as no
     * row is left in a non-terminal state; see attachmentsSettling().
     */
    public function refreshAttachments(): void
    {
        //
    }

    /**
     * This conversation's files, for the composer.
     *
     * @return Collection<int, AiAttachment>
     */
    protected function attachments(): Collection
    {
        return app(AiAttachmentContext::class)->forSession($this->session(), auth()->user());
    }

    /**
     * Is anything still being read?
     *
     * What the poll is keyed off, so a settled composer makes no requests.
     *
     * @param  Collection<int, AiAttachment>  $attachments
     */
    protected function attachmentsSettling(Collection $attachments): bool
    {
        return $attachments->contains(
            static fn (AiAttachment $attachment): bool => ! $attachment->status->isTerminal()
        );
    }

    /**
     * Whether files may be attached at all here.
     *
     * Three conditions, and the third is the interesting one: a deployment
     * whose attachment disk is not writable would accept a file and lose it, so
     * the control is hidden rather than offered — the same reasoning as the
     * production durability check in AppServiceProvider, applied to the button.
     */
    protected function canAttach(): bool
    {
        if (! (bool) config('ai.attachments.enabled', true)) {
            return false;
        }

        if (! auth()->user()?->can('manageAttachments', $this->session())) {
            return false;
        }

        return $this->storageIsWritable();
    }

    /**
     * Is the attachment disk usable?
     *
     * Not memoised. A method-level static would be shared for the life of the
     * process rather than the request — wrong under a long-lived worker, and
     * wrong in a test suite, where one component that answered false would
     * make every later one answer false too. The two stat calls below are
     * cached by PHP anyway.
     */
    private function storageIsWritable(): bool
    {
        try {
            $disk = (string) config('attachments.disk', config('filesystems.default'));

            // Existence of the driver is enough for a remote disk; a local one
            // is only usable if its root is actually there.
            $root = (string) config('filesystems.disks.'.$disk.'.root');

            return (string) config('filesystems.disks.'.$disk.'.driver') !== 'local'
                || ($root !== '' && is_dir($root) && is_writable($root));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The turns of a transcript, rendered.
     *
     * Two shapes, because the two roles are different things. A person's
     * question is prose and goes through App\Services\ContentRenderer exactly
     * as it always has. An assistant answer may contain tables and charts, so
     * it is parsed into blocks — but only when it looks like it has any, since
     * parsing every historical turn to discover that almost all of them are
     * prose would be work done for nothing.
     *
     * Both are computed per viewer. That has always been true of ticket
     * references and mentions, and it stays true here: the same stored answer
     * links AQD-42 for somebody who may open it and leaves it as text for
     * anybody who may not.
     *
     * @param  iterable<AiChatMessage>  $messages
     * @return array{rendered: array<int, string>, blocks: array<int, list<RichBlock>>}
     */
    protected function renderTranscript(
        iterable $messages,
        ContentRenderer $renderer,
        ?Board $board,
    ): array {
        $user = auth()->user();
        $parser = app(RichResponseParser::class);

        $rendered = [];
        $blocks = [];

        foreach ($messages as $message) {
            $key = (int) $message->getKey();

            if ($message->role->isAssistant() && $parser->looksRich($message->content)) {
                $blocks[$key] = $parser->parse($message->content, $user, $board);

                continue;
            }

            $rendered[$key] = $renderer->render($message->content, $user, $board, mentionScope: false);
        }

        return ['rendered' => $rendered, 'blocks' => $blocks];
    }

    // -----------------------------------------------------------------
    // Asking
    // -----------------------------------------------------------------

    public function send(WorkspaceChatService $chat, AiSessionManager $sessions): void
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

        $configuration = $this->configuration();

        if (! $configuration->isUsable()) {
            $this->aiError = 'No AI provider is configured for this deployment.';

            return;
        }

        $validated = $this->validate([
            'draft' => ['required', 'string', 'max:8000'],
        ], attributes: ['draft' => 'message']);

        $question = $validated['draft'];

        $session = $this->session();

        /*
         * The token ceilings, enforced before anything is spent.
         *
         * A session that is full is ended as it refuses, and the message says
         * to start a new one — which is the remedy, and is why the limit exists
         * rather than simply capping the bill.
         */
        $refusal = $sessions->refusalFor($session, auth()->user(), $configuration);

        if ($refusal !== null) {
            $this->aiError = $refusal;
            // The refusal names starting a new conversation as the remedy, so
            // the composer offers the button that does it. See the property.
            $this->sessionExhausted = true;

            return;
        }

        $this->aiError = null;
        $this->sessionExhausted = false;
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

            $answer = $chat->askStreamed(
                $session,
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

            // The one new step: a reversible change somebody just asked for,
            // in a workspace that trusts its AI to act, happens now rather
            // than waiting to be asked for a second time.
            $this->carryOut($answer);
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

    /**
     * Ask a question that was spoken rather than typed.
     *
     * The whole of the voice feature's server side, and it is four lines
     * because that is the design: the browser has already turned speech into
     * text through App\Http\Controllers\AiVoiceController, and from here on a
     * spoken question is an ordinary question. It goes through the same
     * validation, the same session, the same token ceilings, the same
     * capability mode, the same tools and the same audit trail as a typed one.
     *
     * The returned id is what lets the browser play the answer back: it fetches
     * that turn's audio from the speak endpoint, which re-authorizes the turn
     * against the person asking. Nothing about the answer is passed to the
     * browser here for it to have read aloud — the browser is told *which* turn
     * to ask about, not what it said, so it cannot have the assistant speak
     * words the assistant never produced.
     *
     * Returns null when the question was refused (a full session, no provider,
     * a validation failure), in which case the panel shows the error it always
     * shows and nothing is spoken.
     */
    public function sendSpoken(
        string $text,
        WorkspaceChatService $chat,
        AiSessionManager $sessions,
    ): ?int {
        if (! $this->requireAccess()) {
            return null;
        }

        // Trimmed and capped before it becomes the draft, so a transcript is
        // subject to exactly the same rule as typed input. `send()` validates
        // it again, which is where the error message comes from.
        $this->draft = mb_substr(trim($text), 0, 8000);

        if ($this->draft === '') {
            return null;
        }

        $this->send($chat, $sessions);

        if ($this->aiError !== null) {
            return null;
        }

        return $this->lastAnswerId();
    }

    /**
     * The newest answer in this conversation, if there is one.
     *
     * Read back from the database rather than returned by the exchange,
     * because `send()` is shared with the typed path and its return value is
     * part of Livewire's contract with the form. One indexed lookup on a
     * conversation is cheaper than restructuring that.
     */
    protected function lastAnswerId(): ?int
    {
        $answer = AiChatMessage::query()
            ->visibleTo(auth()->user())
            ->ownedBy(auth()->user())
            ->where('ai_chat_messages.ai_session_id', $this->session()->getKey())
            ->where('ai_chat_messages.role', AiChatRole::Assistant->value)
            ->orderByDesc('ai_chat_messages.id')
            ->first(['ai_chat_messages.id']);

        return $answer === null ? null : (int) $answer->getKey();
    }

    /**
     * Whether spoken conversation is available, and why not when it is not.
     *
     * Resolved per render rather than cached, because the answer changes the
     * moment an administrator adds a key — and because a panel that had to be
     * reloaded to notice would send somebody looking for a bug.
     *
     * @return array{available: bool, reason: ?string, voices: array<string, string>}
     */
    protected function voiceStatus(): array
    {
        $voice = app(VoiceProviderInterface::class);

        return [
            'available' => $voice->isConfigured(),
            'reason' => $voice->unavailableReason(),
            'voices' => $voice->voices(),
        ];
    }

    /**
     * What the answer slot shows before the first fragment arrives.
     *
     * Our own markup, not the model's, which is why it may contain HTML at
     * all: every streamed fragment of model prose goes through e() first —
     * see send(). The classes are defined once in resources/css/app.css and
     * resolve to a static tint under prefers-reduced-motion.
     */
    private function thinkingPlaceholder(): string
    {
        return '<span class="nx-thinking">'
            .'<span class="nx-thinking-dot"></span>'
            .'<span class="nx-thinking-label">Thinking…</span>'
            .'</span>';
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

        // Cleared rather than carried over, so a key typed for one deletion is
        // never sitting in the box when a second one is opened.
        $this->deleteConfirmation = '';
    }

    public function cancelConfirming(): void
    {
        $this->confirmingMessageId = null;
        $this->deleteConfirmation = '';
    }

    // -----------------------------------------------------------------
    // Charts
    // -----------------------------------------------------------------

    /**
     * Draw one chart in the transcript a different way.
     *
     * "Change View", and it is a server round trip on purpose. The alternative
     * — shipping every variant to the browser and toggling them, or handing the
     * numbers to a client-side charting library — would mean either eight SVGs
     * per chart or the one thing this feature is built to avoid: model-derived
     * data reaching a library whose options can hold callbacks. Re-rendering
     * server-side keeps the drawing where it already is.
     *
     * Nothing here reads or re-fetches data. The chart is re-parsed from the
     * *stored answer*, so the numbers cannot change between views — which is
     * the property that makes the data table and the CSV still correct after
     * somebody has pressed Pie. And because it re-parses rather than trusting a
     * payload, a request naming a message this person cannot reach resolves to
     * nothing.
     *
     * The type is validated against the fixed list here and validated again by
     * ChartSpec::withType(), which additionally refuses a type this particular
     * dataset cannot honestly be drawn as. Two checks rather than one because
     * they answer different questions: is this a chart type at all, and is it a
     * chart type for *these numbers*.
     */
    public function useChartView(int $messageId, int $block, string $type): void
    {
        if (! $this->requireAccess()) {
            return;
        }

        if (! in_array($type, ChartSpec::TYPES, true)) {
            return;
        }

        // The scoped accessor. A message id from the browser cannot reach a
        // colleague's conversation or a board this person is not on; a miss
        // 404s exactly as it does for a proposal.
        $message = $this->message($messageId);

        if (! $message->role->isAssistant()) {
            return;
        }

        // That there IS a chart at this index, read from the stored text rather
        // than assumed from the request. A prose block has no view to change.
        $parsed = app(RichResponseParser::class)
            ->block($message->content, auth()->user(), $block, $message->board);

        if (! $parsed instanceof RichBlock || ! $parsed->isChart()) {
            return;
        }

        $this->chartViews[$messageId.'.'.$block] = $type;
    }

    /**
     * Carry out a change straight away, when that is what the workspace does.
     *
     * The point where the brief's "execute the action when the user has
     * explicitly requested it" is honoured, and the reason it is *here* rather
     * than inside WorkspaceChatService is that this is the surface both a typed
     * and a spoken question arrive at. `send()` calls it, and `sendSpoken()`
     * calls `send()`, so voice inherits the behaviour instead of repeating it —
     * the same reason the rest of the voice path is four lines.
     *
     * Every decision it makes is asked of ExecuteChatAction rather than taken
     * here: whether this action runs unattended, which board it lands on, and
     * whether this person may do it. A destructive action always answers false
     * and falls through to the confirmation gate below.
     *
     * A refusal is written onto the message and reported in the panel, never
     * swallowed. That is what keeps §16 honest: if the write failed, the
     * transcript says so next to the model's own sentence claiming it worked,
     * and the model's claim is the thing the person should not have to trust.
     */
    private function carryOut(AiChatMessage $answer): void
    {
        if (! $answer->awaitsConfirmation()) {
            return;
        }

        $execute = app(ExecuteChatAction::class);
        $actor = auth()->user();

        $board = $execute->boardFor($answer, $actor);

        if (! $board instanceof Board || ! $execute->executesWithoutConfirmation($answer, $board)) {
            // Left as a proposal with its Confirm button, which is the correct
            // outcome for an AI Operator workspace, for a deletion, and for an
            // action whose board could not be worked out.
            return;
        }

        try {
            $result = $execute->handle($board, $answer, $actor);
        } catch (AuthorizationException|RuntimeException $exception) {
            /*
             * Reported, not hidden.
             *
             * ExecuteChatAction has already marked the message failed with this
             * message, so the transcript carries the refusal. Surfacing it in
             * the panel as well is deliberate: the model's answer above it may
             * well read "Done — I moved NL-18 to Done", and the person needs to
             * see that it did not happen without having to notice a small
             * coloured box.
             */
            $this->aiError = $exception->getMessage() !== ''
                ? $exception->getMessage()
                : 'That change could not be made.';

            return;
        }

        session()->flash('status', $result['label'].'.');
    }

    /**
     * Carry out a proposed action.
     *
     * Authorization happens three times and they are three different questions:
     * access to the assistant asks whether this person may be talking to it at
     * all; the capability mode asks whether the AI may change anything here;
     * and ExecuteChatAction then authorizes the actual write — `create` on a
     * ticket, `update` on a page — against the same abilities the ordinary
     * screens use. Being allowed to chat grants nothing, and neither does the
     * mode.
     */
    public function confirm(ExecuteChatAction $execute): void
    {
        if (! $this->requireAccess()) {
            return;
        }

        $message = $this->message((int) $this->confirmingMessageId);

        /*
         * Which board the change lands on.
         *
         * Asked of the action rather than decided here, so the surface and the
         * executor cannot disagree: for a board conversation it is the
         * message's own board, and for the workspace conversation it is the
         * board a create-ticket proposal named, resolved against this person's
         * membership. Anything else is null and nothing happens.
         */
        $board = $execute->boardFor($message, auth()->user());

        if (! $board instanceof Board) {
            $this->confirmingMessageId = null;
            $this->aiError = 'That change has no board to be made on. Say which board it belongs to, '
                .'or pick one in the context selector, and ask again.';

            return;
        }

        /*
         * The destructive gate: the ticket's own key, typed by hand.
         *
         * §15 of the brief — "never allow the AI to interpret vague phrases as
         * confirmation" — and the way to guarantee that is to make the
         * confirmation something the model cannot produce and a stray click
         * cannot supply. "Yes", "do it" and "go ahead" are all phrases; NL-18
         * typed into a box is an act.
         *
         * It is checked here, on the surface, because this is where a person
         * is. ExecuteChatAction does not know about it and does not need to:
         * TicketPolicy::delete is the authorization, and this is consent.
         */
        if ($message->actionType()?->isDestructive() === true) {
            $expected = $this->destructiveConfirmationKey($message, $board);

            if ($expected !== null && strcasecmp(trim($this->deleteConfirmation), $expected) !== 0) {
                $this->aiError = 'Type '.$expected.' exactly to confirm that deletion. Nothing has been deleted.';

                return;
            }
        }

        try {
            $result = $execute->handle($board, $message, auth()->user());
        } catch (AuthorizationException $exception) {
            $this->confirmingMessageId = null;
            // The action's own words when it has them: a mode refusal explains
            // which mode is in force and who can change it, which is far more
            // useful than "not allowed".
            $this->aiError = $exception->getMessage() !== ''
                ? $exception->getMessage()
                : 'You are not allowed to make that change.';

            return;
        } catch (RuntimeException $exception) {
            $this->confirmingMessageId = null;
            $this->aiError = $exception->getMessage();

            return;
        }

        $this->confirmingMessageId = null;
        $this->deleteConfirmation = '';
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
        $this->deleteConfirmation = '';
    }

    /**
     * The exact text that confirms a destructive action, or null if there is none.
     *
     * The ticket's key — "NL-18" — read from the ticket itself rather than
     * assembled from the proposal's arguments. That distinction is the whole
     * value of the gate: if the key came from the model's own input, a proposal
     * could name a ticket it is not about and the person would be typing a
     * confirmation for the wrong row. Reading it from the resolved ticket means
     * what somebody types is what will actually be deleted.
     *
     * Resolved through TicketFinder with them as the viewer, so a proposal
     * naming a ticket they cannot see yields null and the deletion goes on to
     * fail in ExecuteChatAction for the ordinary reason.
     */
    /**
     * The key to type for the deletion currently being confirmed, if any.
     *
     * Read by the view, so the box can say "type NL-18" rather than "type the
     * ticket's key" — a confirmation somebody has to go and look up is a
     * confirmation they will get wrong twice and then stop reading. Null when
     * nothing destructive is being confirmed, which is what hides the box.
     */
    public function pendingDeletionKey(): ?string
    {
        if ($this->confirmingMessageId === null) {
            return null;
        }

        // The scoped accessor, so this cannot be pointed at somebody else's
        // conversation by rewriting the public property it reads.
        $message = $this->message($this->confirmingMessageId);

        if ($message->actionType()?->isDestructive() !== true) {
            return null;
        }

        $board = app(ExecuteChatAction::class)->boardFor($message, auth()->user());

        return $board instanceof Board
            ? $this->destructiveConfirmationKey($message, $board)
            : null;
    }

    public function destructiveConfirmationKey(AiChatMessage $message, Board $board): ?string
    {
        $number = (int) ($message->actionInput()['number'] ?? 0);

        if ($number < 1) {
            return null;
        }

        $ticket = app(TicketFinder::class)
            ->query($board, auth()->user())
            ->where('tickets.number', $number)
            ->first();

        return $ticket?->key();
    }

    /**
     * Delete the turns of the conversation on screen.
     *
     * No longer gated on configuring the board: the transcript is per person,
     * so there is nobody else's history to destroy. The session itself and its
     * usage ledger survive — see WorkspaceChatService::clear().
     */
    public function clearHistory(WorkspaceChatService $chat): void
    {
        if (! $this->requireAccess()) {
            return;
        }

        $chat->clear($this->session(), auth()->user());

        $this->confirmingMessageId = null;
        $this->aiError = null;
        $this->streamingAnswer = '';
    }

    /**
     * Resolve a message id supplied by the browser.
     *
     * Four filters, and each closes a different door. `visibleTo` and
     * `ownedBy` mean a swapped id cannot reach a board this person is not on or
     * somebody else's conversation. The session filter means it cannot reach
     * *their own* other conversation either: a proposal drafted in one session
     * must not be confirmable from another, and since a session is bound to one
     * scope this also means a proposal against board A cannot be confirmed
     * while the assistant is pointed at board B — the change would land on the
     * wrong board. It 404s instead of resolving.
     */
    private function message(int $messageId): AiChatMessage
    {
        $message = AiChatMessage::query()
            ->visibleTo(auth()->user())
            ->ownedBy(auth()->user())
            ->where('ai_chat_messages.ai_session_id', $this->session()->getKey())
            ->whereKey($messageId)
            ->first();

        abort_unless($message instanceof AiChatMessage, 404);

        return $message;
    }
}
