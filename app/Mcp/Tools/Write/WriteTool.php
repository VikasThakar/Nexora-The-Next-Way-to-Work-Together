<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Write;

use App\Actions\AI\ExecuteChatAction;
use App\Enums\AiActionType;
use App\Enums\AiToolChannel;
use App\Mcp\McpPresentation;
use App\Mcp\Support\Confirmations;
use App\Mcp\Support\RepeatGuard;
use App\Models\AiToolInvocation;
use App\Models\Board;
use App\Models\Ticket;
use App\Services\AI\AiCapabilityGuard;
use App\Services\AI\Audit\AiAuditLogger;
use App\Services\AI\Tools\AiToolContext;
use App\Services\AI\Tools\AiToolInput;
use App\Services\AI\Tools\AiToolInputException;
use App\Services\AI\Tools\Concerns\ResolvesWorkspaceSubjects;
use App\Support\WriteOrigin;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use PDOException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * One change an AI app may make in Nexora, as the person who connected it.
 *
 * The read tools are the in-app assistant's, wrapped (RegistryTool); these are
 * new, because the in-app assistant never writes directly — it proposes, and a
 * person confirms. Over MCP the person is in a conversation in their own AI
 * app, and the app itself asks them before each call (these tools are
 * annotated as writes, and delete as destructive, which is what makes Claude
 * and ChatGPT ask). So the change is made directly, through the same code a
 * confirmed proposal goes through: ExecuteChatAction::perform(). Nothing here
 * re-implements a permission.
 *
 * Every call, whichever tool, runs the same steps in the same order:
 *
 *   1. the per-person write limit (on top of the endpoint's request limit);
 *   2. the arguments, checked against the tool's own schema (AiToolInput,
 *      as the read tools are) — unknown keys dropped, lengths capped;
 *   3. the tool's own work (run()), which resolves its target as this person,
 *      asks AiCapabilityGuard::assertCanWriteExternally() — the AI mode of the
 *      board, and the customer rule — and then makes the change inside
 *      WriteOrigin, so the history says "via Claude";
 *   4. one audit row, success or not, on the `mcp` channel;
 *   5. an answer in the display style: ✅ and what actually changed, read
 *      back from the database, or ❌ and why nothing did.
 *
 * Refusals are prose a person can act on and never say more than the person
 * could find out on the screens: a ticket they cannot see and a ticket that
 * does not exist get the same answer, and a database error is never repeated.
 */
abstract class WriteTool extends Tool
{
    use ResolvesWorkspaceSubjects;

    public function __construct(
        protected readonly AiToolContext $context,
        protected readonly string $via,
    ) {
        $this->name = $this->toolName();
        $this->title = Str::headline($this->toolName());
        $this->description = $this->describe();
    }

    // -----------------------------------------------------------------
    // What each tool says about itself
    // -----------------------------------------------------------------

    abstract protected function toolName(): string;

    abstract protected function describe(): string;

    /**
     * The arguments, as JSON Schema. Staff and customers may see different
     * ones: the customer variant offers only what a customer may change.
     *
     * @return array{type: 'object', properties: array<string, mixed>, required?: list<string>}
     */
    abstract public function inputSchema(): array;

    abstract protected function run(array $input): WriteOutcome;

    /**
     * Is this one of the few things a customer may do, as on the ticket screen?
     */
    protected function forCustomers(): bool
    {
        return false;
    }

    /**
     * Does it destroy something that cannot be got back?
     */
    protected function destructive(): bool
    {
        return false;
    }

    /**
     * Does it add something, so that a client's retry would add it twice?
     */
    protected function addsSomething(): bool
    {
        return false;
    }

    // -----------------------------------------------------------------
    // MCP plumbing
    // -----------------------------------------------------------------

    /**
     * Offered only while writes are switched on, to an active person, and to
     * a customer only when it is one of the customer's own operations.
     */
    public function shouldRegister(): bool
    {
        return (bool) config('mcp.server.writes_enabled', true)
            && $this->context->user->isActive()
            && ($this->context->staff || $this->forCustomers());
    }

