<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Passport;
use RuntimeException;

/**
 * Speak to the remote MCP server the way Claude or ChatGPT does.
 *
 * Over HTTP, through the real route and middleware, rather than through the
 * package's in-process test helper: the authentication, the scope check, the
 * active-user check and the rate limit are all middleware, and they are most
 * of what there is to test.
 *
 * Passport needs an RSA key pair to build its token guard at all, even when a
 * test only uses Passport::actingAs(). One pair is generated per test process
 * and handed to Passport through config, so no key file is ever written and a
 * machine's own PASSPORT_* settings cannot leak in.
 */
trait InteractsWithMcp
{
    /** @var array{private: string, public: string}|null */
    private static ?array $passportKeys = null;

    protected function setUpPassportKeys(): void
    {
        $keys = self::$passportKeys ??= self::generatePassportKeys();

        config([
            'passport.private_key' => $keys['private'],
            'passport.public_key' => $keys['public'],
        ]);
    }

    /**
     * Act as somebody holding a token with the given scopes.
     *
     * @param  list<string>  $scopes
     */
    protected function mcpAs(User $user, array $scopes = [Registrar::OAUTH_SCOPE]): static
    {
        $this->setUpPassportKeys();

        Passport::actingAs($user, $scopes, 'api');

        return $this;
    }

    /**
     * One JSON-RPC request to POST /mcp.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $headers
     */
    protected function mcp(string $method, array $params = [], array $headers = []): TestResponse
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params === [] ? (object) [] : $params,
        ], array_merge(['Accept' => 'application/json, text/event-stream'], $headers));
    }

    /**
     * The tool names the server offers to whoever is acting.
     *
     * @return list<string>
     */
    protected function mcpToolNames(): array
    {
        $tools = $this->mcp('tools/list')->assertOk()->json('result.tools') ?? [];

        return array_values(array_map(static fn (array $tool): string => $tool['name'], $tools));
    }

    /**
     * Call one tool and return the result block: content and isError.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{content: list<array<string, mixed>>, isError?: bool}
     */
    protected function mcpTool(string $name, array $arguments = []): array
    {
        $response = $this->mcp('tools/call', [
            'name' => $name,
            'arguments' => $arguments === [] ? (object) [] : $arguments,
        ])->assertOk();

        $result = $response->json('result');

        if (! is_array($result)) {
            throw new RuntimeException('tools/call returned no result: '.$response->getContent());
        }

        return $result;
    }

    /**
     * All the text a tool call returned, joined.
     *
     * @param  array{content: list<array<string, mixed>>}  $result
     */
    protected function mcpText(array $result): string
    {
        return implode("\n", array_map(
            static fn (array $block): string => (string) ($block['text'] ?? ''),
            $result['content'] ?? [],
        ));
    }

    /**
     * @return array{private: string, public: string}
     */
    private static function generatePassportKeys(): array
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

        // PHP on Windows ships its openssl.cnf beside the binary and does not
        // find it on its own; elsewhere the system configuration is used.
        $bundled = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';

        if (PHP_OS_FAMILY === 'Windows' && getenv('OPENSSL_CONF') === false && is_file($bundled)) {
            $options['config'] = $bundled;
        }

        $key = openssl_pkey_new($options);

        if ($key === false || ! openssl_pkey_export($key, $private, null, $options)) {
            throw new RuntimeException('Could not generate a test key pair: '.(openssl_error_string() ?: 'unknown OpenSSL error'));
        }

        $details = openssl_pkey_get_details($key);

        return ['private' => $private, 'public' => (string) ($details['key'] ?? '')];
    }
}
