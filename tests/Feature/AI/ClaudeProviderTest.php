<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Services\AI\ClaudeService;
use App\Services\AI\Data\AiPrompt;
use App\Services\AI\Exceptions\AiProviderException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use ArrayObject;
use Tests\TestCase;

/**
 * How the Anthropic adapter reports a failed request, through the real SDK.
 *
 * A Guzzle client with a mock handler stands in for the network, so the SDK
 * builds its real request and raises its real exception, and nothing is sent
 * anywhere. A streamed response is given a body that cannot be rewound,
 * because that is what the SDK gets from Guzzle when it streams — and reading
 * that body a second time is what made every streamed failure, which is every
 * failure in the assistant panel, read "no details supplied".
 */
class ClaudeProviderTest extends TestCase
{
    private const KEY = 'sk-ant-test-key-not-real-000000000000';

    private const OUT_OF_CREDIT = 'Your credit balance is too low to access the Anthropic API. '
        .'Please go to Plans & Billing to upgrade or purchase credits.';

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.enabled' => true, 'ai.anthropic.max_retries' => 0]);
    }

    public function test_a_streamed_failure_still_quotes_the_providers_reason(): void
    {
        $message = $this->failure(400, 'invalid_request_error', 'max_tokens: 64000 > 32000, which is the maximum', streamed: true);

        $this->assertSame('The AI provider returned HTTP 400: max_tokens: 64000 > 32000, which is the maximum', $message);
    }

    public function test_a_blocking_failure_quotes_the_providers_reason(): void
    {
        $message = $this->failure(400, 'invalid_request_error', 'max_tokens: 64000 > 32000, which is the maximum', streamed: false);

        $this->assertSame('The AI provider returned HTTP 400: max_tokens: 64000 > 32000, which is the maximum', $message);
    }

    /**
     * What production hit: the account behind the key ran out of credit, and
     * the panel said "HTTP 400: no details supplied" — a malformed request, as
     * far as anybody reading it could tell. It is neither malformed nor
     * fixable from here, so the message says who has to act, and where.
     */
    public function test_an_account_out_of_credit_says_who_has_to_act(): void
    {
        foreach ([true, false] as $streamed) {
            $message = $this->failure(400, 'invalid_request_error', self::OUT_OF_CREDIT, $streamed);

            $this->assertStringContainsString('run out of credit', $message);
            $this->assertStringContainsString('console.anthropic.com', $message);
            $this->assertStringContainsString('Plans & Billing', $message);
            $this->assertStringNotContainsString('HTTP 400', $message);
        }
    }

    public function test_a_billing_error_is_treated_as_out_of_credit_too(): void
    {
        $message = $this->failure(402, 'billing_error', 'This organization has a billing problem.', streamed: true);

        $this->assertStringContainsString('run out of credit', $message);
    }

    public function test_a_rejected_key_is_still_reported_as_the_credential(): void
    {
        $message = $this->failure(401, 'authentication_error', 'invalid x-api-key', streamed: true);

        $this->assertMatchesRegularExpression('/credential/i', $message);
    }

    public function test_the_credential_never_reaches_the_message(): void
    {
        foreach ([
            [400, 'invalid_request_error', 'messages: at least one message is required'],
            [400, 'invalid_request_error', self::OUT_OF_CREDIT],
            [401, 'authentication_error', 'invalid x-api-key'],
            [404, 'not_found_error', 'model: claude-nonexistent'],
        ] as [$status, $type, $text]) {
            foreach ([true, false] as $streamed) {
                $this->assertStringNotContainsString(self::KEY, $this->failure($status, $type, $text, $streamed));
            }
        }
    }

    /**
     * Prompt caching, as the request actually goes out.
     *
     * A cached prompt carries exactly the breakpoints it asked for: the system
     * prompt (which covers the tools before it) and each message flagged
     * `cache`. The flag itself is this application's and must never reach the
     * API — an unknown field is a 400.
     */
    public function test_a_cached_prompt_marks_the_system_prompt_and_the_flagged_messages(): void
    {
        [$provider, $sent] = $this->answering($this->message());

        $provider->complete(new AiPrompt(
            model: 'claude-sonnet-5',
            system: 'Be brief.',
            messages: [
                ['role' => 'user', 'content' => 'First question'],
                ['role' => 'assistant', 'content' => 'First answer', 'cache' => true],
                ['role' => 'user', 'content' => 'Context and the next question', 'cache' => true],
            ],
            maxOutputTokens: 64,
            cache: true,
        ));

        $body = $this->body($sent);

        $this->assertSame([['type' => 'text', 'text' => 'Be brief.', 'cache_control' => ['type' => 'ephemeral']]], $body['system']);
        $this->assertSame('First question', $body['messages'][0]['content']);
        $this->assertSame(['type' => 'ephemeral'], $body['messages'][1]['content'][0]['cache_control']);
        $this->assertSame(['type' => 'ephemeral'], $body['messages'][2]['content'][0]['cache_control']);
        $this->assertArrayNotHasKey('cache_control', $body);
        $this->assertStringNotContainsString('"cache":', (string) json_encode($body));
    }

    public function test_an_hour_long_cache_is_asked_for_by_name(): void
    {
        [$provider, $sent] = $this->answering($this->message());

        $provider->complete(new AiPrompt(
            model: 'claude-sonnet-5',
            system: 'Be brief.',
            messages: [['role' => 'user', 'content' => 'Hello', 'cache' => true]],
            maxOutputTokens: 64,
            cache: true,
            cacheTtl: '1h',
        ));

        $body = $this->body($sent);

        $this->assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $body['system'][0]['cache_control']);
    }

    /**
     * A one-shot request — a ticket run — is never cached: nothing would read
     * the entry again, and writing it costs a quarter more than not.
     */
    public function test_a_prompt_that_does_not_ask_for_caching_sends_no_breakpoints(): void
    {
        [$provider, $sent] = $this->answering($this->message());

        $provider->complete(new AiPrompt(
            model: 'claude-sonnet-5',
            system: 'Be brief.',
            messages: [['role' => 'user', 'content' => 'Hello', 'cache' => true]],
            maxOutputTokens: 64,
        ));

        $body = $this->body($sent);

        $this->assertSame('Be brief.', $body['system']);
        $this->assertSame([['role' => 'user', 'content' => 'Hello']], $body['messages']);
    }

    /**
     * The input count stays every token the model read. Anthropic leaves the
     * cached parts out of its own `input_tokens`; they are added back, and
     * kept separately as well, so cost and limits can treat them as cheap.
     */
    public function test_cached_tokens_are_counted_in_the_input_and_reported_separately(): void
    {
        [$provider] = $this->answering($this->message());

        $completion = $provider->complete(AiPrompt::single('claude-sonnet-5', 'Be brief.', 'Hello', 64));

        $this->assertSame(1050, $completion->inputTokens);
        $this->assertSame(900, $completion->cacheReadTokens);
        $this->assertSame(50, $completion->cacheWriteTokens);
        $this->assertSame(20, $completion->outputTokens);
        $this->assertSame(1070, $completion->contextSize());
    }

    public function test_a_streamed_answer_reports_the_cache_the_same_way(): void
    {
        $events = [
            ['message_start', ['type' => 'message_start', 'message' => [
                'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5',
                'content' => [], 'stop_reason' => null, 'stop_sequence' => null,
                'usage' => ['input_tokens' => 100, 'output_tokens' => 1, 'cache_read_input_tokens' => 900, 'cache_creation_input_tokens' => 50],
            ]]],
            ['content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
            ['content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hello']]],
            ['content_block_stop', ['type' => 'content_block_stop', 'index' => 0]],
            ['message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null], 'usage' => ['output_tokens' => 20]]],
            ['message_stop', ['type' => 'message_stop']],
        ];

        $stream = implode('', array_map(
            static fn (array $event): string => 'event: '.$event[0]."\ndata: ".json_encode($event[1])."\n\n",
            $events,
        ));

        [$provider] = $this->answering(new Response(200, ['Content-Type' => 'text/event-stream'], new NoSeekStream(Utils::streamFor($stream))));

        $completion = $provider->completeStreamed(
            AiPrompt::single('claude-sonnet-5', 'Be brief.', 'Hello', 64),
            static fn (string $text): null => null,
        );

        $this->assertSame('Hello', $completion->text);
        $this->assertSame(1050, $completion->inputTokens);
        $this->assertSame(900, $completion->cacheReadTokens);
        $this->assertSame(50, $completion->cacheWriteTokens);
        $this->assertSame(20, $completion->outputTokens);
    }

    /**
     * A request longer than the model's window is recognised as exactly that,
     * so the chat can retry it with less of the conversation.
     */
    public function test_a_prompt_that_is_too_long_is_recognised(): void
    {
        foreach ([true, false] as $streamed) {
            $json = (string) json_encode(['type' => 'error', 'error' => [
                'type' => 'invalid_request_error',
                'message' => 'prompt is too long: 1040000 tokens > 1000000 maximum',
            ]]);

            $body = Utils::streamFor($json);

            [$provider] = $this->answering(new Response(400, ['Content-Type' => 'application/json'], $streamed ? new NoSeekStream($body) : $body));

            $prompt = AiPrompt::single('claude-sonnet-5', 'Be brief.', 'Hello', 64);

            try {
                $streamed
                    ? $provider->completeStreamed($prompt, static fn (string $text): null => null)
                    : $provider->complete($prompt);

                $this->fail('A prompt that was too long did not raise.');
            } catch (AiProviderException $exception) {
                $this->assertTrue($exception->isContextOverflow());
                $this->assertStringContainsString('Start a new conversation', $exception->getMessage());
            }
        }
    }

    /**
     * A provider answering with one response, and the requests it was sent.
     *
     * @return array{0: ClaudeService, 1: ArrayObject<int, array<string, mixed>>}
     */
    private function answering(Response $response): array
    {
        $sent = new ArrayObject;
        $stack = HandlerStack::create(new MockHandler([$response]));
        $stack->push(Middleware::history($sent));

        return [new ClaudeService(self::KEY, null, new GuzzleClient(['handler' => $stack])), $sent];
    }

    /**
     * A successful blocking answer with cache usage.
     */
    private function message(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5',
            'content' => [['type' => 'text', 'text' => 'Hello']],
            'stop_reason' => 'end_turn',
            'stop_sequence' => null,
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 20,
                'cache_read_input_tokens' => 900,
                'cache_creation_input_tokens' => 50,
            ],
        ]));
    }

    /**
     * The JSON body of the one request that was sent.
     *
     * @param  ArrayObject<int, array<string, mixed>>  $sent
     * @return array<string, mixed>
     */
    private function body(ArrayObject $sent): array
    {
        $this->assertCount(1, $sent);

        return json_decode((string) $sent[0]['request']->getBody(), true);
    }

    /**
     * The message the adapter raises for one error response.
     */
    private function failure(int $status, string $type, string $text, bool $streamed): string
    {
        $json = (string) json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => $text]]);
        $body = Utils::streamFor($json);

        $response = new Response(
            $status,
            ['Content-Type' => 'application/json'],
            $streamed ? new NoSeekStream($body) : $body,
        );

        $provider = new ClaudeService(
            self::KEY,
            null,
            new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([$response]))]),
        );

        $prompt = AiPrompt::single('claude-sonnet-5', 'Be brief.', 'What changed this week?', 64);

        try {
            $streamed
                ? $provider->completeStreamed($prompt, static fn (string $text): null => null)
                : $provider->complete($prompt);
        } catch (AiProviderException $exception) {
            return $exception->getMessage();
        }

        $this->fail('A '.$status.' response did not raise.');
    }
}
