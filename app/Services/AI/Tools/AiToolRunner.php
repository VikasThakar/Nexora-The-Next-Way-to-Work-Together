<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Enums\AiActionType;
use App\Services\AI\AiProviderInterface;
use App\Services\AI\Data\AiCompletion;
use App\Services\AI\Data\AiPrompt;
use App\Services\AI\Data\AiToolCall;
use App\Services\AI\Data\AiToolExchange;
use App\Services\AI\Data\AiToolResult;
use App\Services\AI\Exceptions\AiProviderException;

/**
 * The retrieval loop: ask, look things up, ask again, answer.
 *
 * This is the only place in the application that executes a tool call, and it
 * executes exactly one kind — a read from AiToolRegistry. Everything about it
 * is bounded, and the bounds are the design:
 *
 *   rounds        `ai.tools.max_rounds`, four by default. A model that has not
 *                 answered after four rounds of lookups is not going to, and
 *                 an unbounded loop is an unbounded bill.
 *   calls         `ai.tools.max_calls_per_round`. A round asking for twenty
 *                 lookups is a model exploring rather than answering.
 *   material      each tool bounds its own output, and the total is capped
 *                 here as well, because ten tools that each behave can still
 *                 add up to a context window.
 *
 * Proposals stop the loop
 * -----------------------
 * A `propose_*` call is not executed and never will be — it is a description
 * of a change for a human to confirm. When one appears the loop stops and the
 * completion is returned with that call intact, so
 * App\Services\AI\WorkspaceChatService stores it as a proposal exactly as it
 * did before this class existed.
 *
 * If a turn contains both a proposal and a read, the loop still stops. It has
 * to: both providers require every tool call in an assistant turn to be
 * answered in the next turn, and a proposal has no answer — it has a confirm
 * button. Stopping means nothing is left unanswered, because there is no next
 * turn.
 *
 * Streaming
 * ---------
 * Every round is streamed when the caller streams, so prose the model writes
 * before a lookup reaches the person immediately. Between rounds `$onText` is
 * called with an empty string: a tool round produces no provider traffic, and
 * an empty write is how a caller holding an HTTP response open learns the
 * connection is still alive. See AiProviderInterface.
 */
class AiToolRunner
{
    public function __construct(private readonly AiToolRegistry $registry) {}

