<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Actions\AI\Exceptions\AiRunRefused;
use App\Actions\Comments\PostComment;
use App\Actions\Docs\CreatePage;
use App\Actions\Docs\UpdatePage;
use App\Actions\Tickets\AttachFilesToTicket;
use App\Actions\Tickets\CreateTicket;
use App\Actions\Tickets\DeleteTicket;
use App\Actions\Tickets\MoveTicket;
use App\Actions\Tickets\MoveTicketToTab;
use App\Actions\Tickets\SyncTicketLabels;
use App\Actions\Tickets\UpdateTicket;
use App\Enums\AiActionType;
use App\Enums\AiRunMode;
use App\Enums\AiRunTrigger;
use App\Enums\CommentStream;
use App\Enums\TicketPriority;
use App\Enums\TicketType;
use App\Models\AiAttachment;
use App\Models\AiChatMessage;
use App\Models\AiSession;
use App\Models\AiRun;
use App\Models\AiToolInvocation;
use App\Models\Attachment;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\BoardTab;
use App\Models\Comment;
use App\Models\DocPage;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AI\AiCapabilityGuard;
use App\Services\AI\Audit\AiAuditLogger;
use App\Services\AI\ProposalTargets;
use App\Services\AI\WorkspaceChatService;
use App\Services\AttachmentStorage;
use App\Services\BoardAccess;
use App\Services\BoardBroadcaster;
use App\Services\DocPageFinder;
use App\Services\Docs\Import\DocumentImporter;
use App\Services\Docs\Import\DocumentImportException;
use App\Services\TicketFinder;
use App\Support\TicketPlace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Throwable;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Carry out an action the assistant was asked for.
 *
 * This is the only place a chat action becomes a database change. Two flows
 * reach it and they differ in exactly one step:
 *
 *   the model proposes → the workspace previews → a person confirms → here;
 *   the model proposes → here, immediately.
 *
 * The second is for reversible changes in a workspace set to AI Agent, where
 * asking somebody to confirm what they just asked for out loud is friction
 * rather than safety. Which flow applies is one question answered in one place
 * — executesWithoutConfirmation() — and it changes *when* this class is called,
 * never what it permits. Every check below runs identically on both paths.
 *
 * Five properties hold, and each is a separate deliberate choice.
 *
 * The proposal is not trusted, only read
 * --------------------------------------
 * Everything is re-resolved from the confirmed message at the moment of
 * execution: the ticket by its number *within this board*, the page by its slug
 * *within this board*, both through the readers that apply visibility. A
 * proposal naming ticket 999 on another board resolves to nothing and is
 * refused. The model's output is treated as a form submission from an untrusted
 * client, which is exactly what it is.
 *
 * Authorization is the real one
 * -----------------------------
 * Gate checks against the confirming user, using the same abilities the ordinary
 * screens use — TicketPolicy::create, TicketPolicy::update, DocPagePolicy::*.
 * Nothing is granted because "the AI suggested it": a person who cannot create a
 * ticket by hand cannot create one by confirming a proposal.
 *
 * Authorization is per field, and it happens first
 * ------------------------------------------------
 * A request to move a card and re-assign it asks two different permissions —
 * TicketPolicy::move and ::assign — and both are taken before either write
 * happens. So a team member who may move but not assign changes nothing at all
 * and is told why, rather than getting a moved card, a dropped assignee and a
 * message that says something went wrong. Partial success is the outcome the
 * brief calls out by name, and resolving everything before writing anything is
 * how it is avoided.
 *
 * The writes go through the ordinary actions
 * ------------------------------------------
 * CreateTicket, UpdateTicket, MoveTicket, SyncTicketLabels, PostComment,
 * DeleteTicket, CreatePage, UpdatePage. So every rule those own still applies —
 * a new page is internal until somebody publishes it, ticket numbering is
 * allocated under a lock, a move resequences positions and records a
 * TicketMoved event, a customer's comment is forced onto the customer stream —
 * and there is no second, weaker path into the same tables.
 *
 * The surface, and what stays off it
 * ----------------------------------
 * Nine action types. They can raise a ticket or a batch of them, edit one,
 * re-prioritise, re-assign, move it between columns, re-label it, comment on
 * it, delete it, write documentation pages, and import a file the person
 * attached into the documentation together with tickets for its to-dos.
 *
 * A file becomes a page body on the server (DocumentImporter), from the copy
 * prepared when the file was read, so a long document is never retyped by the
 * model — and tickets made from an internal page or an imported file start
 * internal unless the person said customers should see them.
 *
 * Visibility is bounded rather than reachable. A new ticket starts from the
 * board's own default — the same one the New ticket form starts from — and
 * the proposal may only narrow it: `customer_visible: false` makes a ticket
 * internal, and nothing a proposal says can make one more visible than the
 * board allows. An existing ticket's visibility is not reachable at all.
 *
 * Board structure remains deliberately unreachable: creating boards, adding
 * members, adding, renaming, reordering or removing tabs, and adding or
 * renaming columns and labels stay with the screens that govern them, because
 * they change what everybody else sees rather than the state of one piece of
 * work. The assistant works within the shape of a board; it does not reshape
 * it. It can file a ticket in a tab and move one between tabs — that is work,
 * not structure — but only into a tab somebody named.
 */
class ExecuteChatAction
{
    public function __construct(
        private readonly WorkspaceChatService $chat,
        private readonly TicketFinder $tickets,
        private readonly DocPageFinder $pages,
        private readonly CreateTicket $createTicket,
        private readonly UpdateTicket $updateTicket,
        private readonly MoveTicket $moveTicket,
        private readonly MoveTicketToTab $moveTicketToTab,
        private readonly SyncTicketLabels $syncLabels,
        private readonly PostComment $postComment,
        private readonly DeleteTicket $deleteTicket,
        private readonly ProposalTargets $targets,
        private readonly CreatePage $createPage,
        private readonly UpdatePage $updatePage,
        private readonly AiCapabilityGuard $guard,
        private readonly CreateAiRun $createRun,
        private readonly AiAuditLogger $audit,
        private readonly BoardAccess $boards,
        private readonly AttachFilesToTicket $attachFiles,
        private readonly AttachmentStorage $storage,
        private readonly DocumentImporter $importer,
        private readonly BoardBroadcaster $broadcaster,
    ) {}

    /**
     * @return array{label: string, url: ?string}
     *
     * @throws AuthorizationException when the confirming user may not do this
     * @throws RuntimeException when the proposal cannot be resolved
     */
    public function handle(Board $board, AiChatMessage $message, User $actor): array
    {
        /*
         * The capability mode, checked before anything else.
         *
         * WorkspaceChatService does not offer the model write tools under AI
         * Observer, so under normal conditions no proposal exists to confirm.
         * This is the case that does happen: a preview already on screen when
         * an administrator tightens the workspace, and a confirm button that is
         * a separate request. The mode in force is the one at the moment of the
         * write, not the one the proposal was drafted under.
         *
         * It is a ceiling, not a grant. The per-record authorization below
         * still runs in full for every proposal that gets past it.
         *
         * The refusal is audited before it is thrown. This is the one refusal
         * that would otherwise go unrecorded, and it is the most interesting
         * one there is: a customer or an Observer workspace reaching this line
         * at all means a proposal existed that should never have been drafted,
         * which is either a bug or somebody trying. Either way a security
         * review wants the row.
         */
        try {
            $this->guard->assertCanExecuteProposal($board, $actor);
        } catch (AuthorizationException $refusal) {
            if (($type = $message->actionType()) !== null) {
                $this->recordAttempt($type, $message->actionInput(), $message, $actor, $board, null, $refusal);
            }

            throw $refusal;
        }

        /*
         * The board this proposal may act on, decided here rather than taken
         * on trust from the caller.
         *
         * For a board-scoped conversation that is the message's own board and
         * nothing else. For a workspace-scoped one it is the board the proposal
         * named, resolved against this person's membership — see boardFor().
         * Either way the caller's board has to be the same one, so a swapped
         * argument on a crafted request lands nowhere.
         */
        $expected = $this->boardFor($message, $actor);

        if (! $expected instanceof Board || (int) $expected->getKey() !== (int) $board->getKey()) {
            throw new RuntimeException('That proposal belongs to a different board.');
        }

        /*
         * One execution per proposal, even under concurrency.
         *
         * The state check below is what stops a proposal being carried out
         * twice in a row; it cannot stop two requests that check at the same
         * moment — a second tab, a double click on a slow confirm — and for a
         * batch of thirty tickets that would be sixty. So the check runs under
         * a lock, on a freshly read row: whoever gets the lock second finds the
         * proposal already dealt with.
         */
        $lock = Cache::lock('ai-chat-action:'.$message->getKey(), 600);

        if (! $lock->get()) {
            throw new RuntimeException('That change is already being made. Give it a moment.');
        }

        try {
            $message->refresh();

            return $this->execute($board, $message, $actor);
        } finally {
            $lock->release();
        }
    }

