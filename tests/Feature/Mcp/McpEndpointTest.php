<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Enums\AiToolChannel;
use App\Models\AiToolInvocation;
use App\Services\AI\Tools\SearchTicketsTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Enums\MetaKey;
use Laravel\Mcp\Enums\RequestHeader;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithMcp;
use Tests\TestCase;

/**
 * The remote MCP endpoint as Claude and ChatGPT meet it.
 *
 * The first section is the handshake that decides whether a client can
 * connect at all: an unauthenticated request must be a 401 carrying the
 * WWW-Authenticate pointer to the OAuth metadata — never a redirect to the
 * login page and never a server error — whatever the client puts in its
 * Accept header. The rest is the read-tool surface over JSON-RPC.
 */
class McpEndpointTest extends TestCase
{
    use InteractsWithMcp;
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // The handshake
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function acceptHeaders(): array
    {
        return [
            'the MCP default' => ['application/json, text/event-stream'],
            'event stream first' => ['text/event-stream, application/json'],
            'a browser' => ['text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'],
            'anything' => ['*/*'],
            'no header at all' => [null],
        ];
    }

    #[DataProvider('acceptHeaders')]
    public function test_an_unauthenticated_request_gets_the_oauth_challenge(?string $accept): void
    {
        $this->setUpPassportKeys();

        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($accept !== null) {
            $server['HTTP_ACCEPT'] = $accept;
        }

        $response = $this->call('POST', '/mcp', [], [], [], $server, (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
        ]));

        $response->assertStatus(401);

