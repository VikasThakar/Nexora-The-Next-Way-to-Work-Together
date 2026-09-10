<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Services\AI\Data\AiCompletion;
use App\Services\AI\Data\AiMedia;
use App\Services\AI\Data\AiPrompt;
use App\Services\AI\Data\AiTool;
use App\Services\AI\Data\AiToolCall;
use App\Services\AI\Data\AiToolExchange;
use App\Services\AI\Data\AiToolResult;
use App\Services\AI\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The second provider: OpenAI's chat completions API.
 *
 * Written against the HTTP API through Laravel's client rather than against an
 * SDK, and that is a deliberate choice rather than an omission. This project
 * has no OpenAI SDK in its dependencies, the surface used here is four fields
 * of one endpoint, and Http::fake() makes the whole adapter testable without a
 * network or a credential — which an SDK would not, for the same reason
 * ClaudeService needs AiProviderInterface to be fakeable at all.
 *
 * Everything vendor-specific stops here, exactly as it does in ClaudeService:
 * the endpoint, the request shape, the `tool_calls` structure, the `usage`
 * block, the error envelope. Callers hand over an AiPrompt and get an
 * AiCompletion, and nothing above this line knows which vendor answered.
 *
 * What this adapter does not pretend
 * ----------------------------------
 * Two honest gaps, both visible in the code rather than papered over:
 *
 *   the catalogue ships no context window, no category and no pricing for
 *   OpenAI models, because this deployment has no first-party source for them.
 *   An unpriced model produces a null cost, which reads as "not available"
 *   rather than as free;
 *
 *   the model ids come from configuration (AI_OPENAI_MODELS), because which
 *   models an account can reach is a property of the account. A model the
 *   account does not have produces a 404 from OpenAI, translated below into
 *   advice naming the setting to change.
 *
 * Streaming
 * ---------
 * Real server-sent events, parsed here. The interface permits an adapter that
 * cannot stream to call `$onText` once at the end, and taking that shortcut
 * would have made a long answer look like a hang for its whole duration —
 * which is precisely the failure the streaming path exists to avoid. Every
 * event, including one that carries no prose, results in a call to `$onText`,
 * because an empty call is how the caller learns the connection is alive.
 */
