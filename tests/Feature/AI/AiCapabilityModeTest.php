<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Actions\AI\CreateAiRun;
use App\Actions\AI\Exceptions\AiRunRefused;
use App\Actions\AI\ExecuteChatAction;
use App\Enums\AiActionType;
use App\Enums\AiCapabilityMode;
use App\Enums\AiChatMode;
use App\Enums\AiChatRole;
use App\Enums\AiRunMode;
use App\Enums\AiRunTrigger;
use App\Enums\TicketEventType;
use App\Livewire\Ai\Assistant;
use App\Models\AiChatMessage;
use App\Models\AiRun;
use App\Models\Ticket;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The three modes, enforced on the server.
 *
 * The point of every test in this file is that the enforcement is not the UI.
 * Under AI Observer the model is offered no write tools at all — so there is
 * nothing to confirm — and a proposal that survived a tightening of the mode is
 * refused when somebody presses Confirm. Under Operator a run may post its
 * analysis; only Agent may change code or start a run nobody asked for.
 *
 * The mode is a ceiling and never a floor. The last section is the one that
 * matters most: raising a workspace to AI Agent grants no user a capability
 * they did not already have by hand, and it does not make a customer staff.
 */
class AiCapabilityModeTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Observer: no write tools at all
    // -----------------------------------------------------------------

    public function test_observer_is_offered_no_write_tools(): void
    {
        $fake = $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        $this->aiMode(AiCapabilityMode::Observer);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'How is the work going?')
            ->call('send')
            ->assertHasNoErrors();

        $this->assertNotNull($fake->lastPrompt());

        /*
         * No WRITE tool, rather than no tool at all.
         *
         * Read tools are offered in every mode, Observer included — looking a
         * ticket up is the thing Observer is for, and the lookup is authorized
         * against the person asking either way. What Observer withholds is the
         * ability to produce a proposal, and a model with no `propose_*` tool
         * cannot produce one: there is no preview, no confirm button, and
         * nothing for a crafted request to try to execute.
         */
        foreach ($fake->lastPrompt()->tools as $tool) {
            $this->assertStringStartsNotWith('propose_', $tool->name);
        }
    }

    public function test_operator_and_agent_are_offered_the_proposal_tools(): void
    {
        foreach ([AiCapabilityMode::Operator, AiCapabilityMode::Agent] as $mode) {
            $fake = $this->fakeAiProvider();
            $board = $this->boardWithColumns([$team = $this->teamMember()]);

            $this->aiMode($mode);

            Livewire::actingAs($team)
                ->test(Assistant::class)
                ->call('reveal', $board->slug)
                ->call('selectScope', $board->slug)
                // Everything, so both halves of the tool list are in play: the
                // capability mode is what this test is about, and Reading —
                // the default — would withhold the proposals for a reason that
                // has nothing to do with it.
                ->call('selectChatMode', AiChatMode::Everything->value)
                ->set('draft', 'Draft a ticket for the login bug.')
                ->call('send')
                ->assertHasNoErrors();

            $names = array_map(
                static fn ($tool): string => $tool->name,
                $fake->lastPrompt()->tools,
            );

            // Every proposal type is offered, and each one by name — so adding
            // a case to the enum without offering it fails here.
            foreach (AiActionType::cases() as $type) {
                $this->assertContains($type->toolName(), $names, $mode->value);
            }

            /*
             * And nothing else writes.
             *
             * The invariant that matters is not the count — read tools are in
             * this list too — but that every tool which is not a read is a
             * proposal. Even AI Agent is given no tool that changes anything
             * without a person confirming it.
             */
            foreach ($names as $name) {
                // A read tool, by naming convention: every one in the registry
                // is get_* or search_*, and the convention is asserted here so
                // that a tool called anything else has to be either a proposal
                // or a deliberate change to this test.
                if (preg_match('/^(get|search)_/', $name) === 1) {
                    continue;
                }

                $this->assertStringStartsWith('propose_', $name, $mode->value);
            }
        }
    }

    /**
     * The case that actually happens: a preview on screen when an
     * administrator tightens the workspace, and Confirm is a separate request.
     */
    public function test_a_proposal_cannot_be_confirmed_after_the_mode_is_tightened(): void
    {
        $fake = $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        $this->aiMode(AiCapabilityMode::Operator);

        $fake->willPropose('propose_create_ticket', ['title' => 'Fix the login bug']);

        $component = Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'Raise a ticket for the login bug.')
            ->call('send');

        $message = AiChatMessage::query()
            ->where('role', 'assistant')
            ->latest('id')
            ->sole();

        $this->assertTrue($message->awaitsConfirmation());

        // The workspace is narrowed while the preview is on screen.
        $this->aiMode(AiCapabilityMode::Observer);

        $component->call('startConfirming', $message->getKey())
            ->call('confirm');

        // Nothing was created, and the transcript says why.
        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(AiChatMessage::ACTION_PROPOSED, $message->refresh()->actionState());
    }

    public function test_the_action_itself_refuses_under_observer(): void
    {
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        $message = new AiChatMessage;
        $message->board_id = $board->getKey();
        $message->user_id = $team->getKey();
        $message->role = AiChatRole::Assistant;
        $message->content = 'Prepared a ticket.';
        $message->metadata = ['action' => [
            'type' => AiActionType::CreateTicket->value,
            'input' => ['title' => 'Fix the login bug'],
            'state' => AiChatMessage::ACTION_PROPOSED,
        ]];
        $message->save();

        $this->aiMode(AiCapabilityMode::Observer);

        $this->expectException(AuthorizationException::class);

        app(ExecuteChatAction::class)->handle($board, $message, $team);
    }

    // -----------------------------------------------------------------
    // Ticket runs
    // -----------------------------------------------------------------

    public function test_observer_refuses_every_ticket_run(): void
    {
        $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);
        $ticket = $this->ticketOn($board, $team);

        $this->aiMode(AiCapabilityMode::Observer);

        $refusal = app(CreateAiRun::class)->refusalFor($ticket, AiRunMode::Suggest, $team);

        $this->assertNotNull($refusal);
        $this->assertSame(AiRunRefused::REASON_CAPABILITY_MODE, $refusal->reason);

        try {
            app(CreateAiRun::class)->handle($ticket, AiRunMode::Suggest, AiRunTrigger::Manual, $team);
            $this->fail('A run was created under AI Observer.');
        } catch (AiRunRefused $exception) {
            $this->assertSame(AiRunRefused::REASON_CAPABILITY_MODE, $exception->reason);
        }

        $this->assertSame(0, AiRun::query()->count());
    }

    public function test_operator_allows_a_manual_suggest_run(): void
    {
        $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);
        $ticket = $this->ticketOn($board, $team);

        $this->aiMode(AiCapabilityMode::Operator);

        $this->assertNull(app(CreateAiRun::class)->refusalFor($ticket, AiRunMode::Suggest, $team));

        $run = app(CreateAiRun::class)->handle($ticket, AiRunMode::Suggest, AiRunTrigger::Manual, $team);

        $this->assertSame(AiRunMode::Suggest, $run->mode);
    }

    public function test_operator_refuses_an_apply_run_because_it_changes_code(): void
    {
        $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);
        $this->repositoryOn($board);
        $ticket = $this->ticketOn($board, $team);

        $this->aiMode(AiCapabilityMode::Operator);

        $refusal = app(CreateAiRun::class)->refusalFor($ticket, AiRunMode::Apply, $team);

        $this->assertNotNull($refusal);
        $this->assertSame(AiRunRefused::REASON_CAPABILITY_MODE, $refusal->reason);
        $this->assertStringContainsString(AiCapabilityMode::Agent->label(), $refusal->getMessage());
    }

    public function test_agent_allows_an_apply_run(): void
    {
        $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);
        $this->repositoryOn($board);
        $ticket = $this->ticketOn($board, $team);

        $this->aiMode(AiCapabilityMode::Agent);

        $this->assertNull(app(CreateAiRun::class)->refusalFor($ticket, AiRunMode::Apply, $team));
    }

    public function test_operator_refuses_an_unattended_run(): void
    {
        $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);
        $ticket = $this->ticketOn($board, $team);

        $this->aiMode(AiCapabilityMode::Operator);

        try {
            app(CreateAiRun::class)->handle($ticket, AiRunMode::Suggest, AiRunTrigger::Automatic, null);
            $this->fail('An automatic run started under AI Operator.');
        } catch (AiRunRefused $exception) {
            $this->assertSame(AiRunRefused::REASON_CAPABILITY_MODE, $exception->reason);
        }

        $this->assertSame(0, AiRun::query()->count());
    }

    /**
     * A refused automatic run leaves a trace, exactly as a capped one does.
     *
     * Otherwise the team is left wondering why nothing happened, which is the
     * worst kind of quiet.
     */
    public function test_a_mode_refusal_is_recorded_on_the_ticket_timeline(): void
    {
        $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);
        $ticket = $this->ticketOn($board, $team);

        $this->aiMode(AiCapabilityMode::Observer);

        try {
            app(CreateAiRun::class)->handle($ticket, AiRunMode::Suggest, AiRunTrigger::Manual, $team);
        } catch (AiRunRefused) {
            // Expected.
        }

        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->getKey(),
            'type' => TicketEventType::AiRunSkipped->value,
        ]);
    }

    // -----------------------------------------------------------------
    // A board may be stricter, or looser, than the workspace
    // -----------------------------------------------------------------

    public function test_a_board_can_be_stricter_than_the_workspace(): void
    {
        $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);
        $ticket = $this->ticketOn($board, $team);

        $this->aiMode(AiCapabilityMode::Agent);
        $this->boardAiMode($board, AiCapabilityMode::Observer);

        $refusal = app(CreateAiRun::class)->refusalFor($ticket->refresh(), AiRunMode::Suggest, $team);

        $this->assertNotNull($refusal);
        $this->assertSame(AiRunRefused::REASON_CAPABILITY_MODE, $refusal->reason);
    }

    public function test_a_board_can_be_looser_than_the_workspace(): void
    {
        $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);
        $ticket = $this->ticketOn($board, $team);

        $this->aiMode(AiCapabilityMode::Observer);
        $this->boardAiMode($board, AiCapabilityMode::Operator);

        $this->assertNull(app(CreateAiRun::class)->refusalFor($ticket->refresh(), AiRunMode::Suggest, $team));
    }

    // -----------------------------------------------------------------
    // No mode is a bypass
    // -----------------------------------------------------------------

    /**
     * AI Agent does not make a customer staff.
     *
     * The mode widens what the AI may attempt; it never widens what a person
     * may do. A customer has no relationship with the assistant at any mode.
     */
    public function test_agent_mode_grants_a_customer_no_staff_capability(): void
    {
        $fake = $this->fakeAiProvider();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        $this->aiMode(AiCapabilityMode::Agent);

        // A customer does have the read-only assistant panel.
        $this->assertTrue(Assistant::eligibleFor($customer));

        // The staff chat page is still not theirs, and still 404s rather than
        // 403s at the route gate.
        $this->actingAs($customer)
            ->get(route('boards.ai-chat', $board))
            ->assertForbidden();

        $ticket = $this->ticketOn($board, $customer);

        // A customer cannot start a run, whatever the mode says.
        $this->assertFalse($customer->can('create', [AiRun::class, $ticket]));

        /*
         * And the highest mode the product has still sends them no write tool.
         *
         * This is the assertion that matters: AI Agent is the most permissive
         * setting available, and under it a customer's request is built with no
         * `propose_*` tool at all — so there is nothing for a crafted follow-up
         * to confirm, and no proposal can exist in their transcript.
         */
        Livewire::actingAs($customer)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'Please close my ticket.')
            ->call('send')
            ->assertHasNoErrors();

        $this->assertNotNull($fake->lastPrompt());

        foreach ($fake->lastPrompt()->tools as $tool) {
            $this->assertStringStartsNotWith('propose_', $tool->name);
        }

        // Nor the staff-only lookups.
        $names = array_map(static fn ($tool): string => $tool->name, $fake->lastPrompt()->tools);

        $this->assertNotContains('get_activity', $names);
        $this->assertNotContains('get_github_repository', $names);
        $this->assertNotContains('get_code_activity', $names);
    }

    /**
     * A confirmed proposal is still authorized against the person confirming
     * it, with the same abilities the ordinary screens use.
     */
    public function test_agent_mode_does_not_let_a_proposal_write_where_the_person_cannot(): void
    {
        $this->fakeAiProvider();
        $this->aiMode(AiCapabilityMode::Agent);

        $team = $this->teamMember();
        // A board the team member is NOT a member of.
        $otherBoard = $this->boardWithColumns([$this->teamMember()]);

        $message = new AiChatMessage;
        $message->board_id = $otherBoard->getKey();
        $message->user_id = $team->getKey();
        $message->role = AiChatRole::Assistant;
        $message->content = 'Prepared a ticket.';
        $message->metadata = ['action' => [
            'type' => AiActionType::CreateTicket->value,
            'input' => ['title' => 'Fix the login bug'],
            'state' => AiChatMessage::ACTION_PROPOSED,
        ]];
        $message->save();

        $this->expectException(AuthorizationException::class);

        app(ExecuteChatAction::class)->handle($otherBoard, $message, $team);
    }

    // -----------------------------------------------------------------
    // The enum's own arithmetic
    // -----------------------------------------------------------------

    public function test_the_modes_are_cumulative(): void
    {
        $observer = AiCapabilityMode::Observer;
        $operator = AiCapabilityMode::Operator;
        $agent = AiCapabilityMode::Agent;

        $this->assertTrue($agent->atLeast($observer));
        $this->assertTrue($agent->atLeast($operator));
        $this->assertTrue($operator->atLeast($observer));
        $this->assertFalse($observer->atLeast($operator));
        $this->assertFalse($operator->atLeast($agent));

        $this->assertFalse($observer->canProposeWrites());
        $this->assertTrue($operator->canProposeWrites());

        $this->assertFalse($operator->canWriteCode());
        $this->assertTrue($agent->canWriteCode());

        $this->assertFalse($operator->canRunUnattended());
        $this->assertTrue($agent->canRunUnattended());
    }
}
