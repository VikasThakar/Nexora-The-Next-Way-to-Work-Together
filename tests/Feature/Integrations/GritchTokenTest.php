<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\AiCapabilityMode;
use App\Models\AiToolInvocation;
use App\Models\Board;
use App\Models\IntegrationIdentity;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Gritch\GritchAssertion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Tests\Support\InteractsWithMcp;
use Tests\TestCase;

/**
 * Gritch's admin AI: an assertion traded for a short MCP token, then the
 * ordinary /mcp tools — the same rules, confirmations and audit as Claude or
 * ChatGPT — reaching the Gritch board and nothing else.
 *
 * Every /mcp call here carries the real bearer token the endpoint issued, not
 * Passport::actingAs, so the whole path is the production one.
 */
class GritchTokenTest extends TestCase
{
    use InteractsWithMcp;
    use RefreshDatabase;

    private const SALESYS_ID = '65f0c0ffee0000000000abcd';

    private Board $gritch;

    private Board $other;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gritch.enabled' => true,
            'gritch.secret' => str_repeat('k', 48),
            'gritch.board' => 'gritch',
            'gritch.embed_host' => 'nexora.getgritch.test',
        ]);

        $this->setUpPassportKeys();
        $this->aiMode(AiCapabilityMode::Operator);

        $this->colleague = $this->teamMember(['name' => 'Alex Round']);
        $this->gritch = $this->boardWithColumns([$this->colleague], ['name' => 'Gritch', 'slug' => 'gritch']);
        $this->other = $this->boardWithColumns([$this->teamMember()], ['name' => 'Aqueduct Platform', 'slug' => 'aqueduct-platform']);
    }

    public function test_an_assertion_buys_a_short_mcp_token_through_the_gritch_client(): void
    {
        $response = $this->token()->assertOk();

        $this->assertSame('Bearer', $response->json('token_type'));
        $this->assertEqualsWithDelta(15 * 60, $response->json('expires_in'), 5);
        $this->assertSame('gritch', $response->json('board'));

        $token = Passport::token()->newQuery()->sole();
        $this->assertSame('Gritch', Passport::client()->newQuery()->findOrFail($token->client_id)->name);
        $this->assertSame(['mcp:use'], $token->scopes);
        $this->assertSame($this->integrationUser()->id, (int) $token->user_id);
        $this->assertNotNull(IntegrationIdentity::query()->sole()->last_token_at);
    }

    public function test_the_newest_personal_access_client_is_always_gritch(): void
    {
        $this->token()->assertOk();
        app(ClientRepository::class)->createPersonalAccessGrantClient('Somebody else', 'users');

        $this->token()->assertOk();

        $newest = Passport::token()->newQuery()->latest('created_at')->get()->last();
        $this->assertSame('Gritch', Passport::client()->newQuery()->findOrFail($newest->client_id)->name);
    }

    public function test_the_ai_creates_moves_assigns_comments_on_and_deletes_a_ticket_and_history_says_via_gritch(): void
    {
        $bearer = $this->bearer();

        $created = $this->call_($bearer, 'create_ticket', [
            'board' => 'gritch',
            'title' => 'Fix the login page',
            'description' => 'BankID hangs after scanning.',
            'priority' => 'high',
        ]);
        $this->assertFalse($created['isError'] ?? false, $this->mcpText($created));

        $ticket = Ticket::query()->where('title', 'Fix the login page')->sole();
        $this->assertSame($this->gritch->id, $ticket->board_id);
        $this->assertSame($this->integrationUser()->id, $ticket->created_by_id);

        $moved = $this->call_($bearer, 'update_ticket', [
            'ticket' => $ticket->key(),
            'status' => 'In Progress',
            'assignee' => 'Alex Round',
        ]);
        $this->assertFalse($moved['isError'] ?? false, $this->mcpText($moved));
        $this->assertSame('In Progress', $ticket->fresh()->column->name);
        $this->assertSame($this->colleague->id, $ticket->fresh()->assignee_id);

        $commented = $this->call_($bearer, 'add_comment', ['ticket' => $ticket->key(), 'body' => 'Looking at it now.']);
        $this->assertFalse($commented['isError'] ?? false, $this->mcpText($commented));
        $this->assertSame(1, $ticket->comments()->count());

        $pending = $this->call_($bearer, 'delete_ticket', ['ticket' => $ticket->key()]);
        $this->assertSame(1, preg_match('/confirmation: "([A-Z0-9]{6})"/', $this->mcpText($pending), $code));
        $this->assertNotNull($ticket->fresh(), 'Nothing is deleted before the code comes back.');

        $deleted = $this->call_($bearer, 'delete_ticket', ['ticket' => $ticket->key(), 'confirmation' => $code[1]]);
        $this->assertFalse($deleted['isError'] ?? false, $this->mcpText($deleted));
        $this->assertNull(Ticket::query()->find($ticket->id));

        $audit = AiToolInvocation::query()->where('tool', 'create_ticket')->sole();
        $this->assertSame('mcp', $audit->channel);
        $this->assertStringContainsString('via Gritch', (string) $audit->message);
    }

    public function test_another_board_cannot_be_read_or_written_with_the_token(): void
    {
        $bearer = $this->bearer();
        $hidden = $this->ticketOn($this->other, $this->other->members()->first(), ['title' => 'Secret roadmap']);

        $read = $this->call_($bearer, 'get_ticket', ['ticket' => $hidden->key()]);
        $this->assertStringNotContainsString('Secret roadmap', $this->mcpText($read));

        $search = $this->call_($bearer, 'search_tickets', ['query' => 'Secret roadmap']);
        $this->assertStringNotContainsString('Secret roadmap', $this->mcpText($search));

        $write = $this->call_($bearer, 'create_ticket', ['board' => 'aqueduct-platform', 'title' => 'Sneaky']);
        $this->assertTrue($write['isError'] ?? false);
        $this->assertSame(0, Ticket::query()->where('title', 'Sneaky')->count());
    }

    public function test_revoking_disconnects_the_token_at_once(): void
    {
        $bearer = $this->bearer();

        $this->postJson('/integrations/gritch/revoke', ['assertion' => $this->assertion('revoke')])
            ->assertOk()
            ->assertJson(['revoked' => true]);

        $this->app['auth']->forgetGuards();

        $this->mcp('tools/list', [], ['Authorization' => 'Bearer '.$bearer])->assertUnauthorized();
    }

    public function test_revoking_somebody_nexora_never_saw_is_harmless(): void
    {
        $this->postJson('/integrations/gritch/revoke', ['assertion' => $this->assertion('revoke', ['sub' => 'never-seen-0001'])])
            ->assertOk()
            ->assertJson(['revoked' => false]);
    }

    public function test_assertions_of_the_wrong_kind_or_used_twice_are_refused(): void
    {
        $this->postJson('/integrations/gritch/token', ['assertion' => $this->assertion('embed')])->assertUnauthorized();
        $this->postJson('/integrations/gritch/revoke', ['assertion' => $this->assertion('token')])->assertUnauthorized();

        $once = $this->assertion('token');
        $this->postJson('/integrations/gritch/token', ['assertion' => $once])->assertOk();
        $this->postJson('/integrations/gritch/token', ['assertion' => $once])->assertUnauthorized();

        $this->assertSame(1, Passport::token()->newQuery()->count());
    }

    public function test_a_deactivated_account_gets_no_token(): void
    {
        $this->token()->assertOk();
        $this->integrationUser()->forceFill(['deactivated_at' => now()])->save();

        $this->token()->assertForbidden()->assertJsonPath('message', 'Your Nexora access has been turned off. Ask a Nexora administrator.');
    }

    public function test_the_server_endpoints_leave_no_session_behind(): void
    {
        $this->token()->assertOk()->assertCookieMissing(config('session.cookie'));
    }

    public function test_switched_off_means_not_found(): void
    {
        config(['gritch.enabled' => false]);

        $this->token()->assertNotFound();
        $this->postJson('/integrations/gritch/revoke', ['assertion' => $this->assertion('revoke')])->assertNotFound();
    }

    // -----------------------------------------------------------------

    private function token(): TestResponse
    {
        return $this->postJson('/integrations/gritch/token', ['assertion' => $this->assertion('token')]);
    }

    private function bearer(): string
    {
        $bearer = (string) $this->token()->assertOk()->json('access_token');
        $this->app['auth']->forgetGuards();

        return $bearer;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{content: list<array<string, mixed>>, isError?: bool}
     */
    private function call_(string $bearer, string $tool, array $arguments): array
    {
        $this->app['auth']->forgetGuards();

        return $this->mcp('tools/call', ['name' => $tool, 'arguments' => $arguments], ['Authorization' => 'Bearer '.$bearer])
            ->assertOk()
            ->json('result');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function assertion(string $type, array $overrides = []): string
    {
        $now = now()->getTimestamp();

        return GritchAssertion::sign([
            'iss' => 'gritch',
            'aud' => 'nexora',
            'typ' => $type,
            'sub' => self::SALESYS_ID,
            'name' => 'Vikas Thakar',
            'iat' => $now,
            'exp' => $now + 60,
            'jti' => Str::random(32),
            ...$overrides,
        ]);
    }

    private function integrationUser(): User
    {
        return IntegrationIdentity::query()->where('external_id', self::SALESYS_ID)->sole()->user()->sole();
    }
}