class OpenAiService implements AiProviderInterface
{
    /**
     * See ClaudeService's constructor for why the credential is injected
     * rather than read: it is per board and per workspace, not per deployment.
     */
    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $baseUrl = null,
    ) {}

    public function name(): string
    {
        return 'openai';
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

        $response = $this->send($this->payload($prompt, stream: false));

        return $this->toCompletion($this->decode($response), $prompt);
    }

    /**
     * The same request, read as it arrives.
     *
     * The stream is a sequence of `data: {...}` lines ending with
     * `data: [DONE]`. Each frame carries a delta of either prose or a tool
     * call's arguments, and the last frame carries the usage block — which is
     * why `stream_options.include_usage` is set: without it a streamed answer
     * reports no tokens at all, and this product would rather refuse to show a
     * number than show a wrong one.
     */
    public function completeStreamed(AiPrompt $prompt, callable $onText): AiCompletion
    {
        if (! $this->isConfigured()) {
            throw AiProviderException::notConfigured();
        }

        $response = $this->send($this->payload($prompt, stream: true), streamed: true);

        $text = '';
        $model = $prompt->model;
        $responseId = null;
        $inputTokens = null;
        $outputTokens = null;
        $finishReason = null;

        /** @var array<int, array{id: string, name: string, arguments: string}> $toolCalls */
        $toolCalls = [];

        try {
            foreach ($this->frames($response) as $frame) {
                // Announced once, at the top of the stream.
                $model = is_string($frame['model'] ?? null) ? $frame['model'] : $model;
                $responseId = is_string($frame['id'] ?? null) ? $frame['id'] : $responseId;

                if (is_array($frame['usage'] ?? null)) {
                    $inputTokens = $this->intOrNull($frame['usage']['prompt_tokens'] ?? null);
                    $outputTokens = $this->intOrNull($frame['usage']['completion_tokens'] ?? null);
                }

                $choice = $frame['choices'][0] ?? null;

                if (is_array($choice)) {
                    $finishReason = is_string($choice['finish_reason'] ?? null)
                        ? $choice['finish_reason']
                        : $finishReason;

                    $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];

                    $chunk = is_string($delta['content'] ?? null) ? $delta['content'] : '';

                    if ($chunk !== '') {
                        $text .= $chunk;

                        $onText($chunk);

                        continue;
                    }

                    $this->accumulateToolDeltas($delta, $toolCalls);
                }

                // A frame with no prose in it. See the class comment: the empty
                // call is what keeps the connection warm.
                $onText('');
            }
        } catch (ConnectionException $exception) {
            throw AiProviderException::transport($exception->getMessage());
        } catch (AiProviderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw AiProviderException::transport($exception->getMessage());
        }

        return $this->finish(
            text: $text,
            toolCalls: $this->decodeToolCalls($toolCalls),
            model: $model,
            responseId: $responseId,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            finishReason: $finishReason,
            streamed: true,
        );
    }

    // -----------------------------------------------------------------
    // Request construction
    // -----------------------------------------------------------------

    /**
     * Rebuild one message as a content-part array, with the pictures after the
     * text.
     *
     * OpenAI's chat API takes images as `image_url` parts whose URL may be a
     * `data:` URI, which is how the bytes are sent rather than a link — the
     * same decision ClaudeService makes and for the same reason: a link to a
     * private file would have to be public, or signed and therefore public
     * with an expiry. See App\Services\AI\Data\AiMedia.
     *
     * Only images. This API has no equivalent of a document block, and the
     * application does not need one: PDFs and Office files arrive here as
     * extracted text, which every model reads.
     *
     * @param  array{role: string, content: string}  $message
     * @param  list<AiMedia>  $media
     * @return array{role: string, content: list<array<string, mixed>>}
     */
    private function withMedia(array $message, array $media): array
    {
        $parts = [['type' => 'text', 'text' => (string) $message['content']]];

        foreach ($media as $item) {
            if (! $item instanceof AiMedia) {
                continue;
            }

            $parts[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $item->dataUri()],
            ];
        }

        return ['role' => (string) $message['role'], 'content' => $parts];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Replay the tool rounds that have already happened.
     *
     * The vendor shape differs from Anthropic's — an assistant message
     * carrying a `tool_calls` array, then one message per result with role
     * `tool` — but the requirement is the same: every call answered, matched
     * by id. A round with a call left unanswered is a 400, so a round that
     * cannot be completed is dropped whole.
     *
     * `content` on the assistant message is sent as null when the model wrote
     * no prose, which is what this API expects for a turn that is only tool
     * calls; an empty string is rejected.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private function withToolExchanges(array $messages, AiPrompt $prompt): array
    {
        foreach ($prompt->toolExchanges as $exchange) {
            if (! $exchange instanceof AiToolExchange || $exchange->isEmpty()) {
                continue;
            }

            $calls = [];

            foreach ($exchange->calls as $call) {
                if (! $call instanceof AiToolCall || $call->id === null) {
                    continue;
                }

                $calls[] = [
                    'id' => $call->id,
                    'type' => 'function',
                    'function' => [
                        'name' => $call->name,
                        // Re-encoded rather than carried as an array: this API
                        // takes the arguments as a JSON string, exactly as it
                        // emitted them.
                        'arguments' => json_encode($call->input === [] ? new \stdClass : $call->input),
                    ],
                ];
            }

            $results = [];

            foreach ($exchange->results as $result) {
                if (! $result instanceof AiToolResult) {
                    continue;
                }

                $results[] = [
                    'role' => 'tool',
                    'tool_call_id' => $result->toolUseId,
                    'content' => $result->content,
                ];
            }

            if ($calls === [] || $results === []) {
                continue;
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => trim($exchange->text) === '' ? null : $exchange->text,
                'tool_calls' => $calls,
            ];

            foreach ($results as $result) {
                $messages[] = $result;
            }
        }

        return array_values($messages);
    }

    private function payload(AiPrompt $prompt, bool $stream): array
    {
        $messages = [['role' => 'system', 'content' => $prompt->system]];

        foreach ($prompt->messages as $message) {
            $content = trim((string) ($message['content'] ?? ''));

            if ($content === '') {
                continue;
            }

            $messages[] = ['role' => (string) $message['role'], 'content' => $content];
        }

        if ($prompt->hasMedia() && count($messages) > 1) {
            $messages[count($messages) - 1] = $this->withMedia(
                $messages[count($messages) - 1],
                $prompt->media,
            );
        }

        // The same guard ClaudeService applies, for the same reason: a prompt
        // that arrives without a user turn is a bug upstream and must not
        // become an opaque 400 from a vendor.
        if (count($messages) < 2) {
            throw AiProviderException::transport('The prompt had no leading user message.');
        }

        if ($prompt->hasToolExchanges()) {
            $messages = $this->withToolExchanges($messages, $prompt);
        }

        $payload = [
            'model' => $prompt->model,
            'messages' => $messages,
            'max_completion_tokens' => $prompt->maxOutputTokens,
        ];

        if ($prompt->hasTools()) {
            $payload['tools'] = array_map(
                static fn (AiTool $tool): array => [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool->name,
                        'description' => $tool->description,
                        'parameters' => $tool->inputSchema,
                    ],
                ],
                $prompt->tools
            );
        }

        if ($stream) {
            $payload['stream'] = true;

            // Without this a streamed answer reports no usage at all.
            $payload['stream_options'] = ['include_usage' => true];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(array $payload, bool $streamed = false): Response
    {
        $request = Http::withToken((string) $this->apiKey())
            ->withHeaders($this->headers())
            ->timeout((float) config('ai.openai.timeout', 300))
            ->connectTimeout(15)
            ->retry((int) config('ai.openai.max_retries', 2), 250, throw: false)
            ->acceptJson();

        if ($streamed) {
            // Hand back the body as a stream rather than a buffered string, so
            // frames can be read as they arrive.
            $request = $request->withOptions(['stream' => true]);
        }

        try {
            $response = $request->post($this->endpoint(), $payload);
        } catch (ConnectionException $exception) {
            // Laravel raises this for a timeout as well as a refused
            // connection, and the two want different advice, so the message is
            // inspected rather than the class.
            throw str_contains(mb_strtolower($exception->getMessage()), 'timed out')
                ? AiProviderException::timedOut()
                : AiProviderException::transport($exception->getMessage());
        } catch (Throwable $exception) {
            throw AiProviderException::transport($exception->getMessage());
        }

        if ($response->failed()) {
            throw $this->translate($response);
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = [];

        if (filled(config('ai.openai.organization'))) {
            $headers['OpenAI-Organization'] = (string) config('ai.openai.organization');
        }

        if (filled(config('ai.openai.project'))) {
            $headers['OpenAI-Project'] = (string) config('ai.openai.project');
        }

        return $headers;
    }

    private function endpoint(): string
    {
        return rtrim($this->baseUrl(), '/').'/chat/completions';
    }

    // -----------------------------------------------------------------
    // Response interpretation
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw AiProviderException::emptyResponse();
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function toCompletion(array $body, AiPrompt $prompt): AiCompletion
    {
        $choice = $body['choices'][0] ?? null;
        $message = is_array($choice) && is_array($choice['message'] ?? null) ? $choice['message'] : [];

        $raw = [];

        foreach ((array) ($message['tool_calls'] ?? []) as $call) {
            if (! is_array($call)) {
                continue;
            }

            $raw[] = [
                'id' => (string) ($call['id'] ?? ''),
                'name' => (string) ($call['function']['name'] ?? ''),
                'arguments' => (string) ($call['function']['arguments'] ?? ''),
            ];
        }

        return $this->finish(
            text: is_string($message['content'] ?? null) ? $message['content'] : '',
            toolCalls: $this->decodeToolCalls($raw),
            model: is_string($body['model'] ?? null) ? $body['model'] : $prompt->model,
            responseId: is_string($body['id'] ?? null) ? $body['id'] : null,
            inputTokens: $this->intOrNull($body['usage']['prompt_tokens'] ?? null),
            outputTokens: $this->intOrNull($body['usage']['completion_tokens'] ?? null),
            finishReason: is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : null,
            streamed: false,
        );
    }

    /**
     * The one place a completion is built, whichever path produced it.
     *
     * Streaming and blocking share it so they cannot drift: a refusal, an empty
     * answer and a tool call are interpreted identically, exactly as
     * ClaudeService arranges for its two paths.
     *
     * @param  list<AiToolCall>  $toolCalls
     */
    private function finish(
        string $text,
        array $toolCalls,
        ?string $model,
        ?string $responseId,
        ?int $inputTokens,
        ?int $outputTokens,
        ?string $finishReason,
        bool $streamed,
    ): AiCompletion {
        $completion = new AiCompletion(
            text: trim($text),
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            model: $model,
            // OpenAI's vocabulary, mapped onto the one the application already
            // uses. `content_filter` is a refusal by another name, and the rest
            // of the codebase already knows what to do with a refusal.
            stopReason: $finishReason === 'content_filter' ? 'refusal' : $finishReason,
            toolCalls: $toolCalls,
            metadata: array_filter([
                'provider' => $this->name(),
                'response_id' => $responseId,
                'streamed' => $streamed ?: null,
            ], static fn ($value): bool => $value !== null),
        );

        if ($completion->wasRefused()) {
            throw AiProviderException::refused($finishReason);
        }

        if (! $completion->hasText() && $completion->toolCalls === []) {
            throw AiProviderException::emptyResponse();
        }

        return $completion;
    }

    /**
     * Turn accumulated argument strings into tool calls.
     *
     * Arguments arrive as a JSON *string*, in fragments when streamed, so they
     * are parsed rather than matched: a call whose arguments will not decode
     * becomes a call with empty input rather than a dropped call, so the
     * transcript still shows that the model tried to propose something.
     *
     * @param  array<int, array{id: string, name: string, arguments: string}>  $raw
     * @return list<AiToolCall>
     */
    private function decodeToolCalls(array $raw): array
    {
        $calls = [];

        foreach ($raw as $call) {
            if (trim($call['name']) === '') {
                continue;
            }

            $input = json_decode($call['arguments'] === '' ? '{}' : $call['arguments'], true);

            $calls[] = new AiToolCall(
                name: $call['name'],
                input: is_array($input) ? $input : [],
                id: $call['id'],
            );
        }

        return $calls;
    }

    /**
     * Fold one streamed frame's tool-call deltas into the accumulator.
     *
     * Frames identify a call by its index, and only the first frame for a call
     * carries its name and id, so both are only ever written when present —
     * overwriting a name with the empty string from a later fragment is the
     * obvious way to get this wrong.
     *
     * @param  array<string, mixed>  $delta
     * @param  array<int, array{id: string, name: string, arguments: string}>  $toolCalls
     */
    private function accumulateToolDeltas(array $delta, array &$toolCalls): void
    {
        foreach ((array) ($delta['tool_calls'] ?? []) as $call) {
            if (! is_array($call)) {
                continue;
            }

            $index = (int) ($call['index'] ?? 0);

            $toolCalls[$index] ??= ['id' => '', 'name' => '', 'arguments' => ''];

            if (filled($call['id'] ?? null)) {
                $toolCalls[$index]['id'] = (string) $call['id'];
            }

            if (filled($call['function']['name'] ?? null)) {
                $toolCalls[$index]['name'] = (string) $call['function']['name'];
            }

            $toolCalls[$index]['arguments'] .= (string) ($call['function']['arguments'] ?? '');
        }
    }

    /**
     * Read the server-sent event stream, frame by decoded frame.
     *
     * Buffered by line rather than by chunk, because a chunk boundary can fall
     * anywhere — including the middle of a JSON object — and a parser that
     * assumed otherwise would work in tests and lose text in production.
     *
     * @return iterable<array<string, mixed>>
     */
    private function frames(Response $response): iterable
    {
        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(8192);

            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newline));
                $buffer = substr($buffer, $newline + 1);

                $frame = $this->frame($line);

                if ($frame !== null) {
                    yield $frame;
                }
            }
        }

        $frame = $this->frame(trim($buffer));

        if ($frame !== null) {
            yield $frame;
        }
    }

    /**
     * One SSE line, decoded, or null for anything that is not a data frame.
     *
     * @return array<string, mixed>|null
     */
    private function frame(string $line): ?array
    {
        if (! str_starts_with($line, 'data:')) {
            return null;
        }

        $payload = trim(substr($line, 5));

        if ($payload === '' || $payload === '[DONE]') {
            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Turn an HTTP failure into advice a member of staff can act on.
     *
     * The prose is rebuilt from the status rather than forwarded from the
     * response, for the same reason ClaudeService rebuilds its own: a vendor
     * error body is verbose, and the one part of it worth quoting is
     * `error.message`, which is vendor prose and not derived from a secret.
     */
    private function translate(Response $response): AiProviderException
    {
        $status = $response->status();

        return match (true) {
            $status === 401, $status === 403 => AiProviderException::unauthorized(),
            $status === 404 => AiProviderException::status(
                404,
                'the configured model is not available to this account — check AI_OPENAI_MODELS '
                .'and the key stored in the global AI settings'
            ),
            $status === 429 => AiProviderException::rateLimited(),
            $status >= 500 => AiProviderException::status($status, 'the provider reported a server error'),
            default => AiProviderException::status($status, $this->providerMessage($response)),
        };
    }

    private function providerMessage(Response $response): string
    {
        $message = data_get($response->json(), 'error.message');

        return is_string($message) && trim($message) !== ''
            ? mb_substr(trim($message), 0, 500)
            : 'no details supplied';
    }

    // -----------------------------------------------------------------

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * The credential handed to the HTTP client, and nowhere else.
     */
    private function apiKey(): string
    {
        return (string) ($this->apiKey ?? config('ai.openai.api_key'));
    }

    private function baseUrl(): string
    {
        $baseUrl = trim((string) ($this->baseUrl ?? config('ai.openai.base_url')));

        return $baseUrl === '' ? 'https://api.openai.com/v1' : $baseUrl;
    }
}
