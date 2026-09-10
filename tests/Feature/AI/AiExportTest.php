<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Enums\AiChatRole;
use App\Models\AiChatMessage;
use App\Models\Board;
use App\Models\User;
use App\Services\AI\AiContextScope;
use App\Services\AI\AiSessionManager;
use App\Services\AI\WorkspaceChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exporting a table or a chart out of an answer.
 *
 * The property worth pinning is that the file is the *rendered* data. Nothing
 * is posted from the browser and nothing is cached: the controller re-reads the
 * stored answer and parses it with the same parser the screen used, so the CSV
 * and the table on screen cannot disagree.
 */
class AiExportTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = <<<'ANSWER'
    Throughput by month.

    ```nexora-table
    {"title": "Tickets by month", "columns": ["Month", "Tickets"], "rows": [["Jan", 120], ["Feb", 143]]}
    ```
    ANSWER;

    private const CHART = <<<'ANSWER'
    Revenue rose.

    ```nexora-chart
    {"type": "bar", "title": "Revenue by quarter", "labels": ["Q1", "Q2"],
     "datasets": [{"label": "Revenue", "data": [100, 118]}]}
    ```
    ANSWER;

    // -----------------------------------------------------------------
    // Tables
    // -----------------------------------------------------------------

    public function test_a_table_exports_as_the_csv_it_shows(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $message = $this->answer($board, $team, self::TABLE);

        $response = $this->actingAs($team)
            ->get(route('ai.blocks.export', ['message' => $message->getKey(), 'block' => 1]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        // The header row and both data rows, exactly as rendered.
        $this->assertStringContainsString('Month,Tickets', $csv);
        $this->assertStringContainsString('Jan,120', $csv);
        $this->assertStringContainsString('Feb,143', $csv);

        // Through the product's existing download path, BOM and all, so it
        // opens correctly in Excel like every other export in Nexora.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }

    public function test_the_filename_comes_from_the_blocks_own_title(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $message = $this->answer($board, $team, self::TABLE);

        $this->actingAs($team)
            ->get(route('ai.blocks.export', ['message' => $message->getKey(), 'block' => 1]))
            ->assertDownload('tickets-by-month.csv');
    }

    /**
     * A title is model-authored text, and a filename is a header value.
     *
     * Slugged before it goes anywhere near Content-Disposition: a header
     * assembled from untrusted text is a response-splitting bug waiting to
     * happen.
     */
    public function test_a_hostile_title_cannot_reach_the_download_header(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $message = $this->answer($board, $team, <<<'ANSWER'
        ```nexora-table
        {"title": "a\"; DROP TABLE\r\nX-Injected: yes", "columns": ["A"], "rows": [["1"]]}
        ```
        ANSWER);

        $response = $this->actingAs($team)
            ->get(route('ai.blocks.export', ['message' => $message->getKey(), 'block' => 0]))
            ->assertOk();

        $disposition = (string) $response->headers->get('Content-Disposition');

        $this->assertStringNotContainsString('DROP TABLE', $disposition);
        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString('X-Injected', $disposition);
        $this->assertNull($response->headers->get('X-Injected'));
    }

    // -----------------------------------------------------------------
    // Charts
    // -----------------------------------------------------------------

    /**
     * A chart exports its numbers.
     *
     * The picture is rasterised in the browser from the SVG already on the
     * page; what the server produces is the data the picture was drawn from,
     * which is what somebody forwarding a figure to a client actually needs.
     */
    public function test_a_chart_exports_the_data_behind_the_picture(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $message = $this->answer($board, $team, self::CHART);

        $csv = $this->actingAs($team)
            ->get(route('ai.blocks.export', ['message' => $message->getKey(), 'block' => 1]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Label,Revenue', $csv);
        $this->assertStringContainsString('Q1,100', $csv);
        $this->assertStringContainsString('Q2,118', $csv);
    }

    // -----------------------------------------------------------------
    // What cannot be exported
    // -----------------------------------------------------------------

    public function test_a_prose_block_is_not_exportable(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $message = $this->answer($board, $team, self::TABLE);

        // Block 0 is the sentence above the table.
        $this->actingAs($team)
            ->get(route('ai.blocks.export', ['message' => $message->getKey(), 'block' => 0]))
            ->assertNotFound();
    }

    public function test_a_block_index_that_does_not_exist_is_not_found(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $message = $this->answer($board, $team, self::TABLE);

        $this->actingAs($team)
            ->get(route('ai.blocks.export', ['message' => $message->getKey(), 'block' => 99]))
            ->assertNotFound();
    }

    /**
     * A person's own question is not an assistant artefact.
     *
     * Otherwise a pipe table somebody typed into the composer would come back
     * as an "exportable" table of the assistant's, which it is not.
     */
    public function test_a_users_own_turn_cannot_be_exported(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $session = app(AiSessionManager::class)->start(AiContextScope::board($board), $team);

        $question = app(WorkspaceChatService::class)->store(
            $session,
            AiContextScope::board($board),
            $team,
            AiChatRole::User,
            "| A | B |\n|---|---|\n| 1 | 2 |",
        );

        $this->actingAs($team)
            ->get(route('ai.blocks.export', ['message' => $question->getKey(), 'block' => 0]))
            ->assertNotFound();
    }

    public function test_a_malformed_url_is_rejected_by_the_router(): void
    {
        $team = $this->teamMember();

        // Both parameters are constrained to digits, so this never reaches the
        // controller and is never cast to zero inside it.
        $this->actingAs($team)
            ->get('/ai/messages/abc/blocks/xyz/export.csv')
            ->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Authorization
    // -----------------------------------------------------------------

    public function test_another_persons_answer_cannot_be_exported(): void
    {
        $owner = $this->teamMember();
        $other = $this->teamMember();
        $board = $this->boardWithColumns([$owner, $other]);

        $message = $this->answer($board, $owner, self::TABLE);

        // Both are on the board. The conversation is still the owner's alone —
        // the rule every AI surface in this product applies.
        $this->actingAs($other)
            ->get(route('ai.blocks.export', ['message' => $message->getKey(), 'block' => 1]))
            ->assertNotFound();
    }

    public function test_an_administrator_cannot_export_somebody_elses_answer(): void
    {
        $owner = $this->teamMember();
        $board = $this->boardWithColumns([$owner]);

        $message = $this->answer($board, $owner, self::TABLE);

        // An administrator has no reading right over a colleague's
        // conversation, so they have none over the tables in it.
        $this->actingAs($this->admin())
            ->get(route('ai.blocks.export', ['message' => $message->getKey(), 'block' => 1]))
            ->assertNotFound();
    }

    public function test_a_customer_cannot_reach_the_export_route_at_all(): void
    {
        $owner = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$owner, $customer]);

        $message = $this->answer($board, $owner, self::TABLE);

        // The route group's role gate answers first, before anything is loaded.
        $this->actingAs($customer)
            ->get(route('ai.blocks.export', ['message' => $message->getKey(), 'block' => 1]))
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $message = $this->answer($board, $team, self::TABLE);

        $this->get(route('ai.blocks.export', ['message' => $message->getKey(), 'block' => 1]))
            ->assertRedirect(route('login'));
    }

    // -----------------------------------------------------------------

    /**
     * Store an assistant turn the way the service does.
     */
    private function answer(Board $board, User $user, string $content): AiChatMessage
    {
        $session = app(AiSessionManager::class)->start(AiContextScope::board($board), $user);

        return app(WorkspaceChatService::class)->store(
            $session,
            AiContextScope::board($board),
            $user,
            AiChatRole::Assistant,
            $content,
        );
    }
}
