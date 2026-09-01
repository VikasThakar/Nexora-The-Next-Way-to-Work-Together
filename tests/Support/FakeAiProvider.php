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

    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function complete(AiPrompt $prompt): AiCompletion
    {
        $this->calls++;
        $this->prompts[] = $prompt;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return new AiCompletion(
            text: $this->text,
            inputTokens: $this->inputTokens,
            outputTokens: $this->outputTokens,
            model: $this->model,
            stopReason: $this->stopReason,
            toolCalls: $this->toolCalls,
            metadata: ['provider' => 'fake'],
        );
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
