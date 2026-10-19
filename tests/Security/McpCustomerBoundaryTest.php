<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Actions\Tickets\MoveTicket;
use App\Enums\CommentStream;
use App\Models\Board;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;
use Tests\Support\InteractsWithMcp;
use Tests\TestCase;

/**
 * The customer boundary, as it applies to an external AI client.
 *
 * A customer who connects Claude or ChatGPT to Nexora must get exactly what
 * the in-app customer assistant gets: the customer tool set, and only what has
 * been shared with them. Every test here goes through the real HTTP endpoint
 * with a token for the customer, so the route, the middleware and the tools
 * are all under test together.
 *
 * The board in most tests carries one shared ticket ("OTTER") and one internal
 * ticket ("ZEBRA"), both finished yesterday. ZEBRA must not appear anywhere —
 * not in a list, a count, a completion report or a direct lookup by key.
 */
class McpCustomerBoundaryTest extends TestCase
{
    use InteractsWithMcp;
    use RefreshDatabase;

    public function test_a_customer_is_offered_exactly_the_customer_tools(): void
    {
        $customer = $this->customer();
        $this->boardWithColumns([$customer]);

        $names = $this->mcpAs($customer)->mcpToolNames();
        sort($names);

        $this->assertSame([
            'get_board',
            'get_completed_tickets',
            'get_documentation_page',
            'get_statistics',
            'get_ticket',
            'search_documentation',
            'search_tickets',
        ], $names);
    }

    public function test_a_customer_calling_a_staff_tool_by_name_gets_nothing(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        foreach (['get_activity', 'get_code_activity', 'get_github_repository'] as $tool) {
            $this->mcpAs($customer)
                ->mcp('tools/call', ['name' => $tool, 'arguments' => ['board' => $board->slug]])
                ->assertStatus(400)
                ->assertJsonPath('error.code', -32602)
                ->assertJsonMissingPath('result');
        }
    }

    public function test_an_internal_ticket_reaches_a_customer_through_no_tool(): void
    {
        [$customer, $board, $internal] = $this->boardWithOtterAndZebra();

        $this->mcpAs($customer);

        $answers = [
            'search_tickets' => $this->mcpTool('search_tickets', []),
            'search_tickets by word' => $this->mcpTool('search_tickets', ['query' => 'ZEBRA']),
            'get_board' => $this->mcpTool('get_board', ['board' => $board->slug]),
            'get_statistics' => $this->mcpTool('get_statistics', ['metric' => 'totals']),
            'get_completed_tickets' => $this->mcpTool('get_completed_tickets', ['period' => 'yesterday']),
            'get_ticket by key' => $this->mcpTool('get_ticket', ['ticket' => $internal->key()]),
        ];

        foreach ($answers as $label => $result) {
            $this->assertStringNotContainsString('ZEBRA', $this->mcpText($result), $label);
        }

        $this->assertStringContainsString('OTTER', $this->mcpText($answers['search_tickets']));
        $this->assertStringContainsString("Total\t1", $this->mcpText($answers['get_statistics']));
        $this->assertStringContainsString('Total: 1 completed ticket.', $this->mcpText($answers['get_completed_tickets']));

        // Asked for by its exact key, an internal ticket reads as missing — the
        // same answer a ticket that does not exist gets.
        $this->assertTrue($answers['get_ticket by key']['isError']);
        $this->assertStringContainsString('No ticket matches', $this->mcpText($answers['get_ticket by key']));
    }

    public function test_internal_notes_and_unpublished_pages_stay_internal(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $shared = $this->ticketOn($board, $team, ['title' => 'OTTER shared', 'customer_visible' => true]);
        $this->commentOn($shared, $team, 'INTERNAL NOTE the client must not read this', CommentStream::Internal);
        $this->commentOn($shared, $team, 'Customer-facing reply', CommentStream::Customer);

        $this->docPageOn($board, $team, ['title' => 'INTERNAL runbook', 'body_md' => 'Escalate to on-call.']);
        $this->publishedPageOn($board, $team, ['title' => 'Getting started', 'body_md' => 'Open your board.']);

        $this->mcpAs($customer);

        $ticket = $this->mcpText($this->mcpTool('get_ticket', ['ticket' => $shared->key()]));
        $this->assertStringContainsString('Customer-facing reply', $ticket);
        $this->assertStringNotContainsString('INTERNAL NOTE', $ticket);

        $docs = $this->mcpText($this->mcpTool('search_documentation', ['query' => 'runbook']));
        $this->assertStringNotContainsString('INTERNAL runbook', $docs);
        $this->assertStringNotContainsString('on-call', $docs);
    }

