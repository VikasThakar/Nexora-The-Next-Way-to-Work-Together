<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Enums\AiCapabilityMode;
use App\Enums\CommentStream;
use App\Enums\TicketPriority;
use App\Enums\TicketType;
use App\Models\AiToolInvocation;
use App\Models\Comment;
use App\Models\Label;
use App\Models\Ticket;
use App\Models\TicketEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithMcp;
use Tests\TestCase;

/**
 * The remote MCP server's write tools, end to end over JSON-RPC.
 *
 * Each test sends real tools/call requests to POST /mcp as a person holding a
 * token, and asserts two things: what the database holds afterwards, and what
 * the AI app was told — because "never say it was done unless it was" is only
 * true if the answer is read back from the saved rows.
 */
class McpWriteToolsTest extends TestCase
{
    use InteractsWithMcp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aiMode(AiCapabilityMode::Operator);
    }

    // -----------------------------------------------------------------
    // Creating
    // -----------------------------------------------------------------

    public function test_staff_create_a_ticket_with_every_field_and_the_answer_reads_the_saved_row(): void
    {
        $team = $this->teamMember();
        $alex = $this->teamMember(['name' => 'Alex Round']);
        $board = $this->boardWithColumns([$team, $alex], ['name' => 'Aqueduct Platform', 'slug' => 'aqueduct-platform']);
        Label::factory()->create(['board_id' => $board->id, 'name' => 'bug']);

        $result = $this->mcpAs($team)->mcpTool('create_ticket', [
            'board' => 'Aqueduct Platform',
            'title' => 'Login times out',
            'description' => 'After 30 seconds.',
            'type' => 'bug',
            'priority' => 'high',
            'status' => 'In Progress',
            'assignee' => 'Alex Round',
            'labels' => ['bug'],
            'due_date' => '2026-11-03',
            'estimate' => 2,
            'visible_to_customer' => false,
        ]);

        $this->assertFalse($result['isError'] ?? false);

        $ticket = Ticket::query()->sole();

        $this->assertSame('Login times out', $ticket->title);
        $this->assertSame(TicketType::Bug, $ticket->type);
        $this->assertSame(TicketPriority::High, $ticket->priority);
        $this->assertSame('In Progress', $ticket->column->name);
        $this->assertSame($alex->id, $ticket->assignee_id);
        $this->assertSame(['bug'], $ticket->labels->pluck('name')->all());
        $this->assertSame('2026-11-03', $ticket->due_date?->toDateString());
        $this->assertFalse($ticket->customer_visible);
        $this->assertSame($team->id, $ticket->created_by_id);

        $text = $this->mcpText($result);

        $this->assertStringContainsString('✅ **Ticket created — ['.$ticket->key().'](', $text);
        $this->assertStringContainsString('🟡 In Progress', $text);
        $this->assertStringContainsString('👤 Alex Round', $text);
        $this->assertStringContainsString('⬆️ High', $text);
        $this->assertStringContainsString('🐞 Bug', $text);
        $this->assertStringContainsString('🔒 internal', $text);

        // Audited as a change from outside the product…
        $this->assertDatabaseHas('ai_tool_invocations', [
            'tool' => 'create_ticket',
            'category' => AiToolInvocation::CATEGORY_ACTION,
            'channel' => 'mcp',
            'outcome' => AiToolInvocation::OUTCOME_OK,
            'user_id' => $team->id,
            'board_id' => $board->id,
            'target' => $ticket->key(),
        ]);

        // …and the ticket's own history says who, and through what.
        $created = TicketEvent::query()->where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame($team->id, $created->actor_id);
        $this->assertSame('an AI app', $created->payload['via'] ?? null);
    }

    public function test_a_retried_create_makes_one_ticket(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->mcpAs($team);

        $arguments = ['board' => $board->slug, 'title' => 'Only once'];

        $first = $this->mcpTool('create_ticket', $arguments);
        $second = $this->mcpTool('create_ticket', $arguments);

        $this->assertSame(1, Ticket::query()->count());
        $this->assertFalse($second['isError'] ?? false);
        $this->assertStringContainsString('Already done', $this->mcpText($second));
        $this->assertStringContainsString(Ticket::query()->sole()->key(), $this->mcpText($first));
    }

    public function test_a_batch_is_all_or_nothing_and_names_the_ticket_at_fault(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->mcpAs($team);

        $refused = $this->mcpTool('create_tickets', [
            'board' => $board->slug,
            'tickets' => [
                ['title' => 'One'],
                ['title' => 'Two', 'status' => 'Shipped'],
                ['title' => 'Three'],
            ],
        ]);

        $this->assertTrue($refused['isError'] ?? false);
        $this->assertStringContainsString('Ticket 2', $this->mcpText($refused));
        $this->assertSame(0, Ticket::query()->count());

        $created = $this->mcpTool('create_tickets', [
            'board' => $board->slug,
            'tickets' => [['title' => 'One'], ['title' => 'Two', 'status' => 'Review'], ['title' => 'Three']],
        ]);

        $this->assertFalse($created['isError'] ?? false);
        $this->assertSame(['One', 'Two', 'Three'], Ticket::query()->orderBy('number')->pluck('title')->all());
        $this->assertStringContainsString('| Ticket | Title | Status | Assigned to | Priority |', $this->mcpText($created));
        $this->assertStringContainsString('🟣 Review', $this->mcpText($created));
    }

    // -----------------------------------------------------------------
    // Updating
    // -----------------------------------------------------------------

    public function test_an_update_reports_each_change_old_to_new_and_can_unassign_and_clear_labels(): void
    {
        $team = $this->teamMember();
        $alex = $this->teamMember(['name' => 'Alex Round']);
        $board = $this->boardWithColumns([$team, $alex]);
        $label = Label::factory()->create(['board_id' => $board->id, 'name' => 'bug']);
        $ticket = $this->ticketOn($board, $team, ['assignee_id' => $alex->id, 'label_ids' => [$label->id]]);

        $result = $this->mcpAs($team)->mcpTool('update_ticket', [
            'ticket' => $ticket->key(),
            'status' => 'Done',
            'assignee' => 'nobody',
            'labels' => [],
        ]);

        $this->assertFalse($result['isError'] ?? false);

        $ticket->refresh();
        $this->assertSame('Done', $ticket->column->name);
        $this->assertNull($ticket->assignee_id);
        $this->assertCount(0, $ticket->labels);

        $text = $this->mcpText($result);
        $this->assertStringContainsString('**Status:** ⚪ Backlog → 🟢 Done', $text);
        $this->assertStringContainsString('**Assigned to:** 👤 Alex Round → 👤 Unassigned', $text);
        $this->assertStringContainsString('**Labels:** bug → —', $text);
    }

    public function test_an_unknown_status_changes_nothing_and_says_so(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['priority' => TicketPriority::Low->value]);

        $result = $this->mcpAs($team)->mcpTool('update_ticket', [
            'ticket' => $ticket->key(),
            'status' => 'Shipped',
            'priority' => 'critical',
        ]);

        $this->assertTrue($result['isError'] ?? false);
        $this->assertStringStartsWith('❌ Not changed', $this->mcpText($result));

        // Neither field moved: everything is resolved before anything is written.
        $ticket->refresh();
        $this->assertSame('Backlog', $ticket->column->name);
        $this->assertSame(TicketPriority::Low, $ticket->priority);
    }

    public function test_a_customer_on_the_board_cannot_be_given_work(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer(['name' => 'Cara Client']);
        $board = $this->boardWithColumns([$team, $customer]);
        $ticket = $this->ticketOn($board, $team);

        $result = $this->mcpAs($team)->mcpTool('update_ticket', ['ticket' => $ticket->key(), 'assignee' => 'Cara Client']);

        $this->assertTrue($result['isError'] ?? false);
        $this->assertNull($ticket->refresh()->assignee_id);
    }

    public function test_a_change_made_since_the_ticket_was_read_is_not_overwritten(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Original']);

        $this->mcpAs($team);

        $stale = $this->mcpTool('update_ticket', [
            'ticket' => $ticket->key(),
            'title' => 'From a stale read',
            'if_unchanged_since' => $ticket->updated_at->copy()->subHour()->toIso8601String(),
        ]);

        $this->assertTrue($stale['isError'] ?? false);
        $this->assertStringContainsString('after it was read', $this->mcpText($stale));
        $this->assertSame('Original', $ticket->refresh()->title);

        $fresh = $this->mcpTool('update_ticket', [
            'ticket' => $ticket->key(),
            'title' => 'From a fresh read',
            'if_unchanged_since' => $ticket->updated_at->toIso8601String(),
        ]);

        $this->assertFalse($fresh['isError'] ?? false);
        $this->assertSame('From a fresh read', $ticket->refresh()->title);
    }

    public function test_the_checklist_can_be_added_to_ticked_off_and_trimmed(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $this->mcpAs($team);

        $this->mcpTool('update_checklist', ['ticket' => $ticket->key(), 'add' => ['Write the test', 'Fix the bug', 'Deploy']]);

        $result = $this->mcpTool('update_checklist', [
            'ticket' => $ticket->key(),
            'complete' => ['1'],
            'remove' => ['deploy'],
        ]);

        $this->assertFalse($result['isError'] ?? false);

        $items = $ticket->subtasks()->get();
        $this->assertSame(['Write the test', 'Fix the bug'], $items->pluck('title')->all());
        $this->assertSame([true, false], $items->pluck('completed')->map(fn ($done): bool => (bool) $done)->all());
        $this->assertStringContainsString('(1/2 done)', $this->mcpText($result));

        $ambiguous = $this->mcpTool('update_checklist', ['ticket' => $ticket->key(), 'complete' => ['the']]);
        $this->assertTrue($ambiguous['isError'] ?? false);
        $this->assertStringContainsString('more than one checklist item', $this->mcpText($ambiguous));
    }

    // -----------------------------------------------------------------
    // Comments
    // -----------------------------------------------------------------

    public function test_a_comment_is_an_internal_note_unless_asked_and_a_customer_comment_waits_for_confirmation(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);

        $this->mcpAs($team);

        $this->mcpTool('add_comment', ['ticket' => $ticket->key(), 'body' => 'Looking into it.']);

        $this->assertSame(CommentStream::Internal, Comment::query()->sole()->stream);

        $arguments = ['ticket' => $ticket->key(), 'body' => 'Fixed — please try again.', 'visible_to_customer' => true];

        $pending = $this->mcpTool('add_comment', $arguments);

        $this->assertFalse($pending['isError'] ?? false);
        $this->assertStringContainsString('Confirmation needed', $this->mcpText($pending));
        $this->assertSame(1, Comment::query()->count());

        $code = $this->confirmationCode($pending);

        $this->mcpTool('add_comment', [...$arguments, 'confirmation' => $code]);

        $this->assertSame(1, Comment::query()->where('stream', CommentStream::Customer->value)->count());

        // A changed text is a different message: the old code does not post it.
        $changed = $this->mcpTool('add_comment', [...$arguments, 'body' => 'Something else entirely', 'confirmation' => $code]);
        $this->assertStringContainsString('Confirmation needed', $this->mcpText($changed));
        $this->assertSame(1, Comment::query()->where('stream', CommentStream::Customer->value)->count());
    }

    // -----------------------------------------------------------------
    // Deleting
    // -----------------------------------------------------------------

    public function test_deleting_needs_a_code_for_this_person_and_this_ticket(): void
    {
        $team = $this->teamMember();
        $other = $this->teamMember();
        $board = $this->boardWithColumns([$team, $other]);
        $doomed = $this->ticketOn($board, $team, ['title' => 'Doomed']);
        $bystander = $this->ticketOn($board, $team, ['title' => 'Bystander']);

        $this->mcpAs($team);

        $pending = $this->mcpTool('delete_ticket', ['ticket' => $doomed->key()]);
        $this->assertStringContainsString('permanently deletes', $this->mcpText($pending));
        $this->assertNotNull($doomed->fresh());

        $code = $this->confirmationCode($pending);

        // A code is not a password for any ticket.
        $this->mcpTool('delete_ticket', ['ticket' => $bystander->key(), 'confirmation' => $code]);
        $this->assertNotNull($bystander->fresh());

        // A wrong code deletes nothing and offers a new one.
        $wrong = $this->mcpTool('delete_ticket', ['ticket' => $doomed->key(), 'confirmation' => 'ZZZZZZ']);
        $this->assertStringContainsString('not valid', $this->mcpText($wrong));
        $this->assertNotNull($doomed->fresh());

        // Nor is it anybody else's.
        $code = $this->confirmationCode($this->mcpTool('delete_ticket', ['ticket' => $doomed->key()]));
        $this->mcpAs($other)->mcpTool('delete_ticket', ['ticket' => $doomed->key(), 'confirmation' => $code]);
        $this->assertNotNull($doomed->fresh());

        $deleted = $this->mcpAs($team)->mcpTool('delete_ticket', ['ticket' => $doomed->key(), 'confirmation' => $code]);

        $this->assertNull($doomed->fresh());
        $this->assertStringContainsString('Deleted '.$doomed->key(), $this->mcpText($deleted));
        $this->assertNotNull($bystander->fresh());

        $this->assertDatabaseHas('ai_tool_invocations', [
            'tool' => 'delete_ticket',
            'outcome' => AiToolInvocation::OUTCOME_AWAITING,
            'user_id' => $team->id,
        ]);
    }

    // -----------------------------------------------------------------
    // Documentation
    // -----------------------------------------------------------------

    public function test_a_new_page_is_internal_and_an_internal_page_changes_at_once(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->mcpAs($team);

        $created = $this->mcpTool('create_doc_page', ['board' => $board->slug, 'title' => 'Runbook', 'body' => '# Steps']);

        $this->assertFalse($created['isError'] ?? false);
        $page = $board->docPages()->sole();
        $this->assertFalse($page->customer_visible);
        $this->assertStringContainsString('🔒 internal', $this->mcpText($created));

        $updated = $this->mcpTool('update_doc_page', ['board' => $board->slug, 'page' => $page->slug, 'body' => 'New steps']);

        $this->assertFalse($updated['isError'] ?? false);
        $this->assertSame('New steps', $page->refresh()->body_md);
    }

    public function test_a_published_page_waits_for_confirmation(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->publishedPageOn($board, $team, ['title' => 'Guide', 'body_md' => 'Old guide']);

        $this->mcpAs($team);

        $arguments = ['board' => $board->slug, 'page' => $page->slug, 'body' => 'New guide'];

        $pending = $this->mcpTool('update_doc_page', $arguments);

        $this->assertStringContainsString('customers read it', $this->mcpText($pending));
        $this->assertSame('Old guide', $page->refresh()->body_md);

        $this->mcpTool('update_doc_page', [...$arguments, 'confirmation' => $this->confirmationCode($pending)]);

        $this->assertSame('New guide', $page->refresh()->body_md);
    }

    // -----------------------------------------------------------------
    // The limits around every write
    // -----------------------------------------------------------------

    public function test_a_board_set_to_observer_takes_no_changes(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->boardAiMode($board, AiCapabilityMode::Observer);

        $result = $this->mcpAs($team)->mcpTool('create_ticket', ['board' => $board->slug, 'title' => 'Nope']);

        $this->assertTrue($result['isError'] ?? false);
        $this->assertStringContainsString(AiCapabilityMode::Observer->label(), $this->mcpText($result));
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_an_archived_board_takes_no_changes(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Frozen']);
        $board->forceFill(['archived_at' => now()])->save();

        $this->mcpAs($team);

        $this->assertTrue($this->mcpTool('create_ticket', ['board' => $board->slug, 'title' => 'Nope'])['isError'] ?? false);

        $update = $this->mcpTool('update_ticket', ['ticket' => $ticket->key(), 'title' => 'Thawed']);
        $this->assertTrue($update['isError'] ?? false);
        $this->assertStringContainsString('archived', $this->mcpText($update));
        $this->assertSame('Frozen', $ticket->refresh()->title);
    }

    public function test_a_ticket_on_somebody_elses_board_reads_exactly_like_one_that_does_not_exist(): void
    {
        $team = $this->teamMember();
        $stranger = $this->teamMember();
        $this->boardWithColumns([$team]);
        $theirs = $this->boardWithColumns([$stranger], ['ticket_prefix' => 'SEC']);
        $secret = $this->ticketOn($theirs, $stranger);

        $this->mcpAs($team);

        $hidden = $this->mcpText($this->mcpTool('update_ticket', ['ticket' => $secret->key(), 'title' => 'x']));
        $missing = $this->mcpText($this->mcpTool('update_ticket', ['ticket' => 'SEC-999', 'title' => 'x']));

        $this->assertSame($missing, $hidden);
    }

    public function test_switching_writes_off_takes_every_write_tool_away(): void
    {
        config(['mcp.server.writes_enabled' => false]);

        $team = $this->teamMember();
        $this->boardWithColumns([$team]);

        $this->mcpAs($team);

        $names = $this->mcpToolNames();

        $this->assertContains('get_ticket', $names);
        $this->assertNotContains('create_ticket', $names);
        $this->assertNotContains('delete_ticket', $names);

        $instructions = (string) $this->mcp('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'test', 'version' => '1'],
        ])->json('result.instructions');

        $this->assertStringNotContainsString('MAKING CHANGES', $instructions);
    }

    public function test_changes_are_rate_limited_per_person(): void
    {
        config(['mcp.server.write_rate_limit' => 2]);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->mcpAs($team);

        $this->mcpTool('create_ticket', ['board' => $board->slug, 'title' => 'One']);
        $this->mcpTool('create_ticket', ['board' => $board->slug, 'title' => 'Two']);
        $third = $this->mcpTool('create_ticket', ['board' => $board->slug, 'title' => 'Three']);

        $this->assertTrue($third['isError'] ?? false);
        $this->assertStringContainsString('changes in a minute', $this->mcpText($third));
        $this->assertSame(2, Ticket::query()->count());
    }

    // -----------------------------------------------------------------

    /**
     * @param  array{content: list<array<string, mixed>>}  $result
     */
    private function confirmationCode(array $result): string
    {
        $this->assertSame(1, preg_match('/confirmation: "([A-Z0-9]{6})"/', $this->mcpText($result), $matches), 'No confirmation code in: '.$this->mcpText($result));

        return $matches[1];
    }
}