    /**
     * Carry out a proposal the caller has already checked the mode and board
     * of, under the lock taken in handle().
     *
     * @return array{label: string, url: ?string}
     */
    private function execute(Board $board, AiChatMessage $message, User $actor): array
    {
        if (! $message->awaitsConfirmation()) {
            throw new RuntimeException('That proposal has already been dealt with.');
        }

        $type = $message->actionType();
        $input = $message->actionInput();

        if ($type === null) {
            throw new RuntimeException('That message does not contain an action to carry out.');
        }

        try {
            $result = $this->perform($type, $board, $input, $actor, $message->session);
        } catch (NotFoundHttpException) {
            // The readers raise 404 when a ticket or page is not visible to this
            // user on this board — the same answer a customer gets for a ticket
            // that is internal. Translated into prose here rather than allowed
            // to become a bare 404 page: the person is mid-conversation, and
            // "that ticket is not on this board" is the useful answer.
            $exception = new RuntimeException(
                'That ticket or page is not on this board, or you are not allowed to see it. Nothing was changed.'
            );

            $this->chat->markAction($message, AiChatMessage::ACTION_FAILED, [
                'error' => $exception->getMessage(),
                'confirmed_by_id' => $actor->getKey(),
            ]);

            // Audited like any other refusal: a proposal naming something the
            // person cannot see is exactly the attempt a review looks for.
            $this->recordAttempt($type, $input, $message, $actor, $board, null, $exception);

            throw $exception;
        } catch (AuthorizationException|RuntimeException $exception) {
            // Recorded on the message so the transcript shows the refusal rather
            // than leaving a confirm button that appears never to have worked.
            $this->chat->markAction($message, AiChatMessage::ACTION_FAILED, [
                'error' => $exception->getMessage(),
                'confirmed_by_id' => $actor->getKey(),
            ]);

            $this->recordAttempt($type, $input, $message, $actor, $board, null, $exception);

            throw $exception;
        }

        $this->chat->markAction($message, AiChatMessage::ACTION_CONFIRMED, [
            'result' => $result,
            'confirmed_by_id' => $actor->getKey(),
        ]);

        $this->recordAttempt($type, $input, $message, $actor, $board, $result, null);

        return $result;
    }

    /**
     * Make one change, with no proposal behind it.
     *
     * The body of every action, shared by the two callers that may make one:
     * a confirmed (or unattended) proposal, through execute() above, and an AI
     * app calling one of the remote MCP server's write tools, through
     * App\Mcp\Tools\Write\WriteTool. Everything that makes a change safe —
     * resolving the ticket or page through the readers that apply visibility,
     * the per-field Gate checks against $actor, the ordinary domain actions —
     * happens in here, so the two paths cannot disagree about what is allowed.
     *
     * What it does NOT do is the caller's half: the capability-mode ceiling,
     * the proposal's state and lock, and the audit row. execute() keeps those
     * for proposals; the MCP write tool has its own (see
     * AiCapabilityGuard::assertCanWriteExternally()).
     *
     * $session is the conversation whose files `attachment_ids` and
     * `import_attachment_id` may name. Without one, naming a file is refused.
     *
     * @param  array<string, mixed>  $input
     * @return array{label: string, url: ?string, target?: string, note?: string, items?: list<array{key: string, title: string, url: string}>, page?: array{title: string, url: string}}
     *
     * @throws AuthorizationException when $actor may not make this change
     * @throws RuntimeException when the change cannot be resolved
     * @throws NotFoundHttpException when the ticket or page is not visible to $actor
     */
    public function perform(AiActionType $type, Board $board, array $input, User $actor, ?AiSession $session = null): array
    {
        // An archived board is read-only for everybody, whoever is asking.
        if ($board->isArchived()) {
            throw new RuntimeException('“'.$board->name.'” is archived, so nothing on it can be changed. Nothing was changed.');
        }

        return match ($type) {
            AiActionType::CreateTicket => $this->doCreateTicket($board, $input, $actor, $session),
            AiActionType::CreateTickets => $this->doCreateTickets($board, $input, $actor, $session),
            AiActionType::UpdateTicket => $this->doUpdateTicket($board, $input, $actor, $session),
            AiActionType::CreateDocPage => $this->doCreatePage($board, $input, $actor, $session),
            AiActionType::UpdateDocPage => $this->doUpdatePage($board, $input, $actor),
            AiActionType::ImportDocument => $this->doImportDocument($board, $input, $actor, $session),
            AiActionType::CodeRun => $this->doCodeRun($board, $input, $actor),
            AiActionType::CommentTicket => $this->doCommentTicket($board, $input, $actor),
            AiActionType::DeleteTicket => $this->doDeleteTicket($board, $input, $actor),
        };
    }

    /**
     * Write the audit row for one attempted change.
     *
     * §17 of the brief — "every AI-generated mutation should be traceable" —
     * and the answer to it is deliberately *two* records rather than a new
     * system, because the two answer different questions:
     *
     *   the ticket's own history, written by CreateTicket, MoveTicket and the
     *   rest through TicketActivity, says what changed and who changed it. It
     *   is what somebody reading the ticket needs, and it already existed.
     *
     *   the row written here says that the assistant is what caused it, in
     *   which conversation, from which turn, with which arguments — which the
     *   ticket timeline cannot say, because as far as it is concerned a person
     *   made the change. Which is true, and is the point.
     *
     * Failures are recorded too, and that is the more valuable half. A refused
     * write is the trace of somebody asking the AI for something it would not
     * do, and a security review wants that far more than it wants the successes.
     *
     * AiAuditLogger swallows its own errors: an audit row that could break a
     * write it is describing would be worse than the missing row.
     *
     * @param  array<string, mixed>  $input
     * @param  array{label: string, url: ?string, note?: string, target?: string}|null  $result
     */
    private function recordAttempt(
        AiActionType $type,
        array $input,
        AiChatMessage $message,
        User $actor,
        Board $board,
        ?array $result,
        ?\Throwable $failure,
    ): void {
        $this->audit->record(
            tool: $type->toolName(),
            category: AiToolInvocation::CATEGORY_ACTION,
            outcome: match (true) {
                $failure === null => AiToolInvocation::OUTCOME_OK,
                $failure instanceof AuthorizationException => AiToolInvocation::OUTCOME_REFUSED,
                default => AiToolInvocation::OUTCOME_ERROR,
            },
            success: $failure === null,
            user: $actor,
            session: $message->session,
            board: $board,
            message: $message,
            target: $result['target'] ?? null,
            input: $input,
            /*
             * Named, because "who" is the question this row exists to answer.
             *
             * The user relation carries the id, but the ledger is read as prose
             * on a settings screen, and "Workspace AI created NL-18 on behalf
             * of Alex Round" is the sentence §17 asks for. Putting the name in
             * the text means a row still says who caused it after the account
             * has been renamed or deactivated.
             */
            note: $actor->name.': '.($failure !== null
                ? $failure->getMessage()
                : ($result['note'] ?? $result['label'] ?? 'done')),
        );
    }

