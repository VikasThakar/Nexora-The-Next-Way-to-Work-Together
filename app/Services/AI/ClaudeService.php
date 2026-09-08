<?php

declare(strict_types=1);

namespace App\Services\AI;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Messages\InputJSONDelta;
use Anthropic\Messages\Message;
use Anthropic\Messages\RawContentBlockDeltaEvent;
use Anthropic\Messages\RawContentBlockStartEvent;
use Anthropic\Messages\RawMessageDeltaEvent;
use Anthropic\Messages\RawMessageStartEvent;
use Anthropic\Messages\TextBlock;
use Anthropic\Messages\TextDelta;
use Anthropic\Messages\Tool;
use Anthropic\Messages\ToolUseBlock;
use App\Services\AI\Data\AiCompletion;
use App\Services\AI\Data\AiPrompt;
use App\Services\AI\Data\AiTool;
use App\Services\AI\Data\AiToolCall;
use App\Services\AI\Exceptions\AiProviderException;
use GuzzleHttp\Client as GuzzleClient;
use Throwable;

/**
 * The only class in the application that talks to Anthropic.
 *
 * Everything vendor-specific stops here: the SDK import, the model id, the
 * response block union, the exception hierarchy. Callers hand over an AiPrompt
 * and get back an AiCompletion, so replacing or adding a provider is a new
 * implementation of AiProviderInterface rather than an edit to every caller.
 *
 * The credential
 * --------------
 * Read from config, which reads it from the environment, and handed straight to
 * the SDK client. It is never assigned to a property that a dump could reach,
 * never interpolated into a message, and never logged: the exception translation
 * below deliberately reconstructs its own prose from the HTTP status rather than
 * forwarding the SDK's message verbatim, and the request body — which is the
 * only part of the exchange this class serialises — contains no headers.
 *
 * The client is built lazily. A deployment with no key still boots, and every
 * feature except AI keeps working; isConfigured() is what the run pipeline
 * checks so a missing credential is reported once, against the run, instead of
 * throwing per attempt.
 *
 * Timeouts
 * --------
 * The SDK delegates the timeout to whatever PSR-18 transport it is given, so a
 * Guzzle client with an explicit timeout is supplied here. Without it a hung
 * connection would sit until the queue worker's own timeout killed the job,
 * which loses the diagnosis.
 */
class ClaudeService implements AiProviderInterface
{
    private ?Client $client = null;

    public function name(): string
    {
        return 'anthropic';
    }

    public function isConfigured(): bool
    {
        return (bool) config('ai.enabled') && filled($this->apiKey());
    }

    public function complete(AiPrompt $prompt): AiCompletion
    {
        if (! $this->isConfigured()) {
            throw AiProviderException::notConfigured();
        }

        try {
            $message = $this->client()->messages->create(
                maxTokens: $prompt->maxOutputTokens,
                messages: $this->messages($prompt),
                model: $prompt->model,
                outputConfig: $this->outputConfig($prompt),
                system: $prompt->system,
                // Adaptive thinking: the model decides how much reasoning a
                // given ticket deserves. Depth is steered by effort above.
                thinking: ['type' => 'adaptive'],
                tools: $this->tools($prompt),
            );
        } catch (APITimeoutException) {
            throw AiProviderException::timedOut();
        } catch (APIConnectionException $exception) {
            throw AiProviderException::transport($exception->getMessage());
        } catch (APIStatusException $exception) {
            throw $this->translate($exception);
        } catch (AiProviderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // An unexpected class from the SDK or the transport. Wrapped rather
            // than propagated so the failure handler has one type to catch and
            // the note reads the same as any other provider failure.
            throw AiProviderException::transport($exception->getMessage());
        }

        return $this->toCompletion($message);
    }