    /**
     * Run one question to completion.
     *
     * @param  (callable(string): void)|null  $onText
     *
     * @throws AiProviderException
     */
    public function run(
        AiProviderInterface $provider,
        AiPrompt $prompt,
        AiToolContext $context,
        ?callable $onText = null,
    ): AiToolRun {
        $maxRounds = max(0, (int) config('ai.tools.max_rounds', 4));
        $maxCalls = max(1, (int) config('ai.tools.max_calls_per_round', 6));
        $characterBudget = max(1000, (int) config('ai.tools.max_result_characters', 60000));

        $prose = [];
        $inputTokens = null;
        $outputTokens = null;
        $invocations = [];
        $spent = 0;
        $rounds = 0;
        $completion = null;
        $budgetExhausted = false;

        while (true) {
            $completion = $onText === null
                ? $provider->complete($prompt)
                : $provider->completeStreamed($prompt, $onText);

            if ($completion->hasText()) {
                $prose[] = $completion->text;
            }

            // Added rather than replaced. Every round is a billed request, and
            // a total that reported only the last one would understate what a
            // question with three lookups actually cost.
            $inputTokens = $this->add($inputTokens, $completion->inputTokens);
            $outputTokens = $this->add($outputTokens, $completion->outputTokens);

            $calls = array_values(array_filter(
                $completion->toolCalls,
                static fn ($call): bool => $call instanceof AiToolCall,
            ));

            if ($calls === []) {
                break;
            }

            // A proposal ends the exchange. See the class comment.
            if ($this->containsProposal($calls)) {
                break;
            }

            if ($rounds >= $maxRounds || $budgetExhausted) {
                /*
                 * Out of budget, with lookups still outstanding.
                 *
                 * The calls are answered — with an error result saying the
                 * budget is spent — rather than dropped. Dropping them would
                 * leave the provider's own transcript inconsistent, and both
                 * vendors reject a turn whose tool calls went unanswered. This
                 * way the model gets one more turn in which to answer from
                 * what it has, which is what somebody waiting actually wants.
                 */
                $prompt = $prompt->withToolExchange(new AiToolExchange(
                    calls: $calls,
                    results: array_map(
                        static fn (AiToolCall $call): AiToolResult => new AiToolResult(
                            toolUseId: (string) $call->id,
                            content: 'The lookup budget for this question is used up. Answer now from '
                                .'what you already have, and say plainly what you could not check.',
                            isError: true,
                        ),
                        $calls,
                    ),
                    text: $completion->text,
                ));

                $rounds++;

                $completion = $onText === null
                    ? $provider->complete($prompt)
                    : $provider->completeStreamed($prompt, $onText);

                if ($completion->hasText()) {
                    $prose[] = $completion->text;
                }

                $inputTokens = $this->add($inputTokens, $completion->inputTokens);
                $outputTokens = $this->add($outputTokens, $completion->outputTokens);

                break;
            }

            $results = [];

            foreach (array_slice($calls, 0, $maxCalls) as $call) {
                if ($call->id === null) {
                    // Without an id there is nothing to answer, and a provider
                    // that omitted one has not really asked for a tool.
                    continue;
                }

                $outcome = $this->registry->invoke($call->name, $call->input, $context);

                $text = $outcome->text;

                if ($spent + mb_strlen($text) > $characterBudget) {
                    $remaining = max(0, $characterBudget - $spent);

                    $text = $remaining < 200
                        ? 'The material budget for this question is used up. Answer from what you '
                            .'already have and say what you could not read.'
                        : mb_substr($text, 0, $remaining)
                            ."\n\n[Cut short: the material budget for this question is used up.]";

                    $budgetExhausted = true;
                }

                $spent += mb_strlen($text);

                $results[] = new AiToolResult(
                    toolUseId: $call->id,
                    content: $text,
                    isError: ! $outcome->success,
                );

                $invocations[] = [
                    'tool' => $call->name,
                    'target' => $outcome->target,
                    'success' => $outcome->success,
                ];
            }

            /*
             * Every call answered, or none at all.
             *
             * A round where the counts do not match is a request both vendors
             * refuse, so rather than send it the loop stops and the answer is
             * whatever prose the model has already written. That is the safe
             * failure: an incomplete answer rather than a 400.
             */
            if ($results === [] || count($results) !== count($calls)) {
                break;
            }

            $prompt = $prompt->withToolExchange(new AiToolExchange(
                calls: $calls,
                results: $results,
                text: $completion->text,
            ));

            $rounds++;

            // Keeps the response warm across the boundary between two provider
            // calls, where no vendor event is arriving.
            if ($onText !== null) {
                $onText('');
            }
        }

        return new AiToolRun(
            completion: $this->merge($completion, $prose, $inputTokens, $outputTokens),
            rounds: $rounds,
            invocations: $invocations,
        );
    }

    // -----------------------------------------------------------------

    /**
     * Is any of these a change for a human to confirm?
     *
     * Asked of AiActionType rather than of "is it absent from the registry",
     * which would be the easy version and the wrong one: a name the model
     * invented is also absent from the registry, and treating that as a
     * proposal would end the exchange with nothing to show. An unknown name
     * goes to the registry instead, which answers it with a refusal the model
     * can recover from — and records the attempt.
     *
     * @param  list<AiToolCall>  $calls
     */
    private function containsProposal(array $calls): bool
    {
        foreach ($calls as $call) {
            if (AiActionType::fromToolName($call->name) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * One completion describing the whole exchange.
     *
     * @param  list<string>  $prose
     */
    private function merge(
        ?AiCompletion $last,
        array $prose,
        ?int $inputTokens,
        ?int $outputTokens,
    ): AiCompletion {
        if ($last === null) {
            // Unreachable: the loop always runs at least once, and a provider
            // that answers nothing throws. Stated rather than assumed.
            throw AiProviderException::emptyResponse();
        }

        $text = trim(implode("\n\n", array_filter(
            $prose,
            static fn (string $chunk): bool => trim($chunk) !== '',
        )));

        return new AiCompletion(
            text: $text,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            model: $last->model,
            stopReason: $last->stopReason,
            toolCalls: $last->toolCalls,
            metadata: $last->metadata,
        );
    }

    /**
     * Null plus a number is that number; null plus null stays null.
     *
     * Null means "the provider did not report usage" everywhere in this
     * codebase, and it has to survive addition — a session whose provider
     * reports nothing must read "not available" rather than zero.
     */
    private function add(?int $carried, ?int $next): ?int
    {
        if ($next === null) {
            return $carried;
        }

        return ($carried ?? 0) + $next;
    }
}