    public function test_a_board_the_customer_is_not_on_does_not_exist_for_them(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $this->boardWithColumns([$team, $customer]);

        $elsewhere = $this->boardWithColumns([$team], ['ticket_prefix' => 'ELSE']);
        $foreign = $this->ticketOn($elsewhere, $team, ['title' => 'FOREIGN shared elsewhere', 'customer_visible' => true]);

        $this->mcpAs($customer);

        foreach ([
            'get_board' => ['get_board', ['board' => $elsewhere->slug]],
            'get_completed_tickets' => ['get_completed_tickets', ['board' => $elsewhere->slug]],
            'search_tickets' => ['search_tickets', ['board' => $elsewhere->slug]],
            'get_ticket' => ['get_ticket', ['ticket' => $foreign->key()]],
        ] as $label => [$tool, $arguments]) {
            $result = $this->mcpTool($tool, $arguments);

            $this->assertTrue($result['isError'] ?? false, $label);
            $this->assertStringNotContainsString('FOREIGN', $this->mcpText($result), $label);
        }

        $this->assertStringNotContainsString('FOREIGN', $this->mcpText($this->mcpTool('search_tickets', [])));
    }

    public function test_arguments_that_try_to_name_another_viewer_are_ignored(): void
    {
        [$customer, $board] = $this->boardWithOtterAndZebra();
        $admin = $this->admin();

        $text = $this->mcpText($this->mcpAs($customer)->mcpTool('search_tickets', [
            'board' => $board->slug,
            'user_id' => $admin->getKey(),
            'staff' => true,
            'viewer' => $admin->email,
        ]));

        $this->assertStringContainsString('OTTER', $text);
        $this->assertStringNotContainsString('ZEBRA', $text);
    }

    public function test_a_deactivated_customer_is_refused_and_their_tokens_revoked(): void
    {
        $customer = $this->customer();
        $this->boardWithColumns([$customer]);

        $token = $this->liveTokenFor($customer);

        $customer->forceFill(['deactivated_at' => now()])->saveQuietly();

        $response = $this->mcpAs($customer)->mcp('tools/list');

        $response->assertStatus(401);
        $this->assertStringContainsString('resource_metadata=', (string) $response->headers->get('WWW-Authenticate'));
        $this->assertTrue((bool) $token->refresh()->revoked);
    }

    public function test_a_token_without_the_mcp_scope_is_refused(): void
    {
        $customer = $this->customer();
        $this->boardWithColumns([$customer]);

        $this->mcpAs($customer, [])->mcp('tools/list')->assertForbidden();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------

    /**
     * A board with a customer, one shared ticket and one internal ticket,
     * both moved to Done yesterday.
     *
     * @return array{0: User, 1: Board, 2: Ticket, 3: Ticket}
     */
    private function boardWithOtterAndZebra(): array
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        Carbon::setTestNow('2026-06-01 09:00:00');
        $shared = $this->ticketOn($board, $team, ['title' => 'OTTER shared', 'customer_visible' => true]);
        $internal = $this->ticketOn($board, $team, ['title' => 'ZEBRA internal']);

        Carbon::setTestNow('2026-06-09 10:00:00');
        $done = $this->columnNamed($board, 'Done');
        app(MoveTicket::class)->handle($shared, $done, 0, $team);
        app(MoveTicket::class)->handle($internal->refresh(), $done, 0, $team);

        Carbon::setTestNow('2026-06-10 12:00:00');

        return [$customer, $board, $internal, $shared];
    }

    /**
     * A real, unrevoked access-token row for the user, so revocation can be
     * observed. The client id is arbitrary: the column has no foreign key.
     */
    private function liveTokenFor(User $user): Token
    {
        $token = Passport::token();

        $token->forceFill([
            'id' => bin2hex(random_bytes(40)),
            'user_id' => $user->getKey(),
            'client_id' => (string) Str::uuid(),
            'name' => null,
            'scopes' => ['mcp:use'],
            'revoked' => false,
            'expires_at' => now()->addHour(),
        ])->save();

        return $token;
    }
}
