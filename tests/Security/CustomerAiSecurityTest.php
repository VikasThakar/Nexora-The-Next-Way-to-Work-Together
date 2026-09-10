<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Enums\AiCapabilityMode;
use App\Enums\CommentStream;
use App\Enums\GithubLinkState;
use App\Enums\GithubLinkType;
use App\Livewire\Ai\Assistant;
use App\Models\AiChatMessage;
use App\Models\AiRun;
use App\Models\Board;
use App\Models\GithubLink;
use App\Models\User;
use App\Services\AI\AiCapabilityGuard;
use App\Services\AI\AiContextScope;
use App\Services\AI\AiSessionManager;
use App\Services\AI\AssistantContextBuilder;
use App\Services\AI\Tools\AiToolContext;
use App\Services\AI\Tools\AiToolRegistry;
use App\Services\BoardAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The customer AI boundary.
 *
 * Customers now have an assistant. It is strictly read-only, and it may only
 * see what has been shared with them — which is the single most consequential
 * change in this phase, so it gets its own suite.
 *
 * Every test here asserts against a layer BELOW the screen, because the screen
 * is not the boundary. The boundary is four things, and each has its own
 * section:
 *
 *   the context     assembled with the customer as the viewer, so the material
 *                   the model receives is the customer-visible subset;
 *   the tools       the staff-only lookups are not offered, so the model never
 *                   learns they exist;
 *   the writes      no `propose_*` tool in any mode, so no proposal can be
 *                   drafted and none can be confirmed;
 *   the transcripts one person's conversation, so a customer on a board cannot
 *                   read the delivery team's conversation about that board.
 *
 * The requirement said "enforce this on the backend". Nothing below drives a
 * browser.
 */
