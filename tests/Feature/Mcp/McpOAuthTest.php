<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithMcp;
use Tests\TestCase;

/**
 * Connecting an AI client: the OAuth flow Claude and ChatGPT run against
 * Nexora the first time someone adds it as a connector.
 *
 *   discovery     the two .well-known documents that say where to go
 *   registration  the client registers itself, but only with a redirect on a
 *                 domain we support — anyone may call the endpoint
 *   the flow      login → consent → code → token (with PKCE) → /mcp, and the
 *                 refresh-token rotation clients rely on to stay connected
 */
class McpOAuthTest extends TestCase
{
    use InteractsWithMcp;
    use RefreshDatabase;

    private const CLAUDE_CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPassportKeys();
    }

    // -----------------------------------------------------------------
    // Discovery
    // -----------------------------------------------------------------

    public function test_the_protected_resource_metadata_names_the_mcp_url_exactly(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource/mcp')
            ->assertOk()
            ->assertJsonPath('resource', url('/mcp'))
            ->assertJsonPath('authorization_servers.0', url('/'))
            ->assertJsonPath('scopes_supported', ['mcp:use']);
    }

    public function test_the_authorization_server_metadata_points_at_passport(): void
    {
        $metadata = $this->getJson('/.well-known/oauth-authorization-server')->assertOk()->json();

        $this->assertSame(url('/'), $metadata['issuer']);
        $this->assertSame(url('/oauth/authorize'), $metadata['authorization_endpoint']);
        $this->assertSame(url('/oauth/token'), $metadata['token_endpoint']);
        $this->assertSame(url('/oauth/register'), $metadata['registration_endpoint']);
        $this->assertSame(['S256'], $metadata['code_challenge_methods_supported']);
        $this->assertContains('none', $metadata['token_endpoint_auth_methods_supported']);
        $this->assertSame(['authorization_code', 'refresh_token'], $metadata['grant_types_supported']);
    }

    public function test_no_device_code_routes_exist(): void
    {
        $this->assertFalse(app('router')->has('passport.device'));
        $this->assertFalse(app('router')->has('passport.device.code'));
    }

    // -----------------------------------------------------------------
    // Registration
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function supportedCallbacks(): array
    {
        return [
            'Claude (web, desktop, phone)' => [self::CLAUDE_CALLBACK],
            'Claude, second domain' => ['https://claude.com/api/mcp/auth_callback'],
            'ChatGPT' => ['https://chatgpt.com/connector/oauth/abc123'],
            'ChatGPT, stable callback' => ['https://chatgpt.com/connector_platform_oauth_redirect'],
            'Claude Code loopback' => ['http://localhost:33418/callback'],
            'loopback by address' => ['http://127.0.0.1:33418/callback'],
        ];
    }

    #[DataProvider('supportedCallbacks')]
    public function test_a_supported_ai_client_can_register(string $callback): void
    {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Some AI client',
            'redirect_uris' => [$callback],
        ]);

        $response->assertCreated()
            ->assertJsonPath('token_endpoint_auth_method', 'none')
            ->assertJsonPath('redirect_uris', [$callback]);

        $client = Passport::client()->newQuery()->findOrFail($response->json('client_id'));

        $this->assertFalse($client->confidential());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedCallbacks(): array
    {
        return [
            'an arbitrary site' => ['https://evil.example/callback'],
            'a look-alike host' => ['https://claude.ai.evil.example/api/mcp/auth_callback'],
            'a supported host over plain http' => ['http://claude.ai/api/mcp/auth_callback'],
            'a script' => ['javascript:alert(1)'],
            'a custom scheme' => ['claude://callback'],
        ];
    }

    #[DataProvider('refusedCallbacks')]
    public function test_any_other_redirect_is_refused_and_registers_nothing(string $callback): void
    {
        $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => [$callback],
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');

        $this->assertSame(0, Passport::client()->newQuery()->count());
    }

    // -----------------------------------------------------------------
    // The flow
    // -----------------------------------------------------------------

    public function test_a_person_connects_claude_end_to_end(): void
    {
        $user = $this->teamMember(['name' => 'Ada Lovelace']);
        $this->boardWithColumns([$user]);

        $clientId = $this->register('Claude');
        [$verifier, $query] = $this->authorizationQuery($clientId);
        $authorizeUrl = '/oauth/authorize?'.http_build_query($query);

        // Not signed in: to Nexora's login, which brings them back afterwards.
        $this->get($authorizeUrl)->assertRedirect(route('login'));
        $this->assertStringContainsString('/oauth/authorize?', (string) session('url.intended'));

        // Signed in: the consent screen, naming the client and where it goes.
        $consent = $this->actingAs($user)->get($authorizeUrl);

        $consent->assertOk()
            ->assertSee('Allow Claude to use')
            ->assertSee('Reads and changes.')
            ->assertSee('claude.ai')
            ->assertSee('Ada Lovelace')
            ->assertDontSee('window.close', false);

        // Approve: back to Claude with a code and the state it sent.
        $location = $this->post(route('passport.authorizations.approve'), [
            'state' => '',
            'client_id' => $clientId,
            'auth_token' => $this->authToken($consent->getContent()),
        ])->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith(self::CLAUDE_CALLBACK.'?', (string) $location);
        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $returned);
        $this->assertSame('state-from-claude', $returned['state'] ?? null);

        // Exchange the code, as Claude does: form-encoded, with the verifier
        // and the RFC 8707 resource it wants the token for.
        $tokens = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => self::CLAUDE_CALLBACK,
            'code_verifier' => $verifier,
            'code' => $returned['code'],
            'resource' => url('/mcp'),
        ])->assertOk()->json();

        $this->assertSame('Bearer', $tokens['token_type']);
        $this->assertEqualsWithDelta(3600, $tokens['expires_in'], 5);
        $this->assertNotEmpty($tokens['refresh_token']);

        // The token works on /mcp, as this person.
        $this->app['auth']->forgetGuards();

        $this->mcp('tools/list', [], ['Authorization' => 'Bearer '.$tokens['access_token']])
            ->assertOk()
            ->assertJsonPath('result.tools.0.annotations.readOnlyHint', true);

        // Refreshing rotates the refresh token; the old one is spent.
        $refreshed = $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
            'client_id' => $clientId,
            'resource' => url('/mcp'),
        ])->assertOk()->json();

        $this->assertNotSame($tokens['refresh_token'], $refreshed['refresh_token']);

        $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
            'client_id' => $clientId,
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    /**
     * ChatGPT may leave the scope out and relies on the server's default; it
     * sends the resource on both the authorization and the token request.
     */
    public function test_a_request_without_a_scope_gets_a_working_mcp_token(): void
    {
        $user = $this->teamMember();
        $this->boardWithColumns([$user]);

        $callback = 'https://chatgpt.com/connector/oauth/abc123';
        $clientId = $this->register('ChatGPT', $callback);
        [$verifier, $query] = $this->authorizationQuery($clientId, $callback);
        unset($query['scope']);

        $code = $this->approve($user, $clientId, $query);

        $tokens = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => $callback,
            'code_verifier' => $verifier,
            'code' => $code,
            'resource' => url('/mcp'),
        ])->assertOk()->json();

        $this->app['auth']->forgetGuards();

        $this->mcp('tools/list', [], ['Authorization' => 'Bearer '.$tokens['access_token']])->assertOk();
    }

    public function test_a_wrong_pkce_verifier_gets_no_token(): void
    {
        $user = $this->teamMember();
        $clientId = $this->register('Claude');
        [, $query] = $this->authorizationQuery($clientId);

        $code = $this->approve($user, $clientId, $query);

        $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => self::CLAUDE_CALLBACK,
            'code_verifier' => Str::random(64),
            'code' => $code,
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    public function test_cancelling_sends_the_client_back_with_access_denied(): void
    {
        $user = $this->teamMember();
        $clientId = $this->register('Claude');
        [, $query] = $this->authorizationQuery($clientId);

        $consent = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($query))->assertOk();

        $location = (string) $this->delete(route('passport.authorizations.deny'), [
            'state' => '',
            'client_id' => $clientId,
            'auth_token' => $this->authToken($consent->getContent()),
        ])->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith(self::CLAUDE_CALLBACK.'?', $location);
        $this->assertStringContainsString('error=access_denied', $location);
    }

    public function test_a_redirect_the_client_did_not_register_is_never_followed(): void
    {
        $user = $this->teamMember();
        $clientId = $this->register('Claude');
        [, $query] = $this->authorizationQuery($clientId);

        $query['redirect_uri'] = 'https://chatgpt.com/connector/oauth/someone-else';

        $response = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($query));

        $this->assertNotSame(302, $response->getStatusCode());
        $this->assertStringNotContainsString('someone-else', (string) $response->headers->get('Location'));
    }

    public function test_a_loopback_client_is_flagged_on_the_consent_screen(): void
    {
        $user = $this->teamMember();
        $clientId = $this->register('Claude Code', 'http://localhost:33418/callback');
        [, $query] = $this->authorizationQuery($clientId, 'http://localhost:33418/callback');

        $this->actingAs($user)
            ->get('/oauth/authorize?'.http_build_query($query))
            ->assertOk()
            ->assertSee('a program running on this computer');
    }

    public function test_a_customer_is_told_they_share_only_what_was_shared_with_them(): void
    {
        $customer = $this->customer();
        $clientId = $this->register('ChatGPT', 'https://chatgpt.com/connector/oauth/abc');
        [, $query] = $this->authorizationQuery($clientId, 'https://chatgpt.com/connector/oauth/abc');

        $this->actingAs($customer)
            ->get('/oauth/authorize?'.http_build_query($query))
            ->assertOk()
            ->assertSee('chatgpt.com')
            ->assertSee('only the tickets and pages that have been shared with you')
            // A customer is told what they can change, and nothing staff can.
            ->assertSee('Reads and replies.')
            ->assertDontSee('Deleting a ticket');
    }

    // -----------------------------------------------------------------

    private function register(string $name, string $callback = self::CLAUDE_CALLBACK): string
    {
        return (string) $this->postJson('/oauth/register', [
            'client_name' => $name,
            'redirect_uris' => [$callback],
        ])->assertCreated()->json('client_id');
    }

    /**
     * A PKCE verifier and the authorization request Claude would send.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function authorizationQuery(string $clientId, string $callback = self::CLAUDE_CALLBACK): array
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [$verifier, [
            'client_id' => $clientId,
            'redirect_uri' => $callback,
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => 'state-from-claude',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => url('/mcp'),
        ]];
    }

    /**
     * @param  array<string, string>  $query
     */
    private function approve(User $user, string $clientId, array $query): string
    {
        $consent = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($query))->assertOk();

        $location = (string) $this->post(route('passport.authorizations.approve'), [
            'state' => '',
            'client_id' => $clientId,
            'auth_token' => $this->authToken($consent->getContent()),
        ])->headers->get('Location');

        parse_str((string) parse_url($location, PHP_URL_QUERY), $returned);

        return (string) ($returned['code'] ?? '');
    }

    private function authToken(string|false $html): string
    {
        preg_match('/name="auth_token" value="([^"]+)"/', (string) $html, $matches);

        $this->assertNotEmpty($matches[1] ?? null, 'The consent screen carries no auth_token.');

        return $matches[1];
    }
}