    /**
     * The same request, read as it arrives.
     *
     * The SDK returns a plain iterator of server-sent events with no
     * accumulator of its own, so the message is reassembled here from the
     * events and handed to the same toCompletion()-shaped interpretation as a
     * blocking call. That keeps one definition of "what a completion is": tool
     * calls, usage, refusals and empty answers all behave identically whether
     * the caller streamed or not.
     *
     * `$onText` is called with every prose fragment, and with an empty string on
     * every other event. The empty calls are what keep the connection warm: the
     * event union this SDK exposes has no `ping` variant, so during a long
     * thinking phase the only traffic available is the events we do get, and a
     * caller that is writing to an HTTP response needs to flush *something*
     * before nginx's read timeout. See the note in AiProviderInterface.
     */
    public function completeStreamed(AiPrompt $prompt, callable $onText): AiCompletion
    {
        if (! $this->isConfigured()) {
            throw AiProviderException::notConfigured();
        }

        $text = '';
        $toolCalls = [];
        $partialToolJson = [];
        $toolNames = [];
        $toolIds = [];

        $model = $prompt->model;
        $responseId = null;
        $inputTokens = null;
        $outputTokens = null;
        $stopReason = null;
        $refusalCategory = null;

        $stream = null;

        try {
            $stream = $this->client()->messages->createStream(
                maxTokens: $prompt->maxOutputTokens,
                messages: $this->messages($prompt),
                model: $prompt->model,
                outputConfig: $this->outputConfig($prompt),
                system: $prompt->system,
                thinking: ['type' => 'adaptive'],
                tools: $this->tools($prompt),
            );

            foreach ($stream as $event) {
                if ($event instanceof RawMessageStartEvent) {
                    $model = $event->message->model;
                    $responseId = $event->message->id;
                    $inputTokens = $event->message->usage->inputTokens;

                    $onText('');

                    continue;
                }

                if ($event instanceof RawContentBlockStartEvent) {
                    // A tool block announces its name and id up front; its
                    // arguments arrive afterwards as JSON fragments.
                    if ($event->contentBlock instanceof ToolUseBlock) {
                        $toolNames[$event->index] = $event->contentBlock->name;
                        $toolIds[$event->index] = $event->contentBlock->id;
                        $partialToolJson[$event->index] = '';
                    }

                    $onText('');

                    continue;
                }

                if ($event instanceof RawContentBlockDeltaEvent) {
                    if ($event->delta instanceof TextDelta) {
                        $text .= $event->delta->text;

                        $onText($event->delta->text);

                        continue;
                    }

                    if ($event->delta instanceof InputJSONDelta) {
                        $partialToolJson[$event->index] =
                            ($partialToolJson[$event->index] ?? '').$event->delta->partialJSON;
                    }

                    // Thinking and signature deltas reach here too. They are
                    // not shown — the product stores conclusions, not reasoning
                    // traces — but they still count as a sign of life.
                    $onText('');

                    continue;
                }

                if ($event instanceof RawMessageDeltaEvent) {
                    $stopReason = $event->delta->stopReason;
                    $refusalCategory = $event->delta->stopDetails?->category;
                    $outputTokens = $event->usage->outputTokens;

                    $onText('');

                    continue;
                }

                $onText('');
            }
        } catch (APITimeoutException) {
            throw AiProviderException::timedOut();
        } catch (APIConnectionException $exception) {
            throw AiProviderException::transport($exception->getMessage());
        } catch (APIStatusException $exception) {
            throw $this->translate($exception);
        } catch (AiProviderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw AiProviderException::transport($exception->getMessage());
        } finally {
            // A caller that aborts mid-iteration must not leave the socket open
            // for the rest of the request.
            $stream?->close();
        }

        foreach ($partialToolJson as $index => $json) {
            $input = json_decode($json === '' ? '{}' : $json, true);

            $toolCalls[] = new AiToolCall(
                name: $toolNames[$index] ?? '',
                input: is_array($input) ? $input : [],
                id: $toolIds[$index] ?? '',
            );
        }

        $completion = new AiCompletion(
            text: trim($text),
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            model: $model,
            stopReason: $stopReason,
            toolCalls: $toolCalls,
            metadata: [
                'provider' => $this->name(),
                'response_id' => $responseId,
                'streamed' => true,
            ],
        );

        // Identical handling to the blocking path, deliberately: a refusal is
        // an answer but not a result, and an empty answer is a fault.
        if ($completion->wasRefused()) {
            throw AiProviderException::refused($refusalCategory);
        }

        if (! $completion->hasText() && $completion->toolCalls === []) {
            throw AiProviderException::emptyResponse();
        }

        return $completion;
    }

    // -----------------------------------------------------------------
    // Request construction
    // -----------------------------------------------------------------

