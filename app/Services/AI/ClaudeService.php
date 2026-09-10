<?php

declare(strict_types=1);

namespace App\Services\AI;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Messages\Base64ImageSource;
use Anthropic\Messages\ImageBlockParam;
use Anthropic\Messages\InputJSONDelta;
use Anthropic\Messages\Message;
use Anthropic\Messages\MessageParam;
use Anthropic\Messages\RawContentBlockDeltaEvent;
use Anthropic\Messages\RawContentBlockStartEvent;
use Anthropic\Messages\RawMessageDeltaEvent;
use Anthropic\Messages\RawMessageStartEvent;
use Anthropic\Messages\TextBlock;
use Anthropic\Messages\TextBlockParam;
use Anthropic\Messages\TextDelta;
use Anthropic\Messages\Tool;
use Anthropic\Messages\ToolResultBlockParam;
use Anthropic\Messages\ToolUseBlock;
use Anthropic\Messages\ToolUseBlockParam;
use App\Services\AI\Data\AiCompletion;
use App\Services\AI\Data\AiMedia;
use App\Services\AI\Data\AiPrompt;
use App\Services\AI\Data\AiTool;
use App\Services\AI\Data\AiToolCall;
use App\Services\AI\Data\AiToolExchange;
use App\Services\AI\Data\AiToolResult;
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

    /**
     * The credential and endpoint this instance will use.
     *
     * Both are optional and both fall back to config, which is why the
     * container can still bind this class with no arguments and why a
     * deployment that only ever set ANTHROPIC_API_KEY keeps working unchanged.
     *
     * They exist because a key is no longer a deployment-wide constant: it can
     * be stored by an administrator, or belong to one board billed to its own
     * account. App\Services\AI\AiProviderRegistry resolves which one applies
     * and constructs an instance with it, per request rather than per process —
     * a singleton holding one board's key would eventually send it for another.
     *
     * The value is assigned to a readonly promoted property and read by
     * apiKey() alone. It is never logged, never interpolated into a message and
     * never returned: the exception translation below rebuilds its own prose
     * from the HTTP status precisely so that no part of the exchange that could
     * carry a header reaches a note.
     */
    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $baseUrl = null,
    ) {}

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
     * @return list<array{role: string, content: string}|MessageParam>
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

        $messages = $prompt->hasMedia() ? $this->withMedia($messages, $prompt) : $messages;

        return $prompt->hasToolExchanges()
            ? $this->withToolExchanges($messages, $prompt)
            : $messages;
    }

    /**
     * Replay the tool rounds that have already happened.
     *
     * Each round becomes two turns: the assistant turn holding every tool_use
     * block it asked for, then a user turn holding every matching
     * tool_result. The API requires that pairing to be exact — every call
     * answered, in the same turn, by id — which is why a round is the unit
     * rather than a call. See AiToolExchange.
     *
     * The rounds go after the question, because that is when they happened:
     * the person asked, the model asked for a lookup, the lookup answered.
     *
     * @param  list<array{role: string, content: string}|MessageParam>  $messages
     * @return list<array{role: string, content: string}|MessageParam>
     */
    private function withToolExchanges(array $messages, AiPrompt $prompt): array
    {
        foreach ($prompt->toolExchanges as $exchange) {
            if (! $exchange instanceof AiToolExchange || $exchange->isEmpty()) {
                continue;
            }

            $blocks = [];

            // Any prose the model wrote alongside its tool calls. Kept,
            // because a transcript that drops it does not match what the
            // model actually said.
            if (trim($exchange->text) !== '') {
                $blocks[] = TextBlockParam::with(text: $exchange->text);
            }

            foreach ($exchange->calls as $call) {
                if (! $call instanceof AiToolCall || $call->id === null) {
                    continue;
                }

                $blocks[] = ToolUseBlockParam::with(
                    id: $call->id,
                    input: $call->input,
                    name: $call->name,
                );
            }

            $results = [];

            foreach ($exchange->results as $result) {
                if (! $result instanceof AiToolResult) {
                    continue;
                }

                $results[] = ToolResultBlockParam::with(
                    toolUseID: $result->toolUseId,
                    content: $result->content,
                    isError: $result->isError ?: null,
                );
            }

            // A round with nothing on either side is dropped whole: half a
            // round is a request the API refuses.
            if ($blocks === [] || $results === []) {
                continue;
            }

            $messages[] = MessageParam::with(content: $blocks, role: 'assistant');
            $messages[] = MessageParam::with(content: $results, role: 'user');
        }

        return array_values($messages);
    }

    /**
     * Rebuild the final turn as content blocks, with the pictures after the
     * text.
     *
     * Attached to the *last* message rather than sent as a turn of their own,
     * because that is where the question is: an image block in an earlier turn
     * is context the model has to hold, and one in the final turn is the thing
     * being asked about. Text first, then images, which is the order Anthropic
     * documents as reading best.
     *
     * The last message is always the user's — messages() has just established
     * that the first one is, and App\Services\AI\WorkspaceChatService appends
     * the question last — but the role is taken from the message rather than
     * assumed, so this stays correct if that ever changes.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return list<array{role: string, content: string}|MessageParam>
     */
    private function withMedia(array $messages, AiPrompt $prompt): array
    {
        $last = array_pop($messages);

        $blocks = [TextBlockParam::with(text: (string) $last['content'])];

        foreach ($prompt->media as $media) {
            if (! $media instanceof AiMedia) {
                continue;
            }

            /*
             * Base64 rather than a URL, deliberately. A URL source would mean
             * handing the provider a link to a private file — public, or signed
             * and therefore public with an expiry. Sending the bytes means the
             * file is never addressable from outside this application at all.
             * See AiMedia.
             */
            $blocks[] = ImageBlockParam::with(
                source: Base64ImageSource::with(
                    data: $media->base64,
                    mediaType: $media->mediaType,
                ),
            );
        }

        $messages[] = MessageParam::with(
            content: $blocks,
            role: (string) ($last['role'] ?? 'user'),
        );

        return array_values($messages);
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
            baseUrl: filled($this->baseUrl())
                ? $this->baseUrl()
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

    /**
     * The credential handed to the SDK, and nowhere else.
     *
     * The injected value when the registry supplied one, otherwise whatever
     * the environment configured. Reading it through one method is what lets
     * the class comment above promise that exactly one line of code touches it.
     */
    private function apiKey(): string
    {
        return (string) ($this->apiKey ?? config('ai.anthropic.api_key'));
    }

    private function baseUrl(): ?string
    {
        $baseUrl = trim((string) ($this->baseUrl ?? config('ai.anthropic.base_url')));

        return $baseUrl === '' ? null : $baseUrl;
    }
}