class CustomerAiSecurityTest extends TestCase
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
    // The context
    // -----------------------------------------------------------------

    /**
     * A board with something of everything, built from a customer's point of
     * view.
     *
     * Asserted on the context string itself rather than on an answer, because
     * the context is what actually crosses the boundary — anything absent here
     * cannot reach the model however the conversation goes.
     */
    public function test_the_context_a_customer_receives_contains_nothing_internal(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $internalTicket = $this->ticketOn($board, $team, [
            'title' => 'INTERNAL rewrite the pricing engine',
            'description_md' => 'The margin calculation is wrong and the client has not noticed.',
        ]);

        $this->commentOn($internalTicket, $team, 'INTERNAL NOTE do not share this.', CommentStream::Internal);

        $shared = $this->ticketOn($board, $customer, ['title' => 'SHARED my export fails']);
        $this->commentOn($shared, $team, 'We are looking into it.', CommentStream::Customer);

        $this->docPageOn($board, $team, [
            'title' => 'INTERNAL runbook',
            'body_md' => 'Escalate to the on-call engineer.',
        ]);

        $this->publishedPageOn($board, $team, [
            'title' => 'SHARED getting started',
            'body_md' => 'Open your board and click a card.',
        ]);

        $this->repositoryOn($board, ['repository_name' => 'aqueduct/secret-platform']);

        $context = app(AssistantContextBuilder::class)->build(
            AiContextScope::board($board),
            $customer,
        );

        // What they may see.
        $this->assertStringContainsString('SHARED my export fails', $context);

        // What they may not.
        $this->assertStringNotContainsString('INTERNAL rewrite', $context);
        $this->assertStringNotContainsString('margin calculation', $context);
        $this->assertStringNotContainsString('INTERNAL NOTE', $context);
        $this->assertStringNotContainsString('INTERNAL runbook', $context);
        $this->assertStringNotContainsString('on-call engineer', $context);
        $this->assertStringNotContainsString('secret-platform', $context);
    }

    public function test_the_workspace_roll_up_a_customer_receives_omits_other_boards(): void
    {
        $customer = $this->customer();
        $team = $this->teamMember();

        $theirs = $this->boardWithColumns([$customer, $team], ['name' => 'Their project', 'ticket_prefix' => 'THP']);
        $elsewhere = $this->boardWithColumns([$team], ['name' => 'Another client', 'ticket_prefix' => 'ANC']);

        $this->ticketOn($theirs, $customer, ['title' => 'Mine']);
        $this->ticketOn($elsewhere, $team, ['title' => 'Another clients roadmap']);

        $context = app(AssistantContextBuilder::class)->build(
            AiContextScope::workspace(),
            $customer,
        );

        $this->assertStringContainsString('Their project', $context);
        $this->assertStringNotContainsString('Another client', $context);
        $this->assertStringNotContainsString('roadmap', $context);
    }

    // -----------------------------------------------------------------
    // The tools
    // -----------------------------------------------------------------

    public function test_a_customer_cannot_reach_github_data_through_any_tool(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $repository = $this->repositoryOn($board, ['repository_name' => 'aqueduct/platform']);

        $ticket = $this->ticketOn($board, $customer, ['title' => 'My bug']);

        $link = new GithubLink;
        $link->board_id = $board->getKey();
        $link->ticket_id = $ticket->getKey();
        $link->board_repository_id = $repository->getKey();
        $link->repository = 'aqueduct/platform';
        $link->type = GithubLinkType::PullRequest;
        $link->external_id = '128';
        $link->reference = '128';
        $link->title = 'Disable VAT for EU resellers';
        $link->url = 'https://github.com/aqueduct/platform/pull/128';
        $link->state = GithubLinkState::Open;
        $link->save();

        $context = $this->contextFor($customer, $board);

        // Not offered.
        $names = array_keys(app(AiToolRegistry::class)->availableFor($context));

        $this->assertNotContains('get_code_activity', $names);
        $this->assertNotContains('get_github_repository', $names);

        // And refused when named directly, with none of the material.
        foreach (['get_code_activity', 'get_github_repository'] as $tool) {
            $outcome = app(AiToolRegistry::class)->invoke($tool, ['board' => $board->slug], $context);

            $this->assertFalse($outcome->success, $tool);
            $this->assertStringNotContainsString('Disable VAT', $outcome->text, $tool);
            $this->assertStringNotContainsString('aqueduct/platform', $outcome->text, $tool);
        }

        // The reader refuses in SQL regardless of any of the above.
        $this->assertSame(0, GithubLink::query()->visibleTo($customer)->count());
    }

    public function test_a_customer_cannot_reach_the_activity_feed_through_a_tool(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $this->ticketOn($board, $team, ['title' => 'Internal thing that moved']);

        $context = $this->contextFor($customer, $board);

        $this->assertNotContains('get_activity', array_keys(app(AiToolRegistry::class)->availableFor($context)));

        $outcome = app(AiToolRegistry::class)->invoke('get_activity', [], $context);

        $this->assertFalse($outcome->success);
        $this->assertStringNotContainsString('Internal thing', $outcome->text);
    }

    public function test_a_customer_cannot_reach_ai_runs_at_all(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $ticket = $this->ticketOn($board, $customer, ['title' => 'My bug']);

        AiRun::factory()->for($ticket)->create(['board_id' => $board->getKey()]);

        // The existence of a run on their ticket is itself internal.
        $this->assertSame(0, AiRun::query()->visibleTo($customer)->count());
        $this->assertFalse($customer->can('viewAny', [AiRun::class, $ticket]));
    }

    // -----------------------------------------------------------------
    // The writes
    // -----------------------------------------------------------------

    /**
     * No write tool, in any mode, ever.
     *
     * Asserted across all three modes rather than only the strictest, because
     * the interesting case is the most permissive one: AI Agent is the setting
     * a workspace turns on to let the AI open pull requests, and it must grant
     * a customer nothing.
     */
    public function test_a_customer_is_offered_no_write_tool_in_any_mode(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        foreach (AiCapabilityMode::cases() as $mode) {
            $this->aiMode($mode);

            $this->assertFalse(
                app(AiCapabilityGuard::class)->allowsProposals($board, $customer),
                $mode->value,
            );
        }
    }

    public function test_a_customers_question_carries_no_proposal_tool_to_the_provider(): void
    {
        $provider = $this->fakeAiProvider();

        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        $this->aiMode(AiCapabilityMode::Agent);

        Livewire::actingAs($customer)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'Close my ticket and write a note saying it is done.')
            ->call('send')
            ->assertHasNoErrors();

        foreach ($provider->lastPrompt()->tools as $tool) {
            $this->assertStringStartsNotWith('propose_', $tool->name);
        }
    }

    /**
     * The prompt a customer's question carries says who it is for.
     *
     * Not the security mechanism — the layers above are — but it is what stops
     * the assistant writing a customer an internal note's worth of candour,
     * and the staff prompt's opening paragraph would do exactly that.
     */
    public function test_a_customer_gets_the_customer_facing_prompt(): void
    {
        $provider = $this->fakeAiProvider();

        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        Livewire::actingAs($customer)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'How is my project going?')
            ->call('send');

        $system = $provider->lastPrompt()->system;

        $this->assertStringContainsString('You are speaking to a CUSTOMER', $system);
        $this->assertStringContainsString('cannot change anything', $system);

        // And emphatically not the internal-note framing.
        $this->assertStringNotContainsString('everything you write is an INTERNAL note', $system);
    }

    // -----------------------------------------------------------------
    // The transcripts
    // -----------------------------------------------------------------

    /**
     * A customer on a board must not read the delivery team's conversation
     * about that board.
     *
     * Board reachability alone would allow it, because they are a member. This
     * is the row the ownership clause in AiChatMessage::scopeVisibleTo exists
     * to guard, and it is the leak a naive "let customers in" change would
     * open.
     */
    public function test_a_customer_cannot_read_the_teams_conversation_about_their_board(): void
    {
        $provider = $this->fakeAiProvider();

        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $provider->willReturn('The client is being unreasonable about the deadline.');

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'What do we tell them?')
            ->call('send');

        $this->assertSame(2, AiChatMessage::query()->count());

        // Nothing at all through the scope.
        $this->assertSame(
            [],
            AiChatMessage::query()->visibleTo($customer)->pluck('id')->all(),
        );

        // Nor through the panel, which resolves its own session by ownership.
        $html = Livewire::actingAs($customer)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->html();

        $this->assertStringNotContainsString('unreasonable', $html);
    }

    public function test_one_customer_cannot_read_another_customers_conversation(): void
    {
        $provider = $this->fakeAiProvider();

        $one = $this->customer();
        $two = $this->customer();
        $board = $this->boardWithColumns([$one, $two]);

        $provider->willReturn('Your invoice reference is 44921.');

        Livewire::actingAs($one)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'What is my invoice reference?')
            ->call('send');

        $this->assertSame(
            [],
            AiChatMessage::query()->visibleTo($two)->pluck('id')->all(),
        );
    }

    /**
     * The staff-only full-page chat is still staff-only.
     *
     * Two abilities rather than one loosened ability: the panel is
     * BoardPolicy::useAssistant, the page is ::useAiChat, and the page quotes
     * internal material so it stays shut.
     */
    public function test_the_full_page_board_chat_remains_closed_to_customers(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        // The route gate stops the request before a component exists.
        $this->actingAs($customer)
            ->get(route('boards.ai-chat', $board))
            ->assertForbidden();

        /*
         * And the two abilities differ, which is the actual claim.
         *
         * A single loosened check would have opened both surfaces. The panel
         * asks useAssistant and gets a yes; the page asks useAiChat and gets
         * a 404-shaped no.
         */
        $this->assertTrue($customer->can('useAssistant', $board));
        $this->assertFalse($customer->can('useAiChat', $board));

        // A team member has both.
        $team = $this->teamMember();
        $board->members()->attach($team->getKey());

        $this->assertTrue($team->can('useAssistant', $board));
        $this->assertTrue($team->can('useAiChat', $board));
    }

    /**
     * A deactivated customer reads nothing, even their own thread.
     */
    public function test_a_deactivated_customer_loses_the_assistant(): void
    {
        $provider = $this->fakeAiProvider();

        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        Livewire::actingAs($customer)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'Anything happening?')
            ->call('send');

        $this->assertSame(2, AiChatMessage::query()->count());

        $customer->deactivated_at = now();
        $customer->save();

        $this->assertFalse(Assistant::eligibleFor($customer->refresh()));

        $this->assertSame(
            [],
            AiChatMessage::query()->visibleTo($customer)->pluck('id')->all(),
        );
    }
}