        $this->assertSame(
            'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"',
            $response->headers->get('WWW-Authenticate'),
        );
    }

    public function test_a_token_that_is_not_a_token_gets_the_same_challenge(): void
    {
        $this->setUpPassportKeys();

        $response = $this->mcp('tools/list', [], ['Authorization' => 'Bearer not-a-real-token']);

        $response->assertStatus(401);
        $this->assertStringContainsString('resource_metadata=', (string) $response->headers->get('WWW-Authenticate'));
    }

    public function test_only_post_is_spoken(): void
    {
        $this->get('/mcp')->assertStatus(405);
        $this->delete('/mcp')->assertStatus(405);
    }

    public function test_initialize_names_the_server_and_the_reporting_timezone(): void
    {
        config(['workspace.board_defaults.timezone' => 'Europe/Stockholm']);

        $response = $this->mcpAs($this->teamMember())->mcp('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'test', 'version' => '1'],
        ]);

        $response->assertOk();
        $this->assertSame('Nexora', $response->json('result.serverInfo.name'));
        $this->assertStringContainsString('Europe/Stockholm', (string) $response->json('result.instructions'));
        $this->assertStringContainsString('read-only', (string) $response->json('result.instructions'));
    }

    /**
     * Clients on the 2026-07-28 protocol skip the handshake: every request
     * carries its protocol version in _meta and mirrors the method (and the
     * tool name) in headers. Claude and ChatGPT move to it on their own
     * schedule, so the endpoint has to answer both generations.
     */
    public function test_a_client_on_the_newest_protocol_can_discover_list_and_call_without_a_handshake(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->ticketOn($board, $team, ['title' => 'Found on the newest protocol']);

        $this->mcpAs($team);

        $meta = [
            MetaKey::PROTOCOL_VERSION->value => '2026-07-28',
            MetaKey::CLIENT_CAPABILITIES->value => (object) [],
            MetaKey::CLIENT_INFO->value => ['name' => 'test', 'version' => '1'],
        ];
        $headers = static fn (string $method, ?string $name = null): array => array_filter([
            RequestHeader::PROTOCOL_VERSION->value => '2026-07-28',
            RequestHeader::METHOD->value => $method,
            RequestHeader::NAME->value => $name,
        ]);

        $discover = $this->mcp('server/discover', ['_meta' => $meta], $headers('server/discover'))->assertOk();
        $this->assertContains('2026-07-28', $discover->json('result.supportedVersions'));
        $this->assertStringContainsString('read-only', (string) $discover->json('result.instructions'));

        $tools = $this->mcp('tools/list', ['_meta' => $meta], $headers('tools/list'))->assertOk();
        $this->assertContains('search_tickets', array_column($tools->json('result.tools') ?? [], 'name'));

        $call = $this->mcp('tools/call', [
            '_meta' => $meta,
            'name' => 'search_tickets',
            'arguments' => ['query' => 'newest'],
        ], $headers('tools/call', 'search_tickets'))->assertOk();

        $this->assertFalse($call->json('result.isError') ?? false);
        $this->assertStringContainsString('Found on the newest protocol', (string) $call->json('result.content.0.text'));
    }

    // -----------------------------------------------------------------
    // The tools
    // -----------------------------------------------------------------

    public function test_staff_get_the_staff_tools_every_one_read_only_and_with_its_own_schema(): void
    {
        $team = $this->teamMember();
        $this->boardWithColumns([$team]);

        $tools = collect($this->mcpAs($team)->mcp('tools/list')->assertOk()->json('result.tools'))->keyBy('name');

        foreach (['get_ticket', 'search_tickets', 'get_completed_tickets', 'get_board', 'get_statistics', 'get_activity', 'get_code_activity', 'get_github_repository'] as $name) {
            $this->assertTrue($tools->has($name), $name.' should be offered to staff');
        }

        $this->assertFalse($tools->has('search_external_knowledge'));
        $this->assertSame($tools->count(), $tools->keys()->unique()->count());

        foreach ($tools as $name => $tool) {
            $this->assertTrue($tool['annotations']['readOnlyHint'] ?? false, $name.' must be read-only');
            $this->assertFalse($tool['annotations']['openWorldHint'] ?? true, $name);
        }

        $this->assertEquals(
            json_decode((string) json_encode(app(SearchTicketsTool::class)->inputSchema()), true),
            $tools['search_tickets']['inputSchema'],
        );
    }

    public function test_a_call_returns_the_tool_answer_and_is_audited_as_an_external_read(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->ticketOn($board, $team, ['title' => 'Asked about from Claude']);

        $result = $this->mcpAs($team)->mcpTool('search_tickets', ['query' => 'Claude']);

        $this->assertFalse($result['isError'] ?? false);
        $this->assertStringContainsString('Asked about from Claude', $this->mcpText($result));

        $row = AiToolInvocation::query()->sole();

        $this->assertSame('search_tickets', $row->tool);
        $this->assertSame(AiToolChannel::Mcp->value, $row->channel);
        $this->assertSame((int) $team->getKey(), (int) $row->user_id);
        $this->assertNull($row->ai_session_id);
        $this->assertNull($row->ai_chat_message_id);
    }

    public function test_a_lookup_that_finds_nothing_comes_back_as_an_error_result(): void
    {
        $team = $this->teamMember();
        $this->boardWithColumns([$team]);

        $result = $this->mcpAs($team)->mcpTool('get_ticket', ['ticket' => 'NOPE-404']);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('No ticket matches', $this->mcpText($result));
    }

    public function test_arguments_outside_the_schema_come_back_as_an_error_result(): void
    {
        $team = $this->teamMember();
        $this->boardWithColumns([$team]);

        $result = $this->mcpAs($team)->mcpTool('get_statistics', ['metric' => 'everything_please']);

        $this->assertTrue($result['isError']);
    }

    public function test_statistics_reach_an_external_client_without_in_app_rendering_hints(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->ticketOn($board, $team);

        $text = $this->mcpText($this->mcpAs($team)->mcpTool('get_statistics', ['metric' => 'totals']));

        $this->assertStringContainsString("Total\t1", $text);
        $this->assertStringNotContainsString('nexora-', $text);
    }

    public function test_an_unknown_tool_is_a_protocol_error(): void
    {
        $this->mcpAs($this->teamMember())
            ->mcp('tools/call', ['name' => 'drop_all_tables', 'arguments' => (object) []])
            ->assertStatus(400)
            ->assertJsonPath('error.code', -32602)
            ->assertJsonMissingPath('result');
    }

    public function test_calls_are_rate_limited_per_person(): void
    {
        config(['mcp.server.rate_limit' => 2]);

        $first = $this->teamMember();
        $second = $this->teamMember();

        $this->mcpAs($first)->mcp('tools/list')->assertOk();
        $this->mcp('tools/list')->assertOk();
        $this->mcp('tools/list')->assertStatus(429);

        $this->app['auth']->forgetGuards();

        $this->mcpAs($second)->mcp('tools/list')->assertOk();
    }
}