    /**
     * Which board a proposal would be carried out on, if any.
     *
     * One definition, called by the surfaces to decide what to hand `handle()`
     * and by `handle()` itself to check what it was handed. Two copies of this
     * would be two chances to disagree about which board a change lands on,
     * which is the worst kind of bug this file could have.
     *
     * There are two cases and they are not symmetrical:
     *
     *   a board-scoped conversation  the message's own board, always. A
     *                                proposal filed against a board executes
     *                                against that board, and a `board` field in
     *                                its input is ignored rather than obeyed —
     *                                otherwise a model talking about AQD could
     *                                write to NL by naming it.
     *   the workspace conversation   the board the proposal named, for any
     *                                action whose schema carries a `board`
     *                                field. Nothing else can be resolved: an
     *                                action that names no board in the
     *                                workspace scope has no board to act on,
     *                                and the surface says so rather than
     *                                picking one.
     *
     * The second case is not a convenience. The assistant is reachable from
     * every page in the application and its default scope is the whole
     * workspace, so "move NL-18 to Done" — a completely clear request naming
     * the board in the ticket's own key — has to be able to land somewhere. The
     * alternative is telling somebody to go and change a selector before the
     * assistant will do what they plainly asked for.
     *
     * Resolution goes through BoardAccess with the CONFIRMING person as the
     * viewer, so a name they cannot reach resolves to null and the confirmation
     * fails the way a ticket they cannot see does. It is a lookup, not an
     * authorization: TicketPolicy::create, ::update, ::move and the rest still
     * run on whatever comes back, and the ticket itself is then resolved
     * *within* that board by TicketFinder with the same person as viewer. So
     * naming a board widens nothing — it only says where to look.
     */
    public function boardFor(AiChatMessage $message, User $actor): ?Board
    {
        if ($message->board instanceof Board) {
            return $message->board;
        }

        $name = $this->optionalString($message->actionInput(), 'board');

        if ($name === null) {
            return null;
        }

        return $this->resolveBoard($name, $actor);
    }

