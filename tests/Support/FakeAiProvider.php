<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\AI\AiProviderInterface;
use App\Services\AI\Data\AiCompletion;
use App\Services\AI\Data\AiPrompt;
use App\Services\AI\Data\AiToolCall;
use App\Services\AI\Exceptions\AiProviderException;

/**
 * The provider used by every test.
 *
 * This is why AiProviderInterface exists. Bound into the container in place of
 * ClaudeService, it makes reaching Anthropic from a test *impossible* rather than
 * merely discouraged: there is no HTTP client to intercept, no base URL to
 * redirect and no environment variable anybody has to remember to unset. A test
 * cannot spend money by accident.
 *
 * It also records every prompt it was given, which is what lets the security
 * tests assert on what was *sent* — that a board's context never contains another
 * board's tickets, for instance — rather than only on what came back.
 */
class FakeAiProvider implements AiProviderInterface
{
    /** @var list<AiPrompt> */
    public array $prompts = [];

    /** @var list<AiToolCall> */
    public array $toolCalls = [];

    public string $text = "## Summary\nA fake analysis.\n\n## Understanding\nNothing real happened here.";

    public ?int $inputTokens = 1200;

    public ?int $outputTokens = 350;

    public ?string $model = 'claude-opus-5';

    public ?string $stopReason = 'end_turn';

    public bool $configured = true;

    public ?AiProviderException $throws = null;

    public int $calls = 0;

    /**
     * Answers to give in order, one per provider call.
     *
     * Needed because the assistant is now a loop: a question can take several
     * provider calls, and a fake that answers identically every time cannot
     * express "ask for a lookup, then answer with what came back" — which is
     * the behaviour the retrieval layer exists for.
     *
     * Each entry is a text and a list of tool calls. When the script runs out
     * the fake falls back to its configured text, so every existing test that
     * never touches this is unaffected.
     *
     * @var list<array{text: string, toolCalls: list<AiToolCall>}>
     */
    public array $script = [];

    /**
     * Tool results the loop handed back, in order.
     *
     * This is what a test asserts on to prove a lookup really happened and
     * that its material reached the model — the strongest available check that
     * the loop is wired up, since it is the model's own view of the answer.
     *
     * @var list<string>
     */
    public array $toolResults = [];

    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    /**
     * Every fragment handed to a streaming caller, in order.
     *
     * Asserting on this is how a test checks that a caller streamed at all,
     * rather than quietly falling back to one big write at the end.
     *
     * @var list<string>
     */
    public array $streamedChunks = [];

    public function complete(AiPrompt $prompt): AiCompletion
    {
        $this->calls++;
        $this->prompts[] = $prompt;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        [$text, $toolCalls] = $this->next();

        return new AiCompletion(
            text: $text,
            inputTokens: $this->inputTokens,
            outputTokens: $this->outputTokens,
            model: $this->model,
            stopReason: $this->stopReason,
            toolCalls: $toolCalls,
            metadata: ['provider' => 'fake'],
        );
    }

    /**
     * Stream the configured answer a word at a time.
     *
     * Chunked rather than emitted whole so a test can tell real progressive
     * delivery from a single write, and so the accumulation the caller performs
     * is actually exercised. The failure path throws *before* any chunk, which
     * is the order the real provider fails in when the request is rejected.
     */
    public function completeStreamed(AiPrompt $prompt, callable $onText): AiCompletion
    {
        $this->calls++;
        $this->prompts[] = $prompt;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        // An empty leading call, as the real provider makes on message_start.
        // Callers have to tolerate it, so the fake produces it.
        $onText('');
        $this->streamedChunks[] = '';

        [$text, $toolCalls] = $this->next();

        foreach ($this->chunks($text) as $chunk) {
            $onText($chunk);
            $this->streamedChunks[] = $chunk;
        }

        return new AiCompletion(
            text: $text,
            inputTokens: $this->inputTokens,
            outputTokens: $this->outputTokens,
            model: $this->model,
            stopReason: $this->stopReason,
            toolCalls: $toolCalls,
            metadata: ['provider' => 'fake', 'streamed' => true],
        );
    }

    /**
     * The next scripted answer, or the configured one.
     *
     * Every call also records whatever tool results arrived on the prompt, so a
     * test can see exactly what the model was shown.
     *
     * @return array{0: string, 1: list<AiToolCall>}
     */
    private function next(): array
    {
        $prompt = $this->lastPrompt();

        if ($prompt !== null) {
            foreach ($prompt->toolExchanges as $exchange) {
                foreach ($exchange->results as $result) {
                    $this->toolResults[] = $result->content;
                }
            }
        }

        if ($this->script !== []) {
            $step = array_shift($this->script);

            return [$step['text'], $step['toolCalls']];
        }

        return [$this->text, $this->toolCalls];
    }

    /**
     * Split text into fragments that reassemble to exactly the original.
     *
     * @return list<string>
     */
    private function chunks(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $pieces = preg_split('/(?<=\s)/', $text) ?: [$text];

        return array_values(array_filter($pieces, static fn (string $piece): bool => $piece !== ''));
    }

    // -----------------------------------------------------------------
    // Test helpers
    // -----------------------------------------------------------------

    public function willReturn(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    public function willFail(AiProviderException $exception): self
    {
        $this->throws = $exception;

        return $this;
    }

    /**
     * Make the next answer propose an action.
     *
     * @param  array<string, mixed>  $input
     */
    public function willPropose(string $toolName, array $input): self
    {
        $this->toolCalls = [new AiToolCall(name: $toolName, input: $input, id: 'toolu_fake')];

        return $this;
    }

    /**
     * Ask for one lookup, then answer.
     *
     * The two-step script the retrieval loop is built for: the first provider
     * call asks for a tool, the second — which is the one that receives the
     * tool result — produces the answer.
     *
     * @param  array<string, mixed>  $input
     */
    public function willLookUp(string $toolName, array $input, string $answer = 'Answered from the lookup.'): self
    {
        $this->script = [
            ['text' => '', 'toolCalls' => [new AiToolCall(name: $toolName, input: $input, id: 'toolu_lookup')]],
            ['text' => $answer, 'toolCalls' => []],
        ];

        return $this;
    }

    /**
     * Everything the loop handed back to the model, as one string.
     *
     * Asserting on this is how a test proves the material a tool returned
     * actually reached the model — and, just as importantly, that material it
     * must never see did not.
     */
    public function toolResultText(): string
    {
        return implode("\n", $this->toolResults);
    }

    /**
     * Report no usage at all, so the "unknown cost stays null" path is exercised.
     */
    public function willReportNoUsage(): self
    {
        $this->inputTokens = null;
        $this->outputTokens = null;

        return $this;
    }

    public function lastPrompt(): ?AiPrompt
    {
        return $this->prompts === [] ? null : $this->prompts[count($this->prompts) - 1];
    }

    /**
     * Everything that was sent on the last call: system prompt and every message.
     *
     * Used by the security tests to assert on the whole payload at once.
     */
    public function lastPayload(): string
    {
        $prompt = $this->lastPrompt();

        if ($prompt === null) {
            return '';
        }

        return $prompt->system."\n".implode(
            "\n",
            array_map(static fn (array $message): string => (string) $message['content'], $prompt->messages)
        );
    }
}
