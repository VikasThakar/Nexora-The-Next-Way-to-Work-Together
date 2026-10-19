<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Services\AI\ClaudeService;
use App\Services\AI\Data\AiPrompt;
use App\Services\AI\Exceptions\AiProviderException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
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
