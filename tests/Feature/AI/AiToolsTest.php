<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Enums\AiCapabilityMode;
use App\Livewire\Ai\Assistant;
use App\Models\AiChatMessage;
use App\Models\AiSession;
use App\Models\Board;
use App\Models\User;
use App\Services\AI\AiContextScope;
use App\Services\AI\AiSessionManager;
use App\Services\AI\Data\AiToolCall;
use App\Services\AI\Tools\AiToolContext;
use App\Services\AI\Tools\AiToolRegistry;
use App\Services\BoardAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The retrieval layer: what the assistant can look up, and for whom.
 *
 * These tests exercise the tools through the registry — which is the only way
 * anything in the application runs one — so every case here also exercises the
 * schema validation, the availability check and the audit write that the
 * registry performs around a tool.
 *
 * The one property every test in this file is really about: a tool argument
 * names a SUBJECT, and the viewer comes from the context. There is no argument
 * a model can send that widens what a person may read, which is why the same
 * lookup returns different material for a customer and for a member of staff
 * without either tool containing a branch about it.
 */
class AiToolsTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

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

    private function registry(): AiToolRegistry
    {
        return app(AiToolRegistry::class);
    }

    // -----------------------------------------------------------------
    // Which tools exist for whom
    // -----------------------------------------------------------------

    public function test_a_team_member_is_offered_the_whole_set(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $names = array_keys($this->registry()->availableFor($this->contextFor($team, $board)));

        foreach ([
            'get_ticket',
            'search_tickets',
            'get_board',
            'get_activity',
            'search_documentation',
            'get_documentation_page',
            'get_github_repository',
            'get_code_activity',
        ] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    /**
     * A customer is offered fewer tools, and the ones missing are missing
     * entirely rather than refusing when called.
     *
     * That distinction matters: a tool that is not offered is a capability the
     * model never learns exists, so there is nothing for a crafted request to
     * aim at and no refusal message to probe.
     */
    public function test_a_customer_is_not_offered_the_staff_only_tools(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        $names = array_keys($this->registry()->availableFor($this->contextFor($customer, $board)));

        $this->assertContains('get_ticket', $names);
        $this->assertContains('search_tickets', $names);
        $this->assertContains('search_documentation', $names);

        $this->assertNotContains('get_activity', $names);
        $this->assertNotContains('get_github_repository', $names);
        $this->assertNotContains('get_code_activity', $names);
    }

    public function test_calling_a_tool_that_is_not_offered_is_refused_without_saying_why(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        $outcome = $this->registry()->invoke(
            'get_code_activity',
            ['board' => $board->slug],
            $this->contextFor($customer, $board),
        );

        $this->assertFalse($outcome->success);

        // The same answer a made-up tool name gets, so a customer cannot tell
        // "no such tool" from "not for you".
        $invented = $this->registry()->invoke('get_secrets', [], $this->contextFor($customer, $board));

        $this->assertSame($outcome->outcome, $invented->outcome);
    }

    public function test_tools_can_be_switched_off_for_a_deployment(): void
    {
        config()->set('ai.tools.enabled', false);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->assertSame([], $this->registry()->availableFor($this->contextFor($team, $board)));
    }

    // -----------------------------------------------------------------
    // get_ticket
    // -----------------------------------------------------------------

    public function test_get_ticket_returns_the_description_and_the_internal_note(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team, [
            'title' => 'Export times out',
            'description_md' => 'The CSV export dies after ninety seconds.',
        ]);

        $this->commentOn($ticket, $team, 'Blocked on the VAT migration.');

        $outcome = $this->registry()->invoke(
            'get_ticket',
            ['ticket' => $ticket->key()],
            $this->contextFor($team, $board),
        );

        $this->assertTrue($outcome->success);
        $this->assertSame($ticket->key(), $outcome->target);

        $this->assertStringContainsString('Export times out', $outcome->text);
        $this->assertStringContainsString('dies after ninety seconds', $outcome->text);
        $this->assertStringContainsString('Blocked on the VAT migration', $outcome->text);
    }

    /**
     * The same tool, the same argument, a customer asking.
     *
     * The ticket is theirs so they get it; the internal note is not, so they do
     * not. Neither outcome is a branch inside the tool — CommentReader returns
     * a different set of rows for a different viewer, which is the whole design.
     */
    public function test_get_ticket_hides_internal_notes_from_a_customer(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $customer, ['title' => 'My export is broken']);

        $this->commentOn($ticket, $team, 'Internal: the customer is on the old plan.');

        $outcome = $this->registry()->invoke(
            'get_ticket',
            ['ticket' => $ticket->key()],
            $this->contextFor($customer, $board),
        );

        $this->assertTrue($outcome->success);
        $this->assertStringContainsString('My export is broken', $outcome->text);
        $this->assertStringNotContainsString('on the old plan', $outcome->text);
    }

    public function test_an_internal_ticket_is_not_found_rather_than_forbidden_for_a_customer(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $internal = $this->ticketOn($board, $team, ['title' => 'Rewrite the billing engine']);

        $outcome = $this->registry()->invoke(
            'get_ticket',
            ['ticket' => $internal->key()],
            $this->contextFor($customer, $board),
        );

        $this->assertFalse($outcome->success);
        $this->assertStringNotContainsString('Rewrite the billing engine', $outcome->text);

        // Indistinguishable from a number nobody has used.
        $missing = $this->registry()->invoke(
            'get_ticket',
            ['ticket' => $board->ticket_prefix.'-9999'],
            $this->contextFor($customer, $board),
        );

        $this->assertSame($outcome->outcome, $missing->outcome);
    }

    public function test_a_ticket_on_a_board_the_person_is_not_on_is_not_found(): void
    {
        $outsider = $this->teamMember();
        $owner = $this->teamMember();
        $board = $this->boardWithColumns([$owner]);

        $ticket = $this->ticketOn($board, $owner, ['title' => 'Another clients roadmap']);

        $outcome = $this->registry()->invoke(
            'get_ticket',
            ['ticket' => $ticket->key()],
            $this->contextFor($outsider),
        );

        $this->assertFalse($outcome->success);
        $this->assertStringNotContainsString('roadmap', $outcome->text);
    }

    /**
     * A prefixed key resolves without a board, which is what makes the
     * workspace scope useful: somebody on the dashboard can ask about NL-123.
     */
    public function test_a_prefixed_key_resolves_from_the_workspace_scope(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['ticket_prefix' => 'NL']);

        $ticket = $this->ticketOn($board, $team, ['title' => 'Blocked on DNS']);

        $outcome = $this->registry()->invoke(
            'get_ticket',
            ['ticket' => 'NL-'.$ticket->number],
            // No board in the context at all.
            $this->contextFor($team),
        );

        $this->assertTrue($outcome->success);
        $this->assertStringContainsString('Blocked on DNS', $outcome->text);
    }

    // -----------------------------------------------------------------
    // search_tickets
    // -----------------------------------------------------------------

    /**
     * The question the context block cannot answer.
     *
     * "Which tickets are overdue" needs a query, not a roll-up of the sixty
     * most recently updated tickets — which is the whole reason this layer
     * exists.
     */
    public function test_search_tickets_finds_overdue_work_across_boards(): void
    {
        $team = $this->teamMember();
        $one = $this->boardWithColumns([$team], ['slug' => 'one', 'name' => 'One', 'ticket_prefix' => 'ONE']);
        $two = $this->boardWithColumns([$team], ['slug' => 'two', 'name' => 'Two', 'ticket_prefix' => 'TWO']);

        $lateOnOne = $this->ticketOn($one, $team, [
            'title' => 'Late invoice import',
            'due_date' => now()->subWeek()->toDateString(),
        ]);

        $lateOnTwo = $this->ticketOn($two, $team, [
            'title' => 'Late migration',
            'due_date' => now()->subDay()->toDateString(),
        ]);

        $this->ticketOn($one, $team, [
            'title' => 'Due next month',
            'due_date' => now()->addMonth()->toDateString(),
        ]);

        $this->ticketOn($one, $team, ['title' => 'No due date at all']);

        $outcome = $this->registry()->invoke(
            'search_tickets',
            ['overdue' => true],
            $this->contextFor($team),
        );

        $this->assertTrue($outcome->success);
        $this->assertStringContainsString($lateOnOne->key(), $outcome->text);
        $this->assertStringContainsString($lateOnTwo->key(), $outcome->text);
        $this->assertStringNotContainsString('Due next month', $outcome->text);
        $this->assertStringNotContainsString('No due date at all', $outcome->text);
    }

    public function test_a_ticket_in_a_done_column_is_not_overdue(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team, [
            'title' => 'Shipped last week',
            'due_date' => now()->subMonth()->toDateString(),
        ]);

        $done = $board->columns()->where('is_done', true)->first();

        $this->assertNotNull($done, 'The default columns should include a done column.');

        $ticket->board_column_id = $done->getKey();
        $ticket->save();

        $outcome = $this->registry()->invoke(
            'search_tickets',
            ['overdue' => true],
            $this->contextFor($team, $board),
        );

        // Read from the column's own done flag rather than from its name, so a
        // board that calls its last column "Shipped" behaves the same.
        $this->assertStringNotContainsString('Shipped last week', $outcome->text);
    }

    public function test_search_tickets_never_crosses_into_a_board_the_person_cannot_reach(): void
    {
        $team = $this->teamMember();
        $mine = $this->boardWithColumns([$team], ['slug' => 'mine', 'ticket_prefix' => 'MINE']);
        $theirs = $this->boardWithColumns([$this->teamMember()], ['slug' => 'theirs', 'ticket_prefix' => 'THRS']);

        $this->ticketOn($mine, $team, ['title' => 'My own work']);
        $this->ticketOn($theirs, $theirs->members()->first(), ['title' => 'Somebody elses secret plan']);

        // Asked with no board at all, which is the widest this tool goes.
        $outcome = $this->registry()->invoke('search_tickets', [], $this->contextFor($team));

        $this->assertStringContainsString('My own work', $outcome->text);
        $this->assertStringNotContainsString('secret plan', $outcome->text);

        // And naming the board explicitly does not help.
        $named = $this->registry()->invoke(
            'search_tickets',
            ['board' => 'theirs'],
            $this->contextFor($team),
        );

        $this->assertFalse($named->success);
        $this->assertStringNotContainsString('secret plan', $named->text);
    }

    public function test_search_tickets_reports_the_total_separately_from_what_it_lists(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        // Through the real action, so per-board numbering is allocated the
        // way it is in production — the factory does not allocate one.
        for ($index = 1; $index <= 6; $index++) {
            $this->ticketOn($board, $team, ['title' => 'Ticket number '.$index]);
        }

        $outcome = $this->registry()->invoke(
            'search_tickets',
            ['limit' => 2],
            $this->contextFor($team, $board),
        );

        // The model has to be able to tell "there are two" from "here are two
        // of six", or it will report a sample as a total.
        $this->assertStringContainsString('6 matches', $outcome->text);
        $this->assertStringContainsString('showing the 2', $outcome->text);
    }

    public function test_search_tickets_hides_internal_tickets_from_a_customer(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $this->ticketOn($board, $team, ['title' => 'Internal refactor of the queue']);
        $this->ticketOn($board, $customer, ['title' => 'My login is slow']);

        $outcome = $this->registry()->invoke(
            'search_tickets',
            [],
            $this->contextFor($customer, $board),
        );

        $this->assertStringContainsString('My login is slow', $outcome->text);
        $this->assertStringNotContainsString('Internal refactor', $outcome->text);
    }

    // -----------------------------------------------------------------
    // Documentation
    // -----------------------------------------------------------------

    public function test_documentation_search_and_read_work_together(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $page = $this->docPageOn($board, $team, [
            'title' => 'Deployment runbook',
            'body_md' => 'Run composer install, then php artisan migrate --force.',
        ]);

        $found = $this->registry()->invoke(
            'search_documentation',
            ['query' => 'runbook'],
            $this->contextFor($team, $board),
        );

        $this->assertTrue($found->success);
        $this->assertStringContainsString('Deployment runbook', $found->text);
        $this->assertStringContainsString($page->slug, $found->text);

        $read = $this->registry()->invoke(
            'get_documentation_page',
            ['slug' => $page->slug],
            $this->contextFor($team, $board),
        );

        $this->assertTrue($read->success);
        $this->assertStringContainsString('php artisan migrate --force', $read->text);
    }

    public function test_internal_documentation_is_invisible_to_a_customer(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $internal = $this->docPageOn($board, $team, [
            'title' => 'Incident runbook',
            'body_md' => 'Escalate to the on-call engineer.',
        ]);

        $published = $this->publishedPageOn($board, $team, [
            'title' => 'Getting started',
            'body_md' => 'Log in and open your board.',
        ]);

        $found = $this->registry()->invoke(
            'search_documentation',
            ['query' => 'runbook'],
            $this->contextFor($customer, $board),
        );

        $this->assertStringNotContainsString('Incident runbook', $found->text);
        $this->assertStringNotContainsString('on-call engineer', $found->text);

        // Named directly, it is still not found.
        $read = $this->registry()->invoke(
            'get_documentation_page',
            ['slug' => $internal->slug],
            $this->contextFor($customer, $board),
        );

        $this->assertFalse($read->success);
        $this->assertStringNotContainsString('on-call engineer', $read->text);

        // What has been published to them does come back.
        $allowed = $this->registry()->invoke(
            'get_documentation_page',
            ['slug' => $published->slug],
            $this->contextFor($customer, $board),
        );

        $this->assertTrue($allowed->success);
        $this->assertStringContainsString('open your board', $allowed->text);
    }

    // -----------------------------------------------------------------
    // The board tool
    // -----------------------------------------------------------------

    public function test_get_board_lists_the_boards_when_none_is_named(): void
    {
        $team = $this->teamMember();
        $this->boardWithColumns([$team], ['name' => 'Aqueduct Platform', 'slug' => 'aqueduct']);

        $outcome = $this->registry()->invoke('get_board', [], $this->contextFor($team));

        $this->assertTrue($outcome->success);
        $this->assertStringContainsString('Aqueduct Platform', $outcome->text);
        $this->assertStringContainsString('aqueduct', $outcome->text);
    }

    public function test_get_board_counts_only_what_the_viewer_can_see(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $this->ticketOn($board, $team, ['title' => 'Internal one']);
        $this->ticketOn($board, $team, ['title' => 'Internal two']);
        $this->ticketOn($board, $customer, ['title' => 'Theirs']);

        $staffView = $this->registry()->invoke(
            'get_board',
            ['board' => $board->slug],
            $this->contextFor($team, $board),
        );

        $customerView = $this->registry()->invoke(
            'get_board',
            ['board' => $board->slug],
            $this->contextFor($customer, $board),
        );

        $this->assertStringContainsString('Tickets visible to this person: 3', $staffView->text);

        // Counted through the same reader that lists them, so the count and the
        // list cannot disagree — the version of this that leaks is a real total
        // with the internal ones subtracted afterwards.
        $this->assertStringContainsString('Tickets visible to this person: 1', $customerView->text);

        // The membership list is staff-only.
        $this->assertStringContainsString('MEMBERS', $staffView->text);
        $this->assertStringNotContainsString('MEMBERS', $customerView->text);
    }

    // -----------------------------------------------------------------
    // The loop, end to end through the panel
    // -----------------------------------------------------------------

    /**
     * A question that needs a lookup gets one, and the material reaches the
     * model.
     *
     * Asserted on the tool RESULT the fake provider was handed, which is the
     * model's own view of the answer — the strongest available evidence that
     * the loop is wired up rather than merely present.
     */
    public function test_a_question_drives_a_lookup_and_the_result_reaches_the_model(): void
    {
        $provider = $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team, [
            'title' => 'Payments webhook retries for ever',
            'description_md' => 'The retry loop never gives up.',
        ]);

        $provider->willLookUp(
            'get_ticket',
            ['ticket' => $ticket->key()],
            'It retries indefinitely because the loop has no ceiling.',
        );

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'Why does '.$ticket->key().' keep retrying?')
            ->call('send')
            ->assertHasNoErrors();

        // Two provider calls: one asking for the lookup, one answering.
        $this->assertSame(2, $provider->calls);

        $this->assertStringContainsString('The retry loop never gives up', $provider->toolResultText());

        // And the answer that was stored is the second call's prose.
        $this->assertDatabaseHas('ai_chat_messages', [
            'content' => 'It retries indefinitely because the loop has no ceiling.',
        ]);
    }

    public function test_the_loop_is_bounded_and_still_produces_an_answer(): void
    {
        config()->set('ai.tools.max_rounds', 1);

        $provider = $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team, ['title' => 'Something']);

        /*
         * A model that never stops asking.
         *
         * The loop has to answer the outstanding calls rather than dropping
         * them — both vendors reject a turn whose tool calls went unanswered —
         * and then give the model one turn to answer from what it has.
         */
        $provider->script = [
            ['text' => '', 'toolCalls' => [new AiToolCall('get_ticket', ['ticket' => $ticket->key()], 'a')]],
            ['text' => '', 'toolCalls' => [new AiToolCall('get_ticket', ['ticket' => $ticket->key()], 'b')]],
            ['text' => 'Answering with what I have.', 'toolCalls' => []],
        ];

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'Tell me everything.')
            ->call('send')
            ->assertHasNoErrors();

        $this->assertSame(3, $provider->calls);

        $this->assertStringContainsString(
            'lookup budget for this question is used up',
            $provider->toolResultText(),
        );

        $this->assertDatabaseHas('ai_chat_messages', ['content' => 'Answering with what I have.']);
    }

    /**
     * The lookups are recorded on the turn so the answer can show its working.
     */
    public function test_the_turn_records_what_it_consulted(): void
    {
        $provider = $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Something to read']);

        $provider->willLookUp('get_ticket', ['ticket' => $ticket->key()], 'Read it.');

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'What is in '.$ticket->key().'?')
            ->call('send');

        $answer = AiChatMessage::query()
            ->where('content', 'Read it.')
            ->sole();

        $invocations = $answer->metadata['tools']['invocations'] ?? [];

        $this->assertCount(1, $invocations);
        $this->assertSame('get_ticket', $invocations[0]['tool']);
        $this->assertSame($ticket->key(), $invocations[0]['target']);
        $this->assertTrue($invocations[0]['success']);
    }

    /**
     * A session belongs to one person, and a tool context is built from a
     * resolved session — so there is no way to hand a tool somebody else's
     * conversation from the outside.
     */
    public function test_a_tool_context_cannot_be_built_from_another_persons_session(): void
    {
        $owner = $this->teamMember();
        $other = $this->teamMember();
        $board = $this->boardWithColumns([$owner, $other]);

        $session = app(AiSessionManager::class)->start(AiContextScope::board($board), $owner);

        $this->assertNull(
            app(AiSessionManager::class)->find(AiContextScope::board($board), $other, $session->uuid)
        );

        $this->assertSame(
            [],
            AiSession::query()->visibleTo($other)->pluck('id')->all(),
        );
    }
}
