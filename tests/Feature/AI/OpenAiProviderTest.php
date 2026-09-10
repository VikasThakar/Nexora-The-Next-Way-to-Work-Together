<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Enums\AiProvider;
use App\Services\AI\AiProviderInterface;
use App\Services\AI\AiProviderRegistry;
use App\Services\AI\ClaudeService;
use App\Services\AI\Data\AiPrompt;
use App\Services\AI\Data\AiTool;
use App\Services\AI\Exceptions\AiProviderException;
use App\Services\AI\OpenAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The second provider.
 *
 * Written against the HTTP API rather than an SDK, which is what makes it
 * testable here: Http::fake() means the whole adapter is exercised — the request
 * shape, the usage block, the tool-call structure, the error envelope, the
 * server-sent event stream — with no network and no credential.
 *
 * The unusual test in this file is the last one. It asserts that the registry
 * still honours a provider substituted into the container, because that is how
 * every other test in this suite stays off the network, and an adapter that
 * routed around the binding would quietly re-enable real API calls everywhere.
 */
class OpenAiProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config(['ai.enabled' => true]);
    }

    private function provider(): OpenAiService
    {
        return new OpenAiService('sk-test-key-not-real-000000000000');
    }

    private function prompt(array $tools = []): AiPrompt
    {
        return new AiPrompt(
            model: 'gpt-5.1',
            system: 'You are an engineering assistant.',
            messages: [['role' => 'user', 'content' => 'What changed this week?']],
            maxOutputTokens: 1000,
            tools: $tools,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function answer(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'chatcmpl-abc123',
            'model' => 'gpt-5.1',
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => 'Three tickets moved to Done.'],
            ]],
            'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 350],
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // The happy path
    // -----------------------------------------------------------------

    public function test_it_sends_the_prompt_and_returns_the_answer(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->answer())]);

        $completion = $this->provider()->complete($this->prompt());

        $this->assertSame('Three tickets moved to Done.', $completion->text);
        $this->assertSame(1200, $completion->inputTokens);
        $this->assertSame(350, $completion->outputTokens);
        $this->assertSame('gpt-5.1', $completion->model);
        $this->assertSame('openai', $completion->metadata['provider']);
        $this->assertSame('chatcmpl-abc123', $completion->metadata['response_id']);
    }

    public function test_the_system_prompt_becomes_the_first_message(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->answer())]);

        $this->provider()->complete($this->prompt());

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            $this->assertSame('gpt-5.1', $body['model']);
            $this->assertSame('system', $body['messages'][0]['role']);
            $this->assertSame('You are an engineering assistant.', $body['messages'][0]['content']);
            $this->assertSame('user', $body['messages'][1]['role']);
            $this->assertSame(1000, $body['max_completion_tokens']);

            // The credential travels as a bearer token and nowhere else.
            $this->assertSame(
                'Bearer sk-test-key-not-real-000000000000',
                $request->header('Authorization')[0],
            );

            return true;
        });
    }

    public function test_a_prompt_with_no_user_turn_is_refused_before_it_is_sent(): void
    {
        $this->expectException(AiProviderException::class);

        // No HTTP fake registered: reaching the network would fail the test
        // through preventStrayRequests, which is the assertion.
        $this->provider()->complete(new AiPrompt(
            model: 'gpt-5.1',
            system: 'System.',
            messages: [],
            maxOutputTokens: 100,
        ));
    }

    public function test_tools_are_sent_in_the_vendors_own_shape(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->answer())]);

        $this->provider()->complete($this->prompt([
            new AiTool(
                name: 'propose_create_ticket',
                description: 'Propose a ticket.',
                inputSchema: ['type' => 'object', 'properties' => ['title' => ['type' => 'string']], 'required' => ['title']],
            ),
        ]));

        Http::assertSent(function ($request): bool {
            $tool = $request->data()['tools'][0];

            $this->assertSame('function', $tool['type']);
            $this->assertSame('propose_create_ticket', $tool['function']['name']);
            $this->assertSame('object', $tool['function']['parameters']['type']);

            return true;
        });
    }

    public function test_a_tool_call_is_read_back_as_a_proposal(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->answer([
            'choices' => [[
                'finish_reason' => 'tool_calls',
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => [
                            'name' => 'propose_create_ticket',
                            'arguments' => '{"title":"Fix the login bug"}',
                        ],
                    ]],
                ],
            ]],
        ]))]);

        $completion = $this->provider()->complete($this->prompt());

        $call = $completion->firstToolCall();

        $this->assertNotNull($call);
        $this->assertSame('propose_create_ticket', $call->name);
        $this->assertSame(['title' => 'Fix the login bug'], $call->input);
    }

    /**
     * Arguments arrive as a JSON string, so they are parsed rather than
     * matched. One that will not decode becomes an empty input instead of a
     * dropped call, so the transcript still shows that something was proposed.
     */
    public function test_undecodable_tool_arguments_do_not_lose_the_call(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->answer([
            'choices' => [[
                'finish_reason' => 'tool_calls',
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'function' => ['name' => 'propose_create_ticket', 'arguments' => '{"title":'],
                    ]],
                ],
            ]],
        ]))]);

        $completion = $this->provider()->complete($this->prompt());

        $this->assertSame('propose_create_ticket', $completion->firstToolCall()->name);
        $this->assertSame([], $completion->firstToolCall()->input);
    }

    // -----------------------------------------------------------------
    // Usage, and the honest blank
    // -----------------------------------------------------------------

    public function test_a_response_with_no_usage_block_reports_null(): void
    {
        $answer = $this->answer();
        unset($answer['usage']);

        Http::fake(['api.openai.com/*' => Http::response($answer)]);

        $completion = $this->provider()->complete($this->prompt());

        $this->assertNull($completion->inputTokens);
        $this->assertNull($completion->outputTokens);
    }

    // -----------------------------------------------------------------
    // Failures
    // -----------------------------------------------------------------

    public function test_an_unauthorized_response_names_the_credential(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key']], 401)]);

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessageMatches('/credential/i');

        $this->provider()->complete($this->prompt());
    }

    /**
     * A model the account cannot reach is the most likely misconfiguration of
     * this provider, so the message names the setting to change rather than
     * forwarding a bare 404.
     */
    public function test_a_missing_model_names_the_setting_to_change(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'The model does not exist']], 404)]);

        try {
            $this->provider()->complete($this->prompt());
            $this->fail('A 404 did not raise.');
        } catch (AiProviderException $exception) {
            $this->assertStringContainsString('AI_OPENAI_MODELS', $exception->getMessage());
        }
    }

    public function test_a_rate_limit_is_reported_as_retryable(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Slow down']], 429)]);

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessageMatches('/rate limiting/i');

        $this->provider()->complete($this->prompt());
    }

    /**
     * A vendor error body is verbose and the one useful part of it is
     * `error.message`, which is vendor prose rather than anything derived from
     * a secret. So that part is quoted, and nothing else is.
     */
    public function test_a_client_error_quotes_only_the_providers_message(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'error' => ['message' => 'max_completion_tokens is too large', 'type' => 'invalid_request_error'],
        ], 400)]);

        try {
            $this->provider()->complete($this->prompt());
            $this->fail('A 400 did not raise.');
        } catch (AiProviderException $exception) {
            $this->assertStringContainsString('max_completion_tokens is too large', $exception->getMessage());
            $this->assertStringNotContainsString('sk-test-key', $exception->getMessage());
        }
    }

    public function test_a_content_filter_is_treated_as_a_refusal(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->answer([
            'choices' => [[
                'finish_reason' => 'content_filter',
                'message' => ['role' => 'assistant', 'content' => ''],
            ]],
        ]))]);

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessageMatches('/declined/i');

        $this->provider()->complete($this->prompt());
    }

    public function test_an_empty_answer_is_a_fault_rather_than_a_result(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->answer([
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => ''],
            ]],
        ]))]);

        $this->expectException(AiProviderException::class);

        $this->provider()->complete($this->prompt());
    }

    public function test_it_refuses_to_send_without_a_credential(): void
    {
        $provider = new OpenAiService(null);
        config(['ai.openai.api_key' => null]);

        $this->assertFalse($provider->isConfigured());

        $this->expectException(AiProviderException::class);

        $provider->complete($this->prompt());
    }

    // -----------------------------------------------------------------
    // Streaming
    // -----------------------------------------------------------------

    public function test_it_streams_prose_as_it_arrives(): void
    {
        $stream = implode("\n", [
            'data: {"id":"chatcmpl-1","model":"gpt-5.1","choices":[{"delta":{"role":"assistant"}}]}',
            'data: {"choices":[{"delta":{"content":"Three "}}]}',
            'data: {"choices":[{"delta":{"content":"tickets "}}]}',
            'data: {"choices":[{"delta":{"content":"moved."},"finish_reason":null}]}',
            'data: {"choices":[{"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":90,"completion_tokens":12}}',
            'data: [DONE]',
            '',
        ]);

        Http::fake(['api.openai.com/*' => Http::response($stream, 200)]);

        $chunks = [];

        $completion = $this->provider()->completeStreamed(
            $this->prompt(),
            function (string $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            },
        );

        $this->assertSame('Three tickets moved.', $completion->text);
        $this->assertSame(90, $completion->inputTokens);
        $this->assertSame(12, $completion->outputTokens);
        $this->assertTrue($completion->metadata['streamed']);

        // Progressive rather than one write at the end, which is the whole
        // point of the streaming path.
        $this->assertContains('Three ', $chunks);
        $this->assertContains('tickets ', $chunks);
        $this->assertSame('Three tickets moved.', implode('', $chunks));
    }

    public function test_streaming_asks_for_the_usage_block(): void
    {
        Http::fake(['api.openai.com/*' => Http::response("data: [DONE]\n")]);

        try {
            $this->provider()->completeStreamed($this->prompt(), fn () => null);
        } catch (AiProviderException) {
            // An empty stream is a fault; the assertion is about the request.
        }

        Http::assertSent(function ($request): bool {
            $this->assertTrue($request->data()['stream']);
            $this->assertTrue($request->data()['stream_options']['include_usage']);

            return true;
        });
    }

    public function test_a_streamed_tool_call_is_reassembled_from_its_fragments(): void
    {
        $stream = implode("\n", [
            'data: {"id":"chatcmpl-2","model":"gpt-5.1","choices":[{"delta":{"tool_calls":[{"index":0,"id":"call_9","function":{"name":"propose_create_ticket","arguments":""}}]}}]}',
            'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"arguments":"{\"title\":"}}]}}]}',
            'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"arguments":"\"Fix login\"}"}}]}}]}',
            'data: {"choices":[{"delta":{},"finish_reason":"tool_calls"}]}',
            'data: [DONE]',
            '',
        ]);

        Http::fake(['api.openai.com/*' => Http::response($stream)]);

        $completion = $this->provider()->completeStreamed($this->prompt(), fn () => null);

        $call = $completion->firstToolCall();

        $this->assertNotNull($call);
        // The name arrives only in the first fragment; a later empty one must
        // not overwrite it.
        $this->assertSame('propose_create_ticket', $call->name);
        $this->assertSame('call_9', $call->id);
        $this->assertSame(['title' => 'Fix login'], $call->input);
    }

    // -----------------------------------------------------------------
    // The registry
    // -----------------------------------------------------------------

    public function test_the_registry_builds_the_adapter_for_the_configured_provider(): void
    {
        // The container binding is the real one here, so the registry builds
        // adapters rather than returning a substitute.
        $this->app->forgetInstance(AiProviderInterface::class);
        $this->app->singleton(AiProviderInterface::class, ClaudeService::class);

        $registry = app(AiProviderRegistry::class);

        $this->assertInstanceOf(ClaudeService::class, $registry->make(AiProvider::Anthropic, 'sk-ant-x'));
        $this->assertInstanceOf(OpenAiService::class, $registry->make(AiProvider::OpenAi, 'sk-x'));
    }

    /**
     * A substituted provider always wins.
     *
     * This is how the whole suite stays off the network — Tests\Support's fake
     * and unreachable providers are bound into the container — so an adapter
     * or a registry that routed around the binding would silently re-enable
     * real API calls in every other test.
     */
    public function test_the_registry_honours_a_provider_substituted_into_the_container(): void
    {
        $fake = $this->fakeAiProvider();

        $resolved = app(AiProviderRegistry::class)->make(AiProvider::OpenAi, 'sk-x');

        $this->assertSame($fake, $resolved);
    }
}
