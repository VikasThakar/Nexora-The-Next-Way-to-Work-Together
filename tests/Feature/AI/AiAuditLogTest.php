<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Enums\AiCapabilityMode;
use App\Livewire\Ai\Assistant;
use App\Models\AiToolInvocation;
use App\Models\Board;
use App\Models\User;
use App\Services\AI\AiContextScope;
use App\Services\AI\AiSessionManager;
use App\Services\AI\Audit\AiAuditLogger;
use App\Services\AI\Tools\AiToolContext;
use App\Services\AI\Tools\AiToolRegistry;
use App\Services\BoardAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The audit ledger.
 *
 * The requirement was that every meaningful AI action be auditable, and that
 * secrets never be stored. Both halves are tested here, and the second half is
 * the interesting one: the sanitiser is an allow-list by *shape* rather than a
 * deny-list by key name, because a deny-list fails the first time somebody
 * invents a new name for a secret.
 *
 * What is deliberately absent from every row is the tool's output. A result is
 * a copy of internal workspace content, and a copy in a table with its own
 * visibility rules is a second place for it to leak from.
 */
class AiAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function contextFor(User $user, ?Board $board = null): AiToolContext
    {
        $scope = $board instanceof Board
            ? AiContextScope::board($board)
            : AiContextScope::workspace();

        return new AiToolContext(
            user: $user,
            scope: $scope,
            session: app(AiSessionManager::class)->start($scope, $user),
            mode: AiCapabilityMode::Agent,
            staff: app(BoardAccess::class)->canSeeInternalContent($user),
        );
    }

    // -----------------------------------------------------------------
    // A row per invocation
    // -----------------------------------------------------------------

    public function test_a_successful_lookup_is_recorded_with_its_subject(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Recorded ticket']);

        app(AiToolRegistry::class)->invoke(
            'get_ticket',
            ['ticket' => $ticket->key()],
            $this->contextFor($team, $board),
        );

        $row = AiToolInvocation::query()->sole();

        $this->assertSame('get_ticket', $row->tool);
        $this->assertSame(AiToolInvocation::CATEGORY_READ, $row->category);
        $this->assertSame(AiToolInvocation::OUTCOME_OK, $row->outcome);
        $this->assertTrue($row->success);
        $this->assertSame($ticket->key(), $row->target);
        $this->assertSame((int) $team->getKey(), (int) $row->user_id);
        $this->assertSame((int) $board->getKey(), (int) $row->board_id);

        // The arguments, sanitised. Safe here because no tool in this product
        // takes a credential — and the sanitiser is what keeps that true.
        $this->assertSame(['ticket' => $ticket->key()], $row->input);

        // Its size, not its contents.
        $this->assertGreaterThan(0, (int) $row->result_characters);
        $this->assertStringNotContainsString('Recorded ticket', (string) $row->message);
    }

    /**
     * A refused call is the one most worth having a record of.
     */
    public function test_a_refused_call_is_recorded_as_refused(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        app(AiToolRegistry::class)->invoke(
            'get_code_activity',
            ['board' => $board->slug],
            $this->contextFor($customer, $board),
        );

        $row = AiToolInvocation::query()->sole();

        $this->assertSame('get_code_activity', $row->tool);
        $this->assertFalse($row->success);
        $this->assertSame(AiToolInvocation::OUTCOME_REFUSED, $row->outcome);
        $this->assertNotNull($row->message);
    }

    public function test_a_call_with_bad_arguments_is_recorded_as_invalid(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        app(AiToolRegistry::class)->invoke(
            'get_ticket',
            // No ticket at all: the one required argument.
            ['board' => $board->slug],
            $this->contextFor($team, $board),
        );

        $row = AiToolInvocation::query()->sole();

        $this->assertSame(AiToolInvocation::OUTCOME_INVALID, $row->outcome);
        $this->assertFalse($row->success);
        $this->assertStringContainsString('required', (string) $row->message);
    }

    public function test_a_lookup_that_finds_nothing_is_recorded_as_not_found(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        app(AiToolRegistry::class)->invoke(
            'get_ticket',
            ['ticket' => $board->ticket_prefix.'-4242'],
            $this->contextFor($team, $board),
        );

        $row = AiToolInvocation::query()->sole();

        $this->assertSame(AiToolInvocation::OUTCOME_NOT_FOUND, $row->outcome);
        $this->assertFalse($row->success);
    }

    public function test_a_question_asked_through_the_panel_is_audited_against_the_session(): void
    {
        $provider = $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Audited from the panel']);

        $provider->willLookUp('get_ticket', ['ticket' => $ticket->key()], 'Done.');

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'What is in '.$ticket->key().'?')
            ->call('send');

        $row = AiToolInvocation::query()->sole();

        $this->assertNotNull($row->ai_session_id);
        $this->assertSame((int) $team->getKey(), (int) $row->user_id);
        $this->assertSame($ticket->key(), $row->target);
    }

    // -----------------------------------------------------------------
    // What is never stored
    // -----------------------------------------------------------------

    /**
     * The sanitiser, exercised directly with the shapes that matter.
     *
     * It is an allow-list by shape: scalars survive, bounded and truncated;
     * anything else is replaced with a description of itself. That is why a key
     * called `api_key` needs no special case — a string is a string, and the
     * point is that no tool in this product has a credential to pass.
     */
    public function test_the_sanitiser_keeps_only_bounded_scalars(): void
    {
        $logger = app(AiAuditLogger::class);

        $clean = $logger->sanitise([
            'ticket' => 'AQD-42',
            'limit' => 10,
            'overdue' => true,
            'labels' => ['bug', 'urgent'],
            // An object, a closure, a nested structure: described, not stored.
            'user' => new \stdClass,
            'callback' => static fn (): string => 'x',
            'nested' => ['deep' => ['deeper' => 'value']],
            'long' => str_repeat('a', 5000),
            // A key that is not a schema property name at all.
            'weird key with spaces' => 'dropped',
        ]);

        $this->assertSame('AQD-42', $clean['ticket']);
        $this->assertSame(10, $clean['limit']);
        $this->assertTrue($clean['overdue']);
        $this->assertSame(['bug', 'urgent'], $clean['labels']);

        $this->assertStringContainsString('not recorded', (string) $clean['user']);
        $this->assertStringContainsString('not recorded', (string) $clean['callback']);
        $this->assertStringContainsString('not recorded', (string) $clean['nested']);

        $this->assertLessThan(400, mb_strlen((string) $clean['long']));

        $this->assertArrayNotHasKey('weird key with spaces', $clean);
    }

    public function test_an_empty_argument_list_is_stored_as_null_rather_than_an_empty_object(): void
    {
        $this->assertNull(app(AiAuditLogger::class)->sanitise([]));
    }

    /**
     * A failure to write an audit row must not break the answer.
     *
     * The trade is deliberate and worth pinning: an audit row matters, and it
     * matters less than the answer somebody is waiting for. Simulated by
     * dropping the table, which is the bluntest version of a locked or full
     * one.
     */
    public function test_a_broken_ledger_does_not_break_the_assistant(): void
    {
        $provider = $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Still answered']);

        $provider->willLookUp('get_ticket', ['ticket' => $ticket->key()], 'Answered anyway.');

        Schema::drop('ai_tool_invocations');

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'What is in '.$ticket->key().'?')
            ->call('send')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('ai_chat_messages', ['content' => 'Answered anyway.']);
    }

    // -----------------------------------------------------------------
    // Who may read the ledger
    // -----------------------------------------------------------------

    /**
     * Staff only, including for a customer's own rows.
     *
     * Stricter than it looks, and deliberately: a row's target is workspace
     * content, so a customer reading their own audit trail would be reading a
     * list of the tickets the assistant consulted while answering them.
     */
    public function test_a_customer_reads_no_audit_rows_at_all(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        app(AiToolRegistry::class)->invoke(
            'get_board',
            ['board' => $board->slug],
            $this->contextFor($customer, $board),
        );

        $this->assertSame(1, AiToolInvocation::query()->count());
        $this->assertSame(0, AiToolInvocation::query()->visibleTo($customer)->count());
    }

    public function test_a_team_member_reads_only_the_boards_they_can_reach(): void
    {
        $mine = $this->teamMember();
        $theirs = $this->teamMember();

        $myBoard = $this->boardWithColumns([$mine], ['slug' => 'mine', 'ticket_prefix' => 'MINE']);
        $theirBoard = $this->boardWithColumns([$theirs], ['slug' => 'theirs', 'ticket_prefix' => 'THRS']);

        app(AiToolRegistry::class)->invoke('get_board', ['board' => 'mine'], $this->contextFor($mine, $myBoard));
        app(AiToolRegistry::class)->invoke('get_board', ['board' => 'theirs'], $this->contextFor($theirs, $theirBoard));

        $visible = AiToolInvocation::query()->visibleTo($mine)->get();

        $this->assertCount(1, $visible);
        $this->assertSame((int) $myBoard->getKey(), (int) $visible->first()->board_id);
    }

    /**
     * A workspace-scoped invocation has no board to check, so it is protected
     * by ownership — the same rule a board-less conversation follows.
     */
    public function test_a_workspace_scoped_row_is_readable_only_by_its_owner(): void
    {
        $mine = $this->teamMember();
        $other = $this->admin();

        $this->boardWithColumns([$mine]);

        app(AiToolRegistry::class)->invoke('get_board', [], $this->contextFor($mine));

        $this->assertSame(1, AiToolInvocation::query()->visibleTo($mine)->count());

        // An administrator sees every board, and still not somebody else's
        // board-less conversation.
        $this->assertSame(0, AiToolInvocation::query()->visibleTo($other)->count());
    }

    public function test_the_settings_screen_shows_the_ledger_to_an_administrator(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin], ['slug' => 'platform']);

        app(AiToolRegistry::class)->invoke(
            'get_board',
            ['board' => 'platform'],
            $this->contextFor($admin, $board),
        );

        $this->withConfirmedPassword()
            ->actingAs($admin)
            ->get(route('admin.ai'))
            ->assertOk()
            ->assertSee('What the AI has been doing')
            ->assertSee('get_board');
    }
}
