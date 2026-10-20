<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Enums\AiCapabilityMode;
use App\Enums\AiToolChannel;
use App\Enums\TicketPriority;
use App\Mcp\McpPresentation;
use App\Models\BoardColumn;
use App\Services\AI\AiContextScope;
use App\Services\AI\Tools\AiToolContext;
use App\Services\AI\Tools\AiToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithMcp;
use Tests\TestCase;

/**
 * How Nexora's answers look in Claude and ChatGPT: the display rules in the
 * server instructions, the badges and links in tool output — and the promise
 * that none of it leaks into the in-app assistant, which renders its own way.
 */
class McpPresentationTest extends TestCase
{
    use InteractsWithMcp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        McpPresentation::flush();
    }

    public function test_the_instructions_carry_the_display_rules(): void
    {
        config(['workspace.board_defaults.timezone' => 'Europe/Stockholm']);

        $team = $this->teamMember(['name' => 'Vikas']);
        $this->boardWithColumns([$team]);

        $instructions = $this->initialize($team);

        foreach ([
            'Do not create artifacts',
            'a table — Ticket (the linked key) | Title | Status | Assigned to | Priority | Due',
            'Status 🟢 done · 🟡 in progress · 🟣 in review · 🔴 blocked · ⚪ not started',
            'Priority 🔥 Critical',
            'Status and assignee are separate facts',
            'Never invent statuses',
            '"Who is working on what"',
            'Every change is recorded in Nexora\'s history as Vikas via this app',
            'confirmation code',
            '🔒 internal',
            'Europe/Stockholm',
        ] as $rule) {
            $this->assertStringContainsString($rule, $instructions);
        }
    }

    public function test_a_list_of_tickets_comes_with_links_badges_and_a_display_hint(): void
    {
        $team = $this->teamMember();
        $alex = $this->teamMember(['name' => 'Alex Round']);
        $board = $this->boardWithColumns([$team, $alex]);

        $this->ticketOn($board, $team, [
            'title' => 'Fix the | pipe',
            'assignee_id' => $alex->id,
            'priority' => TicketPriority::Critical->value,
            'board_column_id' => $this->columnNamed($board, 'In Progress')->id,
        ]);
        $this->ticketOn($board, $team, ['title' => 'Nobody has this one']);

        $text = $this->mcpText($this->mcpAs($team)->mcpTool('search_tickets', ['board' => $board->slug]));

        $this->assertMatchesRegularExpression('/\['.$board->ticket_prefix.'-\d+\]\(http[^)]+\/tickets\/\d+\)/', $text);
        $this->assertStringContainsString('🟡 In Progress', $text);
        $this->assertStringContainsString('👤 Alex Round', $text);
        $this->assertStringContainsString('🔥 Critical', $text);
        $this->assertStringContainsString('👤 Unassigned', $text);
        $this->assertStringContainsString('⚪ Backlog', $text);
        // A title can't break the table the model builds from it.
        $this->assertStringContainsString('Fix the \| pipe', $text);
        $this->assertStringContainsString('DISPLAY:', $text);
    }

    public function test_one_ticket_comes_with_its_link_an_exact_last_updated_and_a_numbered_checklist(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Detail']);
        $ticket->subtasks()->create(['title' => 'First step', 'completed' => true, 'position' => 0]);
        $ticket->subtasks()->create(['title' => 'Second step', 'completed' => false, 'position' => 1]);

        $text = $this->mcpText($this->mcpAs($team)->mcpTool('get_ticket', ['ticket' => $ticket->key()]));

        $this->assertStringContainsString('Link: '.McpPresentation::ticketUrl($ticket), $text);
        $this->assertStringContainsString('Status: ⚪ Backlog', $text);
        $this->assertStringContainsString('Assigned to: 👤 Unassigned', $text);
        $this->assertStringContainsString('Last updated: '.$ticket->refresh()->updated_at->toIso8601String(), $text);
        $this->assertStringContainsString("1. [x] First step\n2. [ ] Second step", $text);
        $this->assertStringContainsString('DISPLAY: show "🎫', $text);
    }

    public function test_the_in_app_assistant_sees_exactly_what_it_saw_before(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Same as ever']);

        $context = new AiToolContext(
            user: $team,
            scope: AiContextScope::board($board),
            session: null,
            mode: AiCapabilityMode::Operator,
            staff: true,
            channel: AiToolChannel::Assistant,
        );

        $registry = app(AiToolRegistry::class);

        $list = $registry->invoke('search_tickets', ['board' => $board->slug], $context)->text;
        $detail = $registry->invoke('get_ticket', ['ticket' => $ticket->key()], $context)->text;

        $this->assertStringContainsString('- '.$ticket->key().' [Backlog] Same as ever', $list);
        $this->assertStringContainsString('Status column: Backlog', $detail);

        foreach ([$list, $detail] as $text) {
            $this->assertStringNotContainsString('DISPLAY:', $text);
            $this->assertStringNotContainsString('http', $text);
            $this->assertStringNotContainsString('👤', $text);
        }
    }

    public function test_the_status_colour_comes_from_the_column_and_the_name_is_always_kept(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->assertSame('🟢 Done', McpPresentation::status($this->columnNamed($board, 'Done')));
        $this->assertSame('🟣 Review', McpPresentation::status($this->columnNamed($board, 'Review')));
        $this->assertSame('⚪ To Do', McpPresentation::status($this->columnNamed($board, 'To Do')));
        $this->assertSame('🟡 In Progress', McpPresentation::status($this->columnNamed($board, 'In Progress')));

        $blocked = BoardColumn::factory()->create(['board_id' => $board->id, 'name' => 'Blocked', 'position' => 9]);
        $legal = BoardColumn::factory()->create(['board_id' => $board->id, 'name' => 'With legal', 'position' => 10]);

        $this->assertSame('🔴 Blocked', McpPresentation::status($blocked));
        $this->assertSame('🟡 With legal', McpPresentation::status($legal));

        // The first column of a board is "not started", whatever it is called.
        $other = $this->boardWithMembers([$team]);
        $intake = BoardColumn::factory()->create(['board_id' => $other->id, 'name' => 'Intake', 'position' => 0]);
        BoardColumn::factory()->create(['board_id' => $other->id, 'name' => 'Doing', 'position' => 1]);

        $this->assertSame('⚪ Intake', McpPresentation::status($intake));
    }

    public function test_a_customer_is_never_shown_the_internal_legend_or_staff_rules(): void
    {
        $customer = $this->customer();
        $this->boardWithColumns([$customer]);

        $instructions = $this->initialize($customer);

        $this->assertStringContainsString('HOW TO SHOW RESULTS', $instructions);
        $this->assertStringContainsString('You can raise tickets', $instructions);
        $this->assertStringNotContainsString('🔒', $instructions);
        $this->assertStringNotContainsString('delete', mb_strtolower($instructions));
    }

    private function initialize($user): string
    {
        return (string) $this->mcpAs($user)->mcp('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'test', 'version' => '1'],
        ])->assertOk()->json('result.instructions');
    }
}
