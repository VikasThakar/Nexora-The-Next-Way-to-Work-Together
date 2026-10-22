<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Enums\AiCapabilityMode;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithMcp;
use Tests\TestCase;

/**
 * The remote MCP server on a board split into tabs — what Claude, ChatGPT and
 * Gritch's admin AI see and can do.
 *
 * The `tab` argument is a contract other systems build on: a tab's name,
 * matched exactly (ignoring case) before partially, always optional — without
 * it a new ticket goes to the board's first tab and a status moves a ticket
 * within its own tab, exactly as before tabs existed.
 */
class McpBoardTabsTest extends TestCase
{
    use InteractsWithMcp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aiMode(AiCapabilityMode::Operator);
    }

    public function test_get_board_lists_each_tab_with_its_columns_and_counts(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['slug' => 'gritch']);
        $website = $this->tabOn($board, 'Website');
        $this->ticketOn($board, $team, ['board_tab_id' => $website->id]);

        $text = $this->mcpText($this->mcpAs($team)->mcpTool('get_board', ['board' => 'gritch']));

        $this->assertStringContainsString('TABS AND COLUMNS (2 tabs in board order', $text);
        $this->assertStringContainsString('TAB "Main" — 0 tickets', $text);
        $this->assertStringContainsString('TAB "Website" — 1 ticket', $text);
        $this->assertStringContainsString('Backlog: 1 ticket', $text);
        $this->assertStringContainsString('pass tab with the tab\'s name', $text);
    }

    public function test_get_board_on_a_board_with_one_tab_reads_as_it_always_has(): void
    {
        $team = $this->teamMember();
        $this->boardWithColumns([$team], ['slug' => 'plain']);

        $text = $this->mcpText($this->mcpAs($team)->mcpTool('get_board', ['board' => 'plain']));

        $this->assertStringContainsString('COLUMNS (in board order)', $text);
        $this->assertStringNotContainsString('TABS AND COLUMNS', $text);
        $this->assertStringNotContainsString('TAB "', $text);
    }

    public function test_create_ticket_files_into_the_named_tab_and_says_where(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['name' => 'Gritch', 'slug' => 'gritch']);
        $website = $this->tabOn($board, 'Website');

        $result = $this->mcpAs($team)->mcpTool('create_ticket', [
            'board' => 'gritch',
            'title' => 'New hero image',
            'tab' => 'website',
            'status' => 'Review',
        ]);

        $this->assertFalse($result['isError']);

        $ticket = Ticket::query()->where('title', 'New hero image')->sole();
        $this->assertSame($this->columnNamed($board, 'Review', $website)->id, $ticket->board_column_id);

        $text = $this->mcpText($result);
        $this->assertStringContainsString('**Tab:** Website', $text);
        $this->assertStringContainsString('Review', $text);
    }

    public function test_create_ticket_without_a_tab_goes_to_the_first_tab(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['slug' => 'gritch']);
        $this->tabOn($board, 'Website');

        $result = $this->mcpAs($team)->mcpTool('create_ticket', ['board' => 'gritch', 'title' => 'Wherever']);

        $this->assertStringContainsString('**Tab:** Main', $this->mcpText($result));
        $this->assertTrue(Ticket::query()->where('title', 'Wherever')->sole()->column->tab->is($this->defaultTab($board)));
    }

    public function test_create_tickets_takes_a_tab_per_ticket(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['slug' => 'gritch']);
        $website = $this->tabOn($board, 'Website');

        $result = $this->mcpAs($team)->mcpTool('create_tickets', [
            'board' => 'gritch',
            'tickets' => [
                ['title' => 'One for the site', 'tab' => 'Website'],
                ['title' => 'One for main'],
            ],
        ]);

        $this->assertFalse($result['isError']);
        $this->assertTrue(Ticket::query()->where('title', 'One for the site')->sole()->column->tab->is($website));
        $this->assertTrue(Ticket::query()->where('title', 'One for main')->sole()->column->tab->is($this->defaultTab($board)));
        $this->assertStringContainsString('Website › Backlog', $this->mcpText($result));
    }

    public function test_update_ticket_moves_a_ticket_to_another_tab_and_reports_the_tab_change(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['slug' => 'gritch']);
        $website = $this->tabOn($board, 'Website');
        $ticket = $this->ticketOn($board, $team, ['board_column_id' => $this->columnNamed($board, 'To Do', $this->defaultTab($board))->id]);

        // Same column name in both tabs: only the tab changes, and that is
        // still a change worth reporting.
        $result = $this->mcpAs($team)->mcpTool('update_ticket', [
            'ticket' => $ticket->key(),
            'tab' => 'Website',
        ]);

        $this->assertFalse($result['isError']);
        $this->assertSame($this->columnNamed($board, 'To Do', $website)->id, $ticket->fresh()->board_column_id);
        $this->assertStringContainsString('**Tab:** Main → Website', $this->mcpText($result));
    }

    public function test_status_alone_moves_within_the_tickets_tab_and_an_unknown_tab_changes_nothing(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['slug' => 'gritch']);
        $website = $this->tabOn($board, 'Website');
        $ticket = $this->ticketOn($board, $team, ['board_tab_id' => $website->id]);

        $this->mcpAs($team)->mcpTool('update_ticket', ['ticket' => $ticket->key(), 'status' => 'Done']);
        $this->assertSame($this->columnNamed($board, 'Done', $website)->id, $ticket->fresh()->board_column_id);

        $result = $this->mcpTool('update_ticket', ['ticket' => $ticket->key(), 'tab' => 'Mobile']);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('no tab called', $this->mcpText($result));
        $this->assertStringContainsString('Main and Website', $this->mcpText($result));
        $this->assertSame($this->columnNamed($board, 'Done', $website)->id, $ticket->fresh()->board_column_id);
    }

    public function test_search_filters_by_tab_and_lines_name_the_tab(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['slug' => 'gritch']);
        $website = $this->tabOn($board, 'Website');
        $this->ticketOn($board, $team, ['title' => 'Website work', 'board_tab_id' => $website->id]);
        $this->ticketOn($board, $team, ['title' => 'Main work']);

        $text = $this->mcpText($this->mcpAs($team)->mcpTool('search_tickets', ['board' => 'gritch', 'tab' => 'web']));

        $this->assertStringContainsString('Website work', $text);
        $this->assertStringNotContainsString('Main work', $text);
        $this->assertStringContainsString('Website › Backlog', $text);
    }

    public function test_get_ticket_names_the_tab_and_the_history_names_both_ends_of_a_tab_move(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['slug' => 'gritch']);
        $website = $this->tabOn($board, 'Website');
        $ticket = $this->ticketOn($board, $team);

        $this->mcpAs($team)->mcpTool('update_ticket', ['ticket' => $ticket->key(), 'tab' => 'Website']);

        $text = $this->mcpText($this->mcpTool('get_ticket', ['ticket' => $ticket->key()]));

        $this->assertStringContainsString('Tab: Website', $text);
        $this->assertStringContainsString('from Main › Backlog to Website › Backlog', $text);
        $this->assertSame('website', $website->slug);
    }

    public function test_each_tab_has_its_own_not_started_column(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['slug' => 'gritch']);
        $website = $this->tabOn($board, 'Website');
        $this->columnNamed($board, 'Backlog', $website)->update(['name' => 'Ideas pile']);
        $this->ticketOn($board, $team, ['title' => 'Unstarted', 'board_tab_id' => $website->id]);

        $text = $this->mcpText($this->mcpAs($team)->mcpTool('search_tickets', ['board' => 'gritch']));

        // White, though the name says nothing: it is the first column of its tab.
        $this->assertStringContainsString('⚪ Website › Ideas pile', $text);
    }

    public function test_the_instructions_explain_tabs(): void
    {
        $team = $this->teamMember();
        $this->boardWithColumns([$team]);

        $instructions = (string) $this->mcpAs($team)->mcp('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'test', 'version' => '1'],
        ])->json('result.instructions');

        $this->assertStringContainsString('Some boards are split into tabs', $instructions);
    }
}
