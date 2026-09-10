<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Models\AiChatMessage;
use App\Models\AiToolInvocation;
use App\Services\AI\Audit\AiAuditLogger;
use App\Services\AI\Data\AiTool;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The list of read tools, and the only way to run one.
 *
 * Every invocation goes through invoke(), and that is what makes the four
 * promises in the brief structural rather than aspirational:
 *
 *   schema         the tool declares one, and AiToolInput enforces it here
 *                  before the tool is entered.
 *   authorization  a tool is offered only if availableTo() says so, and its
 *                  own reads are scoped to the viewer on the context. Both are
 *                  checked here — availability is not re-derived by the caller.
 *   validation     see schema. A call that does not fit is answered with prose
 *                  the model can act on, not an exception.
 *   audit          one row per call, written whatever the outcome. A refused
 *                  call is the interesting one to have a record of, so it is
 *                  recorded exactly as a successful one is.
 *
 * A tool that throws is contained
 * -------------------------------
 * Throwable is caught, logged, and turned into a tool result saying the lookup
 * failed. The alternative is that one bad tool takes down the answer, and an
 * assistant that dies because a repository row has an unexpected shape is worse
 * than one that says it could not read the repository.
 *
 * Nothing here writes to workspace content. The registry holds reads only; a
 * change still goes through the propose-and-confirm path in
 * App\Actions\AI\ExecuteChatAction, which authorizes against the confirming
 * person and calls the ordinary action.
 */
class AiToolRegistry
{
    /** @var array<string, AiToolContract> */
    private array $tools = [];

    /**
     * @param  iterable<AiToolContract>  $tools  tagged in AppServiceProvider
     */
    public function __construct(iterable $tools, private readonly AiAuditLogger $audit)
    {
        foreach ($tools as $tool) {
            if ($tool instanceof AiToolContract) {
                $this->tools[$tool->name()] = $tool;
            }
        }
    }

    /**
     * Which tools this person may use, in this context.
     *
     * @return array<string, AiToolContract>
     */
    public function availableFor(AiToolContext $context): array
    {
        if (! (bool) config('ai.tools.enabled', true)) {
            return [];
        }

        return array_filter(
            $this->tools,
            static fn (AiToolContract $tool): bool => $tool->availableTo($context)
        );
    }

    /**
     * The same list, as provider-neutral definitions to send with a prompt.
     *
     * @return list<AiTool>
     */
    public function definitionsFor(AiToolContext $context): array
    {
        return array_values(array_map(
            static fn (AiToolContract $tool): AiTool => new AiTool(
                name: $tool->name(),
                description: $tool->description(),
                inputSchema: $tool->inputSchema(),
            ),
            $this->availableFor($context)
        ));
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Run one tool call and write it down.
     *
     * @param  mixed  $input  straight from the provider; untrusted
     */
    public function invoke(
        string $name,
        mixed $input,
        AiToolContext $context,
        ?AiChatMessage $message = null,
    ): AiToolOutcome {
        $available = $this->availableFor($context);
        $tool = $available[$name] ?? null;

        if (! $tool instanceof AiToolContract) {
            /*
             * Either the name does not exist, or it exists and is not offered
             * here. One answer for both, deliberately: a customer who guesses
             * at get_pull_request should not be able to tell the difference
             * between "no such tool" and "not for you".
             */
            $outcome = AiToolOutcome::refused(
                'There is no tool called "'.mb_substr($name, 0, 64).'" available here. '
                .'Answer from the context you already have, and say what you could not look up.'
            );

            $this->write($name, $outcome, $context, $message, [], 0);

            return $outcome;
        }

        try {
            $arguments = AiToolInput::validate($tool->inputSchema(), $input);
        } catch (AiToolInputException $exception) {
            $outcome = AiToolOutcome::invalid($exception->getMessage());

            $this->write($name, $outcome, $context, $message, is_array($input) ? $input : [], 0);

            return $outcome;
        }

        $startedAt = hrtime(true);

        try {
            $outcome = $tool->handle($arguments, $context);
        } catch (Throwable $exception) {
            /*
             * Logged in full, reported in prose.
             *
             * The message the model gets says which tool failed and nothing
             * about why: an exception message can carry a query, a path or a
             * column name, and none of that belongs in a conversation.
             */
            Log::error('An AI tool failed.', [
                'tool' => $name,
                'user_id' => $context->user->getKey(),
                'exception' => $exception::class,
                'reason' => $exception->getMessage(),
            ]);

            $outcome = AiToolOutcome::failed(
                'That lookup failed for a technical reason. Tell the person it could not be '
                .'completed rather than guessing the answer.',
                $exception::class,
            );
        }

        $this->write(
            $name,
            $outcome,
            $context,
            $message,
            $arguments,
            (int) round((hrtime(true) - $startedAt) / 1_000_000),
        );

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function write(
        string $name,
        AiToolOutcome $outcome,
        AiToolContext $context,
        ?AiChatMessage $message,
        array $arguments,
        int $durationMs,
    ): void {
        $this->audit->record(
            tool: $name,
            category: AiToolInvocation::CATEGORY_READ,
            outcome: $outcome->outcome,
            success: $outcome->success,
            user: $context->user,
            session: $context->session,
            board: $context->board(),
            message: $message,
            target: $outcome->target,
            input: $arguments,
            note: $outcome->note,
            resultCharacters: $outcome->characters(),
            durationMs: $durationMs,
        );
    }
}