    /**
     * A board named the way people name one, among the boards this person can
     * reach and that are not archived; null otherwise.
     *
     * Name, slug or ticket prefix, because that is how people refer to a
     * board out loud and the model is repeating what they said. Matched
     * exactly rather than by LIKE: a partial match that picked the wrong
     * board would create somebody's ticket in the wrong project, and being
     * asked to be precise is a far smaller cost.
     */
    public function resolveBoard(string $name, User $actor): ?Board
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        return $this->boards->query($actor)
            ->notArchived()
            ->where(function ($query) use ($name): void {
                $query->where('boards.slug', $name)
                    ->orWhere('boards.name', $name)
                    ->orWhere('boards.ticket_prefix', mb_strtoupper($name));
            })
            ->first();
    }

    /**
     * Dismiss a proposal without doing it.
     */
    public function discard(AiChatMessage $message, User $actor): void
    {
        if (! $message->awaitsConfirmation()) {
            return;
        }

        $this->chat->markAction($message, AiChatMessage::ACTION_DISCARDED, [
            'discarded_by_id' => $actor->getKey(),
        ]);
    }

    // -----------------------------------------------------------------

    /**
     * Start a coding session on a ticket.
     *
     * The only proposal that does not write a row itself. It calls
     * App\Actions\AI\CreateAiRun — the same action the button on the ticket
     * page calls — so every precondition that pipeline owns still applies, in
     * the same order, and none of them is re-implemented here:
     *
     *   AiRunPolicy::create   authorized against the CONFIRMING user, with the
     *                         run mode, so apply mode is held to its own bar;
     *   the capability mode    apply needs AI Agent, checked by CreateAiRun and
     *                         again by ApplyModeRunner before it clones;
     *   the daily cap          a confirmed proposal spends from the same budget
     *                         as a manual run, because it is one;
     *   a repository           apply mode without one is refused before the
     *                         queue, not after a clone.
     *
     * A refusal from any of those is prose, shown in the conversation. That is
     * the useful behaviour: "this board has no repository attached" tells
     * somebody what to do, where a silent failure tells them nothing.
     *
     * @param  array<string, mixed>  $input
     * @return array{label: string, url: ?string}
     */
    private function doCodeRun(Board $board, array $input, User $actor): array
    {
        $reference = $this->requiredString($input, 'ticket', 'The proposal did not say which ticket to work on.');

        // A prefixed key is accepted and then checked against this board: a
        // proposal confirmed here must act on this board, and a key naming
        // another one is a proposal that would land somewhere else.
        $number = $this->ticketNumber($reference, $board);

        if ($number === null) {
            throw new RuntimeException(
                'The proposal named a ticket that is not on this board. Nothing was started.'
            );
        }

        $ticket = $this->tickets->findOrFail($board, $number, $actor);

        $mode = AiRunMode::tryFrom((string) ($input['mode'] ?? '')) ?? AiRunMode::Suggest;

        if (! $mode->startsARun()) {
            $mode = AiRunMode::Suggest;
        }

        // Against the confirming user, with the mode, so apply mode is
        // authorized as apply mode rather than as "an AI run".
        Gate::forUser($actor)->authorize('create', [AiRun::class, $ticket, $mode]);

        try {
            $run = $this->createRun->handle($ticket, $mode, AiRunTrigger::Manual, $actor);
        } catch (AiRunRefused $refused) {
            // The pipeline's own words. It is the only thing that knows which
            // precondition was missing, and its messages name the remedy.
            throw new RuntimeException($refused->getMessage());
        }

        /*
         * The run's id goes into the audit row, via the result.
         *
         * Starting a coding session is the most consequential thing the
         * assistant can cause, so its audit row names the run it started — the
         * ticket timeline records it too, through CreateAiRun, but that row
         * does not say the assistant asked for it. The row itself is written
         * once for every action type by recordAttempt(); this only supplies the
         * detail that is particular to this one.
         */
        return [
            'label' => $mode->writesCode()
                ? 'Started an apply-mode coding session on '.$ticket->key().'. It will open a draft pull request for review; nothing is merged.'
                : 'Started an analysis run on '.$ticket->key().'. It will post an internal note when it finishes.',
            'url' => route('tickets.show', ['board' => $board, 'number' => $ticket->number]),
            'target' => $ticket->key(),
            'note' => 'Started run '.$run->uuid.' in '.$mode->value.' mode.',
        ];
    }

    /**
     * The per-board number a reference names, if it names one on THIS board.
     *
     * Accepts "AQD-42" and "42". A prefixed key belonging to a different board
     * returns null rather than its number, so a proposal cannot be confirmed
     * against the wrong project by quoting the wrong prefix.
     */
    private function ticketNumber(string $reference, Board $board): ?int
    {
        $reference = trim($reference);

        if (preg_match('/^([A-Za-z][A-Za-z0-9]{0,9})-(\d{1,9})$/', $reference, $matches) === 1) {
            return strtoupper($matches[1]) === strtoupper((string) $board->ticket_prefix)
                ? (int) $matches[2]
                : null;
        }

        return ctype_digit($reference) && (int) $reference > 0 ? (int) $reference : null;
    }

    /**
     * Raise a ticket, with everything the person asked for set on it.
     *
     * One call rather than a create followed by three edits, which is what the
     * brief's "create a ticket called X, priority Critical, assigned to Alex, in
     * the Bugs column" needs: the ticket exists once, in the state that was
     * asked for, with one entry in its history rather than four.
     *
     * Authorization is per field and happens BEFORE anything is written. That
     * ordering is the whole reason this reads the way it does — a team member
     * who may create tickets but not assign them should get a refusal and no
     * ticket, not a ticket with the assignee quietly dropped. (UpdateTicket's
     * own sanitiser drops privileged fields for customers as a backstop; this
     * is the layer that explains itself.)
     *
     * @param  array<string, mixed>  $input
     * @return array{label: string, url: ?string}
     */
    private function doCreateTicket(Board $board, array $input, User $actor, ?AiSession $session): array
    {
        $ticket = $this->createPrepared($board, $this->prepareTicket($board, $input, $actor, $session), $actor);

        $files = $ticket['files'];
        $ticket = $ticket['ticket'];

        return [
            'label' => 'Created ticket '.$ticket->key().' — '.$this->summarise($ticket)
                .($files->isNotEmpty() ? ' with '.$this->countFiles($files) : ''),
            'url' => route('tickets.show', ['board' => $board, 'number' => $ticket->number]),
            'target' => $ticket->key(),
        ];
    }

    /**
     * Several new tickets on one board, all or none.
     *
     * The tool behind "break this plan into tickets". Every ticket is first
     * PREPARED — authorized and resolved exactly as a single create would be,
     * writing nothing — and only when all of them have passed is any created.
     * So an unknown column on the seventh ticket means no tickets and a message
     * naming the seventh, rather than six tickets, a refusal, and somebody
     * working out which of their twelve made it.
     *
     * The creates themselves are sequential, each in CreateTicket's own
     * transaction, so the numbers come out in the order the list gave them.
     * There is no outer transaction: CreateTicket's observers broadcast and
     * notify, and a rollback after those had fired would leave customers' boards
     * announcing tickets that do not exist. Preparation is what makes a failure
     * part-way through unlikely; if one happens anyway, the message says
     * exactly which tickets exist and that the rest were not created.
     *
     * @param  array<string, mixed>  $input
     * @return array{label: string, url: ?string, target: string, items: list<array{key: string, title: string, url: string}>}
     */
    private function doCreateTickets(Board $board, array $input, User $actor, ?AiSession $session): array
    {
        $entries = array_values(array_filter(
            is_array($input['tickets'] ?? null) ? $input['tickets'] : [],
            static fn ($entry): bool => is_array($entry),
        ));

        if ($entries === []) {
            throw new RuntimeException('The proposal had no tickets in it.');
        }

        $max = AiActionType::maxTicketsPerReply();

        if (count($entries) > $max) {
            throw new RuntimeException('That is more than '.$max.' tickets for one request. Nothing was created.');
        }

        /*
         * The documentation page the tickets come from, when the model said.
         *
         * Read as the confirming person sees it. An internal page's to-dos are
         * internal work, so its tickets start internal unless the person said
         * customers should see them, and each links back to the page.
         */
        $source = null;

        if (($slug = $this->optionalString($input, 'source_page_slug')) !== null) {
            $source = $this->pages->findOrFail($board, $slug, $actor);
        }

        $prepared = [];

        foreach ($entries as $index => $entry) {
            if ($source instanceof DocPage && ! $source->customer_visible) {
                $entry['customer_visible'] = ($entry['customer_visible'] ?? false) === true;
            }

            try {
                $prepared[] = $this->prepareTicket($board, $entry, $actor, $session);
            } catch (AuthorizationException|RuntimeException $exception) {
                $reason = $exception->getMessage();
                $which = 'Ticket '.($index + 1).$this->quotedTitle($entry).': '.$reason
                    .(str_contains($reason, 'Nothing was') ? '' : ' Nothing was created.');

                // The kind of refusal is kept, so the audit row still says
                // "refused" for a permission and "error" for a bad column.
                throw $exception instanceof AuthorizationException
                    ? new AuthorizationException($which)
                    : new RuntimeException($which);
            }
        }

        $created = [];

        foreach ($prepared as $ticket) {
            if ($source instanceof DocPage) {
                $ticket = $this->linkedToPage($ticket, $source, route('docs.show', ['board' => $board, 'slug' => $source->slug]));
            }

            try {
                $created[] = $this->createPrepared($board, $ticket, $actor)['ticket'];
            } catch (Throwable $exception) {
                $done = $created === []
                    ? 'No tickets were created'
                    : 'Created '.implode(', ', array_map(fn (Ticket $t): string => $t->key(), $created));

                throw new RuntimeException(
                    $done.', then it stopped: '.$exception->getMessage().'. The remaining '
                    .(count($prepared) - count($created)).' were not created.'
                );
            }
        }

        $first = $created[0];
        $last = $created[count($created) - 1];
        $range = count($created) === 1 ? $first->key() : $first->key().' to '.$last->key();
        $visible = count(array_filter($created, fn (Ticket $t): bool => (bool) $t->customer_visible));

        $audience = match (true) {
            $visible === count($created) => 'visible to customers',
            $visible === 0 => 'internal',
            default => $visible.' visible to customers, '.(count($created) - $visible).' internal',
        };

        return [
            'label' => 'Created '.count($created).' '.(count($created) === 1 ? 'ticket' : 'tickets')
                .' on '.$board->name.': '.$range.' ('.$audience.')',
            'url' => route('boards.show', $board),
            'target' => mb_substr($range.' ('.count($created).' tickets)', 0, 190),
            'items' => array_map(fn (Ticket $t): array => [
                'key' => $t->key(),
                'title' => $t->title,
                'url' => route('tickets.show', ['board' => $board, 'number' => $t->number]),
            ], $created),
        ];
    }

    /**
     * Everything a new ticket needs, authorized and resolved, and nothing
     * written yet.
     *
     * Authorization is per field and happens here, BEFORE anything is
     * written, which is what lets a batch refuse as a whole. See the comment
     * on doCreateTicket() for why each field is gated only when asked for.
     *
     * @param  array<string, mixed>  $input
     * @return array{attributes: array<string, mixed>, files: Collection<int, Attachment>}
     */
    private function prepareTicket(Board $board, array $input, User $actor, ?AiSession $session): array
    {
        Gate::forUser($actor)->authorize('create', [Ticket::class, $board]);

        $title = $this->requiredString($input, 'title', 'The proposal had no ticket title.');

        // Resolved before the ticket exists, like labels: an id that is not a
        // file on this conversation means no ticket rather than a ticket
        // missing the screenshot it was raised about.
        $files = $this->attachmentsFrom($session, $input, $actor);

        /*
         * Visibility: the board's default, which the proposal may narrow and
         * never widen.
         *
         * The board's default is what the New ticket form starts from, so a
         * ticket the assistant raises lands where one raised by hand would.
         * `customer_visible: false` — the person said it is internal, or it is
         * plainly staff-only — makes it internal. `true` cannot make a ticket
         * more visible than the board allows, so nothing said in a
         * conversation can be what publishes internal work on a board whose
         * tickets are internal by default.
         */
        $visible = $board->newTicketsVisibleToCustomers()
            && (($input['customer_visible'] ?? true) !== false);

        $attributes = [
            'title' => mb_substr($title, 0, 200),
            'description_md' => $this->optionalString($input, 'description_md'),
            'priority' => $this->priority($input),
            'customer_visible' => $visible,
        ];

        /*
         * The fields somebody had to name for them to be here.
         *
         * Each is gated on the ability the ordinary screen uses for it, and the
         * gate runs only when the field was actually asked for: checking
         * `assign` on every create would refuse a plain "raise a ticket" from
         * somebody who may raise tickets and not assign them, which is a
         * capability they do have.
         */
        if (($type = $this->optionalString($input, 'type')) !== null) {
            $attributes['type'] = TicketType::tryFrom($type) ?? TicketType::Task;
        }

        if (($assignee = $this->optionalString($input, 'assignee')) !== null) {
            Gate::forUser($actor)->authorize('assign', $this->prototype($board, $visible));

            $attributes['assignee_id'] = $this->targets->assignee($board, $assignee, $actor)?->getKey();
        }

        /*
         * Which tab: one of this board's, by name — or, when none is named, the
         * board's first tab, exactly as the New ticket form starts there. The
         * tab on screen is filled in while the proposal is drafted (see
         * App\Services\AI\ProposalTabDefaults), so the card shows it; nothing
         * here guesses. Naming a tab needs no more than `create`: customers may
         * choose a tab too.
         */
        $tab = ($tabName = $this->optionalString($input, 'tab')) !== null
            ? $this->targets->tab($board, $tabName)
            : $board->defaultTab();

        if ($tab instanceof BoardTab) {
            $attributes['board_tab_id'] = $tab->getKey();
        }

        if (($column = $this->optionalString($input, 'column')) !== null) {
            // The same ability a move needs: choosing where a new ticket starts
            // is choosing its status.
            Gate::forUser($actor)->authorize('move', $this->prototype($board, $visible));

            if (! $tab instanceof BoardTab) {
                throw new RuntimeException($board->name.' has no columns, so a ticket cannot be created on it.');
            }

            // A column of that tab — two tabs can both have a "To Do".
            $attributes['board_column_id'] = $this->targets->column($board, $tab, $column)->getKey();
        }

        [$dueGiven, $due] = $this->dueDate($input);

        if ($dueGiven && $due !== null) {
            $attributes['due_date'] = $due;
        }

        if (($estimate = $this->estimate($input)) !== null) {
            $attributes['estimate'] = $estimate;
        }

        $labels = $this->labelNames($input);

        if ($labels !== null) {
            Gate::forUser($actor)->authorize('manageLabels', $this->prototype($board, $visible));

            // Resolved before the ticket exists, so an unknown label name means
            // no ticket at all rather than a ticket missing a label.
            $attributes['label_ids'] = $this->targets->labelIds($board, $labels);
        }

        return ['attributes' => $attributes, 'files' => $files];
    }

    /**
     * Write one prepared ticket: CreateTicket, then the conversation's files.
     *
     * @param  array{attributes: array<string, mixed>, files: Collection<int, Attachment>}  $prepared
     * @return array{ticket: Ticket, files: Collection<int, Attachment>}
     */
    private function createPrepared(Board $board, array $prepared, User $actor): array
    {
        $ticket = $this->createTicket->handle($board, $prepared['attributes'], $actor);
        $files = $prepared['files'];

        /*
         * The conversation's files, copied onto the ticket.
         *
         * Copied rather than moved: the transcript still refers to them. Images
         * are written into the description so they are seen, not listed.
         */
        if ($files->isNotEmpty()) {
            $this->attachFiles->embed($ticket, $this->attachFiles->copies($ticket, $files, $actor));
        }

        $ticket->loadMissing(['board', 'column.tab', 'assignee', 'labels']);

        return ['ticket' => $ticket, 'files' => $files];
    }

    /**
     * ` ("Set up CI")`, for naming one entry of a batch in a refusal.
     *
     * @param  array<string, mixed>  $entry
     */
    private function quotedTitle(array $entry): string
    {
        $title = $this->optionalString($entry, 'title');

        return $title === null ? '' : ' ("'.mb_strimwidth($title, 0, 80, '…').'")';
    }

    /**
     * Change a ticket: any of its fields, its assignee, its column, its labels.
     *
     * The tool behind "assign NL-18 to Alex and make it critical", "move NL-18
     * to Done", "re-label it". Three actions are involved rather than one,
     * because three actions already own these writes and each records its own
     * history entry: UpdateTicket for the scalar fields, MoveTicket for the
     * column (it resequences positions and records a TicketMoved event),
     * SyncTicketLabels for the labels.
     *
     * The order matters and is deliberate: everything is authorized and
     * resolved first, then the writes happen. So a request to move *and*
     * re-assign where the person may move but not assign changes nothing at
     * all, rather than moving the card and then failing — which would leave the
     * ticket in a state nobody asked for and a message saying it went wrong.
     *
     * @param  array<string, mixed>  $input
     * @return array{label: string, url: ?string}
     */
    private function doUpdateTicket(Board $board, array $input, User $actor, ?AiSession $session): array
    {
        $ticket = $this->ticketFrom($board, $input, $actor, 'The proposal did not say which ticket to change.');

        Gate::forUser($actor)->authorize('update', $ticket);

        $files = $this->attachmentsFrom($session, $input, $actor);

        if ($files->isNotEmpty()) {
            Gate::forUser($actor)->authorize('manageAttachments', $ticket);
        }

        $attributes = array_filter([
            'title' => $this->optionalString($input, 'title'),
            'description_md' => $this->optionalString($input, 'description_md'),
            'priority' => isset($input['priority']) ? $this->priority($input)->value : null,
            'type' => $this->optionalString($input, 'type'),
        ], static fn ($value): bool => $value !== null);

        if (isset($attributes['title'])) {
            $attributes['title'] = mb_substr($attributes['title'], 0, 200);
        }

        // Added after the filter above, because a null due date is a real
        // change here: "none" clears it.
        [$dueGiven, $due] = $this->dueDate($input);

        if ($dueGiven) {
            $attributes['due_date'] = $due;
        }

        if (($estimate = $this->estimate($input)) !== null) {
            $attributes['estimate'] = $estimate;
        }

        // -------------------------------------------------------------
        // Authorize and resolve. Nothing is written in this section.
        // -------------------------------------------------------------

        $changes = [];

        if (array_key_exists('assignee', $input)) {
            Gate::forUser($actor)->authorize('assign', $ticket);

            $assignee = $this->targets->assignee($board, (string) $input['assignee'], $actor);

            $attributes['assignee_id'] = $assignee?->getKey();
            $changes[] = $assignee instanceof User
                ? 'assigned it to '.$assignee->name
                : 'left it unassigned';
        }

        /*
         * Which tab and column.
         *
         * A named tab is a move to that tab (null when it is the tab the ticket
         * is already in). A column is then a column of the new tab — or, with
         * no tab named, of the ticket's own tab: a column name alone never
         * carries a ticket into another tab.
         */
        $ticket->loadMissing('column.tab');
        $currentTab = $ticket->column?->tab;
        $namedTab = null;
        $destinationTab = null;

        if (($tabName = $this->optionalString($input, 'tab')) !== null) {
            Gate::forUser($actor)->authorize('move', $ticket);

            $namedTab = $this->targets->tab($board, $tabName);
            $destinationTab = $currentTab instanceof BoardTab && $namedTab->is($currentTab) ? null : $namedTab;
        }

        $column = null;

        if (($columnName = $this->optionalString($input, 'column')) !== null) {
            Gate::forUser($actor)->authorize('move', $ticket);

            $lookIn = $destinationTab ?? $currentTab;

            if (! $lookIn instanceof BoardTab) {
                throw new RuntimeException($ticket->key().' is not in a column of '.$board->name.'. Nothing was changed.');
            }

            $column = $this->targets->column($board, $lookIn, $columnName);
        }

        $labelIds = null;

        if (($labels = $this->labelNames($input)) !== null) {
            Gate::forUser($actor)->authorize('manageLabels', $ticket);

            $labelIds = $this->targets->labelIds($board, $labels);
        }

        if ($attributes === [] && $column === null && $destinationTab === null && $labelIds === null && $files->isEmpty()) {
            if ($namedTab instanceof BoardTab) {
                throw new RuntimeException($ticket->key().' is already in the '.$namedTab->name.' tab. Nothing was changed.');
            }

            throw new RuntimeException('The proposal contained no changes to make.');
        }

        // -------------------------------------------------------------
        // Write. Everything above has already been permitted.
        // -------------------------------------------------------------

        if ($attributes !== []) {
            $this->updateTicket->handle($ticket, $attributes, $actor);

            foreach (['title' => 'retitled it', 'description_md' => 'rewrote the description'] as $field => $said) {
                if (isset($attributes[$field])) {
                    $changes[] = $said;
                }
            }

            if (isset($attributes['priority'])) {
                $changes[] = 'set the priority to '.$this->priority($input)->label();
            }

            if (isset($attributes['type'])) {
                $changes[] = 'set the type to '.($ticket->fresh()?->type->label() ?? $attributes['type']);
            }

            if (array_key_exists('due_date', $attributes)) {
                $changes[] = $attributes['due_date'] === null
                    ? 'cleared the due date'
                    : 'set the due date to '.$attributes['due_date'];
            }

            // Read back, because UpdateTicket drops an estimate from anybody
            // who may not set one, and this line reports what is saved.
            if (isset($attributes['estimate']) && $ticket->fresh()?->estimate !== null) {
                $changes[] = 'set the estimate to '.rtrim(rtrim(number_format((float) $attributes['estimate'], 2, '.', ''), '0'), '.');
            }
        }

        if ($destinationTab instanceof BoardTab) {
            /*
             * To another tab: into the named column of it, or — with none
             * named — where App\Support\TabColumnMapping puts it (the column
             * with the same name, else a done column if it was done, else the
             * tab's first column). Appended at the end, like any move below.
             */
            $landed = $this->moveTicketToTab->handle($ticket, $destinationTab, $actor, $column);
            $landed->setRelation('tab', $destinationTab);

            $changes[] = 'moved it to '.TicketPlace::column($landed, true);
        } elseif ($column instanceof BoardColumn) {
            /*
             * Appended to the column rather than inserted at a position.
             *
             * A spoken request never carries a position — "move it to Done"
             * says nothing about where in Done — and choosing one would
             * reorder somebody else's board on a guess. The end of the column
             * is the honest reading, and it is where the ordinary drag-to-
             * column-header behaviour lands a card too.
             */
            $this->moveTicket->handle($ticket, $column, PHP_INT_MAX, $actor);

            $changes[] = 'moved it to '.$column->name;
        }

        if ($labelIds !== null) {
            $this->syncLabels->handle($ticket, $labelIds, $actor);

            $changes[] = $labelIds === []
                ? 'cleared its labels'
                : 'set its labels to '.implode(', ', $ticket->fresh()?->labels->pluck('name')->all() ?? []);
        }

        if ($files->isNotEmpty()) {
            $this->attachFiles->embed($ticket, $this->attachFiles->copies($ticket, $files, $actor));

            $changes[] = 'attached '.$this->countFiles($files);
        }

        $ticket->refresh()->loadMissing(['board', 'column.tab', 'assignee', 'labels']);

        return [
            'label' => 'Updated '.$ticket->key().' — '.($changes === []
                ? $this->summarise($ticket)
                : implode(', ', $changes)),
            'url' => route('tickets.show', ['board' => $board, 'number' => $ticket->number]),
            'target' => $ticket->key(),
        ];
    }

    /**
     * Add a comment to a ticket's conversation.
     *
     * Authorized with CommentPolicy::postTo rather than ::create, because the
     * audience is part of what is being asked for: posting to the customer
     * stream is a different permission from posting an internal note, and
     * `postTo` is the ability the ordinary comment box uses for exactly that
     * reason.
     *
     * The stream defaults to internal. PostComment forces a customer's comment
     * onto the customer stream regardless, so the only decision left here is
     * what a member of staff meant — and the fail-closed reading of an
     * unqualified "add a note to NL-18" is the internal one.
     *
     * @param  array<string, mixed>  $input
     * @return array{label: string, url: ?string}
     */
    private function doCommentTicket(Board $board, array $input, User $actor): array
    {
        $ticket = $this->ticketFrom($board, $input, $actor, 'The proposal did not say which ticket to comment on.');

        $body = trim($this->requiredString($input, 'body_md', 'The proposal had no comment to post.'));

        if ($body === '') {
            throw new RuntimeException('The proposal had no comment to post.');
        }

        $stream = ($input['customer_visible'] ?? false) === true
            ? CommentStream::Customer
            : CommentStream::Internal;

        Gate::forUser($actor)->authorize('postTo', [Comment::class, $ticket, $stream]);

        $this->postComment->handle($ticket, mb_substr($body, 0, 20000), $stream, $actor);

        return [
            'label' => 'Commented on '.$ticket->key()
                .($stream === CommentStream::Customer ? ' (visible to the customer)' : ' (internal note)'),
            'url' => route('tickets.show', ['board' => $board, 'number' => $ticket->number]),
            'target' => $ticket->key(),
        ];
    }

    /**
     * Delete a ticket, permanently.
     *
     * The only irreversible thing the assistant can reach, and the reason
     * AiActionType::isDestructive() exists. By the time execution gets here two
     * things have already happened that cannot happen for any other action: the
     * proposal was never auto-executed whatever the capability mode, and a
     * person typed the ticket's key by hand to confirm it. See
     * executesWithoutConfirmation() below and the confirmation gate in
     * App\Livewire\Ai\Concerns\TalksToWorkspaceAi::confirm().
     *
     * TicketPolicy::delete is still the decision. Neither of the above grants
     * anything — they only ensure that nobody arrives here by accident.
     *
     * @param  array<string, mixed>  $input
     * @return array{label: string, url: ?string}
     */
    private function doDeleteTicket(Board $board, array $input, User $actor): array
    {
        $ticket = $this->ticketFrom($board, $input, $actor, 'The proposal did not say which ticket to delete.');

        Gate::forUser($actor)->authorize('delete', $ticket);

        // Captured before the row goes, so the result and the audit entry can
        // still say what was deleted.
        $key = $ticket->key();
        $title = (string) $ticket->title;

        $this->deleteTicket->handle($ticket);

        return [
            'label' => 'Deleted '.$key.' ("'.mb_substr($title, 0, 120).'") permanently',
            // No link: there is nothing left to open.
            'url' => null,
            'target' => $key,
        ];
    }

    /**
     * A new documentation page — written by the model, or a file the person
     * attached, converted.
     *
     * @param  array<string, mixed>  $input
     * @return array{label: string, url: ?string, target: string}
     */
    private function doCreatePage(Board $board, array $input, User $actor, ?AiSession $session): array
    {
        $prepared = $this->preparePage($board, $input, $actor, $session);
        $page = $this->createPreparedPage($board, $prepared, $actor);

        return [
            'label' => 'Created page “'.$page->title.'”'
                .($prepared['source'] !== null ? ' from '.$prepared['source'] : '')
                .' (internal until published)'
                .($prepared['truncated'] ? ' — the file was longer than a page holds, so the end is in the attached original' : ''),
            'url' => route('docs.show', ['board' => $board, 'slug' => $page->slug]),
            'target' => mb_substr($page->slug, 0, 190),
        ];
    }

    /**
     * A file put into the documentation, and tickets for its to-dos and bugs,
     * as one change.
     *
     * Everything is prepared first — the page authorized and its body
     * converted, every ticket authorized and resolved — and only then is
     * anything written: the page, then the tickets in order. A refusal at the
     * preparation stage therefore leaves nothing behind and names the part
     * that was refused.
     *
     * The tickets start internal unless the person said customers should see
     * them. They come from a page that is internal until somebody publishes
     * it, and a ticket's description is the first thing a customer reads; an
     * internal note copied out of a document must not reach them because a
     * board's default happens to be "visible". Each internal ticket links back
     * to the page it came from.
     *
     * As with a batch, there is no outer transaction — CreateTicket's
     * observers broadcast and notify — so a failure part-way through says
     * exactly what exists: the page, and which tickets.
     *
     * @param  array<string, mixed>  $input
     * @return array{label: string, url: ?string, target: string, page: array{title: string, url: string}, items: list<array{key: string, title: string, url: string}>}
     */
    private function doImportDocument(Board $board, array $input, User $actor, ?AiSession $session): array
    {
        $pageInput = is_array($input['page'] ?? null) ? $input['page'] : [];

        $entries = array_values(array_filter(
            is_array($input['tickets'] ?? null) ? $input['tickets'] : [],
            static fn ($entry): bool => is_array($entry),
        ));

        $max = AiActionType::maxTicketsPerReply();

        if (count($entries) > $max) {
            throw new RuntimeException('That is more than '.$max.' tickets for one request. Nothing was imported.');
        }

        $page = $this->preparePage($board, $pageInput, $actor, $session);

        $tickets = [];

        foreach ($entries as $index => $entry) {
            // Internal unless the person said customers should see it.
            $entry['customer_visible'] = ($entry['customer_visible'] ?? false) === true;

            try {
                $tickets[] = $this->prepareTicket($board, $entry, $actor, $session);
            } catch (AuthorizationException|RuntimeException $exception) {
                $reason = $exception->getMessage();
                $which = 'Ticket '.($index + 1).$this->quotedTitle($entry).': '.$reason
                    .(str_contains($reason, 'Nothing was') ? '' : ' Nothing was imported.');

                throw $exception instanceof AuthorizationException
                    ? new AuthorizationException($which)
                    : new RuntimeException($which);
            }
        }

        $created = $this->createPreparedPage($board, $page, $actor);
        $pageUrl = route('docs.show', ['board' => $board, 'slug' => $created->slug]);

        $made = [];

        foreach ($tickets as $ticket) {
            $ticket = $this->linkedToPage($ticket, $created, $pageUrl);

            try {
                $made[] = $this->createPrepared($board, $ticket, $actor)['ticket'];
            } catch (Throwable $exception) {
                $done = $made === []
                    ? 'Created the page “'.$created->title.'” but no tickets'
                    : 'Created the page “'.$created->title.'” and '.implode(', ', array_map(fn (Ticket $t): string => $t->key(), $made));

                throw new RuntimeException(
                    $done.', then it stopped: '.$exception->getMessage().'. The remaining '
                    .(count($tickets) - count($made)).' tickets were not created.'
                );
            }
        }

        $label = 'Imported “'.$created->title.'”'.($page['source'] !== null ? ' ('.$page['source'].')' : '').' into the docs';
        $target = $created->slug;

        if ($made !== []) {
            $range = count($made) === 1 ? $made[0]->key() : $made[0]->key().' to '.$made[count($made) - 1]->key();
            $visible = count(array_filter($made, fn (Ticket $t): bool => (bool) $t->customer_visible));

            $label .= ' and created '.count($made).' '.(count($made) === 1 ? 'ticket' : 'tickets').': '.$range
                .' ('.match (true) {
                    $visible === 0 => 'internal',
                    $visible === count($made) => 'visible to customers',
                    default => $visible.' visible to customers, '.(count($made) - $visible).' internal',
                }.')';

            $target .= ', '.$range;
        }

        if ($page['truncated']) {
            $label .= ' — the file was longer than a page holds, so the end is in the attached original';
        }

        return [
            'label' => $label,
            'url' => $pageUrl,
            'target' => mb_substr($target, 0, 190),
            'page' => ['title' => $created->title, 'url' => $pageUrl],
            'items' => array_map(fn (Ticket $t): array => [
                'key' => $t->key(),
                'title' => $t->title,
                'url' => route('tickets.show', ['board' => $board, 'number' => $t->number]),
            ], $made),
        ];
    }

    /**
     * Everything a new page needs, authorized and resolved, nothing written.
     *
     * The body is the model's `body_md`, or — when `import_attachment_id`
     * names a file from this conversation — that file's whole contents as
     * Markdown, with `body_md` as an introduction above it. The original file
     * goes on the page with any others named in `attachment_ids`.
     *
     * @param  array<string, mixed>  $input
     * @return array{attributes: array{title: string, body_md: ?string, parent_id: ?int}, files: Collection<int, Attachment>, source: ?string, truncated: bool}
     */
    private function preparePage(Board $board, array $input, User $actor, ?AiSession $session): array
    {
        Gate::forUser($actor)->authorize('create', [DocPage::class, $board]);

        $title = $this->requiredString($input, 'title', 'The proposal had no page title.');

        $parentId = null;
        $parentSlug = $this->optionalString($input, 'parent_slug');

        if ($parentSlug !== null) {
            // Through DocPageFinder, so a parent the confirming user cannot see
            // — including one hidden by the ancestor rule — 404s rather than
            // silently becoming a root page under somebody else's tree.
            $parentId = (int) $this->pages->findOrFail($board, $parentSlug, $actor)->getKey();
        }

        $body = $this->optionalString($input, 'body_md');
        $files = $this->attachmentsFrom($session, $input, $actor);
        $source = null;
        $truncated = false;

        $importId = $input['import_attachment_id'] ?? null;

        if (is_int($importId) || (is_string($importId) && ctype_digit($importId))) {
            [$markdown, $record, $truncated] = $this->importedBody($session, (int) $importId, $actor);

            $body = trim(($body !== null ? $body."\n\n---\n\n" : '').$markdown);
            $source = $record->filename();

            if ($record->attachment instanceof Attachment && ! $files->contains(fn (Attachment $file): bool => $file->is($record->attachment))) {
                $files->prepend($record->attachment);
            }
        }

        // What one page holds, as the editor enforces it.
        $limit = max(10000, (int) config('attachments.imports.body_max_characters', 200000));

        if ($body !== null && mb_strlen($body) > $limit) {
            $body = rtrim(mb_substr($body, 0, $limit - 120))
                ."\n\n---\n\n*The rest did not fit on one page. The whole original is attached to this page.*";
            $truncated = true;
        }

        return [
            'attributes' => [
                'title' => mb_substr($title, 0, 200),
                'body_md' => $body,
                'parent_id' => $parentId,
            ],
            'files' => $files->take(max(1, (int) config('attachments.max_per_owner', 20)))->values(),
            'source' => $source,
            'truncated' => $truncated,
        ];
    }

    /**
     * A conversation's file as a page body: the copy prepared when it was
     * read, or a conversion now for a file read before that existed.
     *
     * @return array{0: string, 1: AiAttachment, 2: bool}
     */
    private function importedBody(?AiSession $session, int $id, User $actor): array
    {
        $record = $session === null ? null : AiAttachment::query()
            ->visibleTo($actor)
            ->forSession($session)
            ->whereKey($id)
            ->with('attachment')
            ->first();

        if (! $record instanceof AiAttachment || ! $record->attachment instanceof Attachment) {
            throw new RuntimeException(
                'The proposal names a file that is not attached to this conversation. Nothing was changed — '
                .'attach the file again and ask once more.'
            );
        }

        if (! $record->isImportable()) {
            throw new RuntimeException(
                '“'.$record->filename().'” cannot become a documentation page'
                .($record->status->isReady() ? ' — pages are made from documents, not pictures or recordings.' : ': it could not be read.')
                .' Nothing was changed.'
            );
        }

        if ($record->markdown !== null) {
            return [$record->markdown, $record, (bool) $record->markdown_truncated];
        }

        try {
            $document = $this->importer->fromAttachment($record->attachment);
        } catch (DocumentImportException $exception) {
            throw new RuntimeException('“'.$record->filename().'” could not be imported: '.$exception->getMessage().' Nothing was changed.');
        }

        return [$document->markdown, $record, $document->truncated];
    }

    /**
     * Write one prepared page: CreatePage, then its files.
     *
     * @param  array{attributes: array{title: string, body_md: ?string, parent_id: ?int}, files: Collection<int, Attachment>, source: ?string, truncated: bool}  $prepared
     */
    private function createPreparedPage(Board $board, array $prepared, User $actor): DocPage
    {
        // CreatePage always makes a page internal; publishing is a separate
        // action with its own ability, and the chat has no way to reach it.
        $page = $this->createPage->handle($board, $prepared['attributes'], $actor);

        // Copied rather than moved: the conversation still refers to them.
        foreach ($prepared['files'] as $file) {
            $this->storage->copy($file, $page, $board, $actor);
        }

        // CreatePage leaves telling other screens to its caller; the docs
        // sidebar does it, so the assistant does too. A new page is internal,
        // so no customer's screen needs to hear about it.
        $this->broadcaster->documentationChanged((int) $board->getKey(), false);

        return $page;
    }

    /**
     * A prepared ticket whose description ends with a link to the page it came
     * from — for an internal ticket only, because a customer who follows a link
     * to an internal page meets a 404 and learns that the page exists.
     *
     * @param  array{attributes: array<string, mixed>, files: Collection<int, Attachment>}  $ticket
     * @return array{attributes: array<string, mixed>, files: Collection<int, Attachment>}
     */
    private function linkedToPage(array $ticket, DocPage $page, string $url): array
    {
        if (($ticket['attributes']['customer_visible'] ?? false) === true) {
            return $ticket;
        }

        $link = '*From the docs page ['.str_replace(['[', ']'], ['\\[', '\\]'], $page->title).']('.$url.')*';
        $description = trim((string) ($ticket['attributes']['description_md'] ?? ''));

        $ticket['attributes']['description_md'] = $description === '' ? $link : $description."\n\n---\n\n".$link;

        return $ticket;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{label: string, url: ?string}
     */
    private function doUpdatePage(Board $board, array $input, User $actor): array
    {
        $slug = $this->requiredString($input, 'slug', 'The proposal did not say which page to change.');

        $page = $this->pages->findOrFail($board, $slug, $actor);

        Gate::forUser($actor)->authorize('update', $page);

        $attributes = array_filter([
            'title' => $this->optionalString($input, 'title'),
            'body_md' => $this->optionalString($input, 'body_md'),
        ], static fn ($value): bool => $value !== null);

        if ($attributes === []) {
            throw new RuntimeException('The proposal contained no changes to make.');
        }

        if (isset($attributes['title'])) {
            $attributes['title'] = mb_substr($attributes['title'], 0, 200);
        }

        /*
         * Somebody else's unsaved work is not overwritten.
         *
         * UpdatePage discards the page's draft — right for its author saving,
         * wrong for anybody else: a colleague's half-written edit would vanish
         * without them ever being told. So the change is refused and the
         * person is told who to ask. The author's own draft is theirs to
         * replace.
         */
        if ($page->hasDraft() && (int) $page->draft_by_id !== (int) $actor->getKey()) {
            $page->loadMissing('draftBy');

            throw new RuntimeException(
                ($page->draftBy?->name ?? 'Somebody').' has unsaved edits on “'.$page->title.'”. '
                .'Nothing was changed — ask them to save or discard their draft first.'
            );
        }

        $this->updatePage->handle($page, $attributes, $actor);

        // As the docs page does after a save: open sidebars and readers
        // refresh, and a published page's customers see the change.
        $this->broadcaster->documentationChanged((int) $board->getKey(), (bool) $page->customer_visible);

        return [
            'label' => 'Updated page “'.$page->title.'”',
            'url' => route('docs.show', ['board' => $board, 'slug' => $page->slug]),
            'target' => mb_substr((string) $page->slug, 0, 190),
        ];
    }

    // -----------------------------------------------------------------
    // Resolving a proposal's subject, and reporting what happened
    // -----------------------------------------------------------------

    /**
     * Does this action run as soon as the person asks for it?
     *
     * The one place the brief's two requirements are reconciled, so they cannot
     * be reconciled differently somewhere else:
     *
     *   "execute the action when the user has explicitly requested it", for
     *   ordinary reversible operations, and
     *   "only execute after explicit confirmation", for destructive ones.
     *
     * Both are honoured by asking two questions. Is the change reversible? A
     * deletion is not, and no mode makes it so. And does the workspace trust
     * its AI to act rather than to propose? That is precisely what the existing
     * capability mode already means, so it is reused rather than joined by a
     * second setting that could disagree with it:
     *
     *   AI Operator  "proposes changes for a person to confirm" — so every
     *                action is previewed and confirmed, exactly as before this
     *                existed. Nothing about a tightened workspace changes.
     *   AI Agent     "runs approved automation" — so a reversible change the
     *                person asked for in words happens, and is reported with
     *                the real result. Asking twice for something somebody just
     *                asked for is friction, not safety, when it can be undone.
     *   AI Observer  never reaches here; no write tool is offered at all.
     *
     * What this does NOT do is authorize anything. It decides *when* handle()
     * is called, never *whether* it succeeds — every policy check inside runs
     * identically either way, against the same person. A customer is offered no
     * write tool, so no answer here applies to one.
     */
    public function executesWithoutConfirmation(AiChatMessage $message, ?Board $board): bool
    {
        $type = $message->actionType();

        if ($type === null || $type->isDestructive()) {
            return false;
        }

        return $this->guard->mode($board)->canRunUnattended();
    }

    /**
     * The ticket a proposal is about, on this board, visible to this person.
     *
     * One definition for all four ticket actions, because "which ticket" is the
     * question a crafted proposal would most like to answer differently from
     * the board it was filed against. TicketFinder applies the viewer's
     * visibility and raises a 404 for a ticket this person may not see — which
     * handle() turns into prose — so an internal ticket cannot be confirmed
     * into existence by a customer naming its number.
     *
     * @param  array<string, mixed>  $input
     */
    /**
     * The conversation's files a proposal names, as stored attachments.
     *
     * Resolved against the message's own session and the person confirming,
     * so an id from another conversation — the model's, or a forged
     * proposal's — is simply not found. A file that is still being read is
     * fine to attach: the ticket wants the bytes, not the extraction.
     *
     * @param  array<string, mixed>  $input
     * @return Collection<int, Attachment>
     */
    private function attachmentsFrom(?AiSession $session, array $input, User $actor): Collection
    {
        $ids = collect((array) ($input['attachment_ids'] ?? []))
            ->filter(static fn ($id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return new Collection;
        }

        // No conversation: the conversation is gone, or the change came from an
        // AI app over MCP, which has no files to name.
        if ($session === null) {
            throw new RuntimeException(
                'That names files from a conversation, and there is no conversation here to take them from. Nothing was changed.'
            );
        }

        $records = AiAttachment::query()
            ->visibleTo($actor)
            ->forSession($session)
            ->whereKey($ids->all())
            ->with('attachment')
            ->get();

        if ($records->count() !== $ids->count()) {
            throw new RuntimeException(
                'The proposal names a file that is not attached to this conversation. Nothing was changed — '
                .'attach the file again and ask once more.'
            );
        }

        return $records
            ->map(static fn (AiAttachment $record): ?Attachment => $record->attachment)
            ->filter(static fn (?Attachment $attachment): bool => $attachment instanceof Attachment)
            ->values();
    }

    /**
     * @param  Collection<int, Attachment>  $files
     */
    private function countFiles(Collection $files): string
    {
        $count = $files->count();

        return $count.' '.($count === 1 ? 'file' : 'files');
    }

    private function ticketFrom(Board $board, array $input, User $actor, string $missing): Ticket
    {
        $number = (int) ($input['number'] ?? 0);

        if ($number < 1) {
            throw new RuntimeException($missing);
        }

        return $this->tickets->findOrFail($board, $number, $actor);
    }

    /**
     * A ticket that does not exist yet, for the policies to judge.
     *
     * Needed because `assign` and `manageLabels` are abilities on a Ticket, and
     * the create path has to answer them before there is a ticket. Rather than
     * re-deriving "is this person staff on this board" — the kind of duplicate
     * rule that eventually disagrees with the policy it copies — the real
     * policy is asked about a stand-in carrying the two attributes it reads:
     * the board, and internal visibility.
     *
     * Both are the honest values for the ticket this path is about to create:
     * the caller passes the visibility it has already decided on, so the answer
     * is the same one the policy would give a moment later on the saved row,
     * and it is TicketPolicy's answer rather than this class's.
     *
     * It is never saved.
     */
    private function prototype(Board $board, bool $visible = false): Ticket
    {
        $ticket = new Ticket;

        $ticket->board_id = $board->getKey();
        $ticket->customer_visible = $visible;
        $ticket->setRelation('board', $board);

        return $ticket;
    }

    /**
     * The label names a proposal asked for, or null for "leave them alone".
     *
     * The distinction is load-bearing: an empty array means "clear the labels",
     * which is a change, and a missing key means the person said nothing about
     * labels, which is not. Collapsing the two would strip every label off a
     * ticket whenever somebody asked to change its priority.
     *
     * @param  array<string, mixed>  $input
     * @return list<string>|null
     */
    private function labelNames(array $input): ?array
    {
        if (! array_key_exists('labels', $input) || ! is_array($input['labels'])) {
            return null;
        }

        $names = [];

        foreach ($input['labels'] as $name) {
            if (is_string($name) && trim($name) !== '') {
                $names[] = trim($name);
            }
        }

        return $names;
    }

    /**
     * A ticket's state in one clause, for the line the person reads.
     *
     * Built from the saved row rather than from the proposal, which is the
     * whole point: the brief's "do not say an operation succeeded unless the
     * backend confirms success" is kept by reporting what is in the database
     * now, not what was asked for. A dropped assignee shows as unassigned here.
     */
    private function summarise(Ticket $ticket): string
    {
        $parts = ['priority '.$ticket->priority->label()];

        if ($ticket->column !== null) {
            // "in Backend API › To Do" on a board split into tabs.
            $parts[] = 'in '.TicketPlace::of($ticket);
        }

        $parts[] = $ticket->assignee !== null
            ? 'assigned to '.$ticket->assignee->name
            : 'unassigned';

        if ($ticket->labels->isNotEmpty()) {
            $parts[] = 'labelled '.implode(', ', $ticket->labels->pluck('name')->all());
        }

        if ($ticket->due_date !== null) {
            $parts[] = 'due '.$ticket->due_date->toDateString();
        }

        // Said every time, because it is the one fact about a new ticket that
        // decides who outside the team can read it.
        $parts[] = $ticket->customer_visible ? 'visible to customers' : 'internal';

        return implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function requiredString(array $input, string $key, string $message): string
    {
        $value = $this->optionalString($input, $key);

        if ($value === null) {
            throw new RuntimeException($message);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function optionalString(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function priority(array $input): TicketPriority
    {
        $value = $input['priority'] ?? null;

        return is_string($value)
            ? (TicketPriority::tryFrom($value) ?? TicketPriority::default())
            : TicketPriority::default();
    }

    /**
     * The due date a change asked for: whether it said anything about one,
     * and the date — null meaning "clear it", which people say as "none".
     *
     * A date that is not a real calendar date is refused rather than guessed
     * at: "2026-02-30" landing on the 2nd of March would be a deadline nobody
     * set.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: bool, 1: ?string}
     */
    private function dueDate(array $input): array
    {
        $value = $this->optionalString($input, 'due_date');

        if ($value === null) {
            return [false, null];
        }

        if (in_array(mb_strtolower($value), ['none', 'clear', 'no date', 'null'], true)) {
            return [true, null];
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException(
                'The due date has to be a date written like 2026-10-25, or "none" to clear it. Nothing was changed.'
            );
        }

        return [true, $value];
    }

    /**
     * The estimate a change asked for, or null when it did not ask.
     *
     * @param  array<string, mixed>  $input
     */
    private function estimate(array $input): ?float
    {
        $value = $input['estimate'] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        // The column is decimal(6,2).
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 9999) {
            throw new RuntimeException('The estimate has to be a number from 0 to 9999. Nothing was changed.');
        }

        return round((float) $value, 2);
    }
}