    /**
     * @return list<array{role: string, content: string}>
     */
    private function messages(AiPrompt $prompt): array
    {
        $messages = array_values(array_filter(
            $prompt->messages,
            static fn (array $message): bool => trim((string) ($message['content'] ?? '')) !== ''
        ));

        // The API requires at least one message and requires the first to be
        // from the user. A prompt that arrives empty is a bug upstream, but it
        // must not become an opaque 400 from the provider.
        if ($messages === [] || ($messages[0]['role'] ?? null) !== 'user') {
            throw AiProviderException::transport('The prompt had no leading user message.');
        }

        return $messages;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function outputConfig(AiPrompt $prompt): ?array
    {
        $effort = $prompt->effort ?? config('ai.model.effort');

        return filled($effort) ? ['effort' => (string) $effort] : null;
    }

    /**
     * @return list<Tool>|null
     */
    private function tools(AiPrompt $prompt): ?array
    {
        if (! $prompt->hasTools()) {
            return null;
        }

        return array_map(
            static fn (AiTool $tool): Tool => Tool::with(
                inputSchema: $tool->inputSchema,
                name: $tool->name,
                description: $tool->description,
            ),
            $prompt->tools
        );
    }

    // -----------------------------------------------------------------
    // Response interpretation
    // -----------------------------------------------------------------

    private function toCompletion(Message $message): AiCompletion
    {
        $text = [];
        $toolCalls = [];

        // content is a union of block types. Thinking blocks are present and
        // deliberately dropped: the run stores what the model concluded, not
        // its reasoning trace, which is neither stable nor useful in a note.
        foreach ($message->content as $block) {
            if ($block instanceof TextBlock) {
                $text[] = $block->text;

                continue;
            }

            if ($block instanceof ToolUseBlock) {
                $toolCalls[] = new AiToolCall(
                    name: $block->name,
                    input: $block->input,
                    id: $block->id,
                );
            }
        }

        $completion = new AiCompletion(
            text: trim(implode("\n\n", $text)),
            inputTokens: $message->usage->inputTokens,
            outputTokens: $message->usage->outputTokens,
            model: $message->model,
            stopReason: $message->stopReason,
            toolCalls: $toolCalls,
            metadata: [
                'provider' => $this->name(),
                'response_id' => $message->id,
            ],
        );

        // A refusal is a considered answer rather than a fault, but it is not a
        // result either: fail the run so the note says what happened instead of
        // posting an empty assessment.
        if ($completion->wasRefused()) {
            throw AiProviderException::refused($message->stopDetails?->category);
        }

        if (! $completion->hasText() && $completion->toolCalls === []) {
            throw AiProviderException::emptyResponse();
        }

        return $completion;
    }

    /**
     * Turn an SDK status exception into advice a member of staff can act on.
     *
     * The SDK's own message embeds the full response body, which is verbose and
     * of no use in a ticket note, so the prose is rebuilt from the status. The
     * body's `error.message` is the exception: it is the one part that usually
     * says something specific, and it is provider prose, not a secret.
     */
    private function translate(APIStatusException $exception): AiProviderException
    {
        $status = (int) $exception->status;

        return match (true) {
            $status === 401, $status === 403 => AiProviderException::unauthorized(),
            $status === 429 => AiProviderException::rateLimited(),
            $status === 529 => AiProviderException::overloaded(),
            $status >= 500 => AiProviderException::status($status, 'the provider reported a server error'),
            default => AiProviderException::status($status, $this->providerMessage($exception)),
        };
    }

    private function providerMessage(APIStatusException $exception): string
    {
        $body = $exception->response?->getBody();

        if ($body === null) {
            return 'no details supplied';
        }

        try {
            $decoded = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return 'no details supplied';
        }

        $message = data_get($decoded, 'error.message');

        return is_string($message) && trim($message) !== ''
            ? mb_substr(trim($message), 0, 500)
            : 'no details supplied';
    }

    // -----------------------------------------------------------------
    // Client
    // -----------------------------------------------------------------

    private function client(): Client
    {
        return $this->client ??= new Client(
            apiKey: $this->apiKey(),
            baseUrl: filled(config('ai.anthropic.base_url'))
                ? (string) config('ai.anthropic.base_url')
                : null,
            requestOptions: [
                'maxRetries' => (int) config('ai.anthropic.max_retries', 2),
                'timeout' => (float) config('ai.anthropic.timeout', 300),
                // The SDK enforces no timeout of its own; the transport does.
                'transporter' => new GuzzleClient([
                    'timeout' => (float) config('ai.anthropic.timeout', 300),
                    'connect_timeout' => 15.0,
                ]),
            ],
        );
    }

    private function apiKey(): string
    {
        return (string) config('ai.anthropic.api_key');
    }
}