    /**
     * Not read-only, not idempotent, no outside world. ChatGPT and Claude ask
     * the person before calling a tool that does not say it is read-only.
     *
     * @return array<string, mixed>
     */
    public function annotations(): array
    {
        return [
            'readOnlyHint' => false,
            'destructiveHint' => $this->destructive(),
            'idempotentHint' => false,
            'openWorldHint' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $definition = parent::toArray();

        $schema = $this->inputSchema();
        $schema['properties'] = ($schema['properties'] ?? []) === [] ? (object) [] : $schema['properties'];

        $definition['inputSchema'] = $schema;

        return $definition;
    }

    public function handle(Request $request): Response
    {
        $started = hrtime(true);
        $user = $this->context->user;
        $raw = $request->all();

        $limiter = 'mcp-write:'.$user->getKey();
        $limit = max(1, (int) config('mcp.server.write_rate_limit', 30));

        if (RateLimiter::tooManyAttempts($limiter, $limit)) {
            return $this->finish(WriteOutcome::refused(
                '❌ Not changed — that is more than '.$limit.' changes in a minute. Wait '
                .RateLimiter::availableIn($limiter).' seconds and try again.'
            ), $raw, $started);
        }

        RateLimiter::hit($limiter, 60);

        try {
            $input = $this->validateInput($raw);
        } catch (AiToolInputException $exception) {
            return $this->finish(WriteOutcome::invalid('❌ Not changed — '.$exception->getMessage()), $raw, $started);
        }

        try {
            $outcome = $this->addsSomething() ? $this->runOnce($input) : $this->run($input);
        } catch (AuthorizationException $refusal) {
            // A policy that denies "as not found" means exactly that.
            $outcome = $refusal->status() === 404
                ? WriteOutcome::notFound('❌ Not changed — '.$this->notFoundText())
                : WriteOutcome::refused('❌ Not changed — '.$this->refusalText($refusal));
        } catch (NotFoundHttpException|ModelNotFoundException) {
            $outcome = WriteOutcome::notFound('❌ Not changed — '.$this->notFoundText());
        } catch (PDOException $exception) {
            // Before RuntimeException, which PDOException extends: a database
            // error's message is never shown to anybody.
            $outcome = $this->technicalFailure($exception);
        } catch (RuntimeException $exception) {
            $outcome = WriteOutcome::failed('❌ Not changed — '.$exception->getMessage());
        } catch (Throwable $exception) {
            $outcome = $this->technicalFailure($exception);
        }

        return $this->finish($outcome, $input, $started);
    }

    // -----------------------------------------------------------------
    // For the tools
    // -----------------------------------------------------------------

    /**
     * The arguments, checked against the schema. A tool whose schema nests
     * objects (which AiToolInput does not walk) checks the inner ones itself.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     *
     * @throws AiToolInputException
     */
    protected function validateInput(array $raw): array
    {
        return AiToolInput::validate($this->inputSchema(), $raw);
    }

    /**
     * The ticket a call names, as this person can see it.
     *
     * @throws NotFoundHttpException
     */
    protected function ticket(array $input): Ticket
    {
        $ticket = $this->ticketFrom($input, $this->context);

        if (! $ticket instanceof Ticket) {
            throw new NotFoundHttpException;
        }

        return $ticket->loadMissing(['board', 'column', 'assignee', 'labels']);
    }

    /**
     * The board a call names — name, slug or ticket prefix — among this
     * person's boards that are not archived.
     *
     * @throws NotFoundHttpException
     */
    protected function board(array $input): Board
    {
        $board = app(ExecuteChatAction::class)->resolveBoard((string) ($input['board'] ?? ''), $this->context->user);

        if (! $board instanceof Board) {
            throw new NotFoundHttpException;
        }

        return $board;
    }

    /**
     * The AI mode of the board, and the customer rule. Policies come after.
     *
     * @throws AuthorizationException
     */
    protected function guard(Board $board): void
    {
        if ($board->isArchived()) {
            throw new RuntimeException('“'.$board->name.'” is archived, so nothing on it can be changed.');
        }

        app(AiCapabilityGuard::class)->assertCanWriteExternally($board, $this->context->user, $this->forCustomers());
    }

    /**
     * Make the change through the shared write path, recorded as coming
     * through this app.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function perform(AiActionType $type, Board $board, array $input): array
    {
        return app(WriteOrigin::class)->through(
            $this->via,
            fn (): array => app(ExecuteChatAction::class)->perform($type, $board, $input, $this->context->user),
        );
    }

    /**
     * Refuse a change to something that has changed since it was read.
     *
     * Optional — the model passes the "Last updated" it saw when it read the
     * record — because two people (or a person and an AI app) editing the same
     * ticket a minute apart should not silently undo each other.
     *
     * @throws RuntimeException
     */
    protected function assertUnchangedSince(array $input, ?CarbonInterface $updatedAt, string $what): void
    {
        $since = trim((string) ($input['if_unchanged_since'] ?? ''));

        if ($since === '' || $updatedAt === null) {
            return;
        }

        try {
            $seen = CarbonImmutable::parse($since);
        } catch (Throwable) {
            throw new RuntimeException('if_unchanged_since has to be the "Last updated" time exactly as it was read. Nothing was changed.');
        }

        // A second's grace: the time the model read was printed to the second.
        if ($updatedAt->getTimestamp() > $seen->getTimestamp() + 1) {
            throw new RuntimeException(
                $what.' was changed at '.McpPresentation::time($updatedAt, McpPresentation::timezone())
                .', after it was read. Nothing was changed — read it again, check what changed, and then make the change.'
            );
        }
    }

    /**
     * The two-step confirmation: null when this call carries a valid code for
     * exactly this change, otherwise the answer asking for one.
     *
     * @param  array<string, mixed>  $input
     */
    protected function confirmation(array $input, string $target, Board $board, string $preview): ?WriteOutcome
    {
        $arguments = array_diff_key($input, ['confirmation' => true, 'if_unchanged_since' => true]);
        $confirmations = app(Confirmations::class);
        $code = trim((string) ($input['confirmation'] ?? ''));

        if ($code !== '' && $confirmations->redeem($this->context->user, $this->toolName(), $target, $arguments, $code)) {
            return null;
        }

        $issued = $confirmations->issue($this->context->user, $this->toolName(), $target, $arguments);

        return WriteOutcome::awaiting(
            ($code !== '' ? "⚠️ That confirmation code is not valid for this change (codes work once, for ten minutes, for exactly the change they were given for). A new one is below.\n\n" : '')
            ."⏸️ **Confirmation needed — nothing has been changed yet.**\n\n"
            .$preview."\n\n"
            .'Show the person exactly what will happen and ask them to confirm. Only after they say yes, call '
            .$this->toolName().' again with the same arguments plus confirmation: "'.$issued.'". '
            .'Never confirm on their behalf. The code works once and expires in 10 minutes.',
            $target,
            $board,
        );
    }

    protected function notFoundText(): string
    {
        return 'there is nothing by that name that you can change. Check the key or name (it may be on a board you are not on, or not exist).';
    }

    // -----------------------------------------------------------------

    /**
     * run(), at most once per identical request within the repeat window.
     */
    private function runOnce(array $input): WriteOutcome
    {
        $outcome = null;

        $once = app(RepeatGuard::class)->once(
            $this->context->user,
            $this->toolName(),
            array_diff_key($input, ['if_unchanged_since' => true]),
            function () use ($input, &$outcome): array {
                $outcome = $this->run($input);

                return [
                    'result' => ['text' => $outcome->text, 'target' => $outcome->target, 'board_id' => $outcome->board?->getKey()],
                    'remember' => $outcome->changedSomething(),
                ];
            },
        );

        if (! $once['repeated'] && $outcome instanceof WriteOutcome) {
            return $outcome;
        }

        $result = $once['result'];

        return WriteOutcome::ok(
            "↩️ **Already done a moment ago** — this was an identical request, so nothing new was created. The earlier result:\n\n"
            .(string) ($result['text'] ?? ''),
            is_string($result['target'] ?? null) ? $result['target'] : null,
            isset($result['board_id']) ? Board::query()->find($result['board_id']) : null,
            'Repeated request; nothing new was created.',
        );
    }

    private function refusalText(AuthorizationException $refusal): string
    {
        $message = trim($refusal->getMessage());

        return $message === '' || $message === 'This action is unauthorized.'
            ? 'you are not allowed to make that change.'
            : $message;
    }

    private function technicalFailure(Throwable $exception): WriteOutcome
    {
        // The class and the reason, for whoever reads the logs — never the
        // message to the AI app, which could carry SQL or a path.
        Log::warning('MCP write tool failed', [
            'tool' => $this->toolName(),
            'user_id' => $this->context->user->getKey(),
            'exception' => $exception::class,
            'reason' => Str::limit($exception->getMessage(), 300),
        ]);

        return WriteOutcome::failed(
            '❌ Not changed — that failed for a technical reason. Tell the person it could not be done rather than guessing.',
            note: $exception::class,
        );
    }

    /**
     * The audit row, then the answer.
     *
     * @param  array<string, mixed>  $input
     */
    private function finish(WriteOutcome $outcome, array $input, int $started): Response
    {
        $user = $this->context->user;

        app(AiAuditLogger::class)->record(
            tool: $this->toolName(),
            category: AiToolInvocation::CATEGORY_ACTION,
            outcome: $outcome->outcome,
            success: $outcome->succeeded(),
            user: $user,
            board: $outcome->board,
            target: $outcome->target !== null ? mb_substr($outcome->target, 0, 190) : null,
            input: array_diff_key($input, ['confirmation' => true]),
            note: $user->name.' via '.$this->via.': '.($outcome->note ?? Str::limit($this->plain($outcome->text), 240)),
            resultCharacters: mb_strlen($outcome->text),
            durationMs: (int) ((hrtime(true) - $started) / 1_000_000),
            channel: AiToolChannel::Mcp->value,
        );

        return $outcome->succeeded()
            ? Response::text($outcome->text)
            : Response::error($outcome->text);
    }

    /**
     * The answer's first line without its Markdown, for the audit note.
     */
    private function plain(string $text): string
    {
        $line = strtok(trim($text), "\n");

        return trim((string) preg_replace('/[*_`#>]|\[([^\]]*)\]\([^)]*\)/u', '$1', $line === false ? $text : $line));
    }
}
