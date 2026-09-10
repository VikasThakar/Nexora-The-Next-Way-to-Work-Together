<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Actions\AI\ExecuteChatAction;
use App\Enums\AiActionType;
use App\Enums\AiCapabilityMode;
use App\Enums\AiChatMode;
use App\Enums\AiChatRole;
use App\Enums\AiRunMode;
use App\Enums\AiRunStatus;
use App\Enums\AiRunTrigger;
use App\Livewire\Ai\Assistant;
use App\Models\AiChatMessage;
use App\Models\AiRun;
use App\Models\AiToolInvocation;
use App\Services\GitHub\PullRequestClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Asking the assistant for a coding session.
 *
 * "Fix the issue described in NL-123" is a request for work, and the assistant
 * does not do the work — it asks for the existing pipeline to be started, and a
 * person confirms. So the whole of this feature is one more proposal type, and
 * the tests below are mostly about what confirming it does NOT bypass:
 *
 *   AiRunPolicy, applied against the confirming person with the run mode, so
 *   apply mode is authorized as apply mode;
 *   the capability mode, so an Operator workspace cannot get an apply run;
 *   the repository requirement, the daily cap and the credential checks, all
 *   owned by App\Actions\AI\CreateAiRun and none of them re-implemented.
 *
 * And the invariant the whole product rests on: nothing merges. That is not a
 * rule enforced here, it is the absence of a capability — PullRequestClient has
 * no merge method — and the last test asserts the absence.
 */
class AiCodeRunProposalTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // The tool
    // -----------------------------------------------------------------

    public function test_the_coding_session_is_offered_as_a_proposal_tool(): void
    {
        $fake = $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->aiMode(AiCapabilityMode::Agent);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            // A proposal tool is only sent to a conversation that is set to
            // write. AI Agent is the ceiling; this is the asker's own choice
            // within it, and both have to say yes.
            ->call('selectChatMode', AiChatMode::Writing->value)
            ->set('draft', 'Fix the login bug.')
            ->call('send');

        $names = array_map(static fn ($tool): string => $tool->name, $fake->lastPrompt()->tools);

        $this->assertContains('propose_code_run', $names);

        // Named `propose_`, like every other write tool, because the name is
        // part of what tells the model that calling it changes nothing.
        $this->assertSame('propose_code_run', AiActionType::CodeRun->toolName());
    }

    public function test_observer_is_offered_no_coding_session(): void
    {
        $fake = $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->aiMode(AiCapabilityMode::Observer);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'Fix the login bug.')
            ->call('send');

        $names = array_map(static fn ($tool): string => $tool->name, $fake->lastPrompt()->tools);

        $this->assertNotContains('propose_code_run', $names);
    }

    // -----------------------------------------------------------------
    // Confirming one
    // -----------------------------------------------------------------

    public function test_a_proposed_suggest_run_starts_nothing_until_it_is_confirmed(): void
    {
        Queue::fake();

        $fake = $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Login times out']);

        $this->aiMode(AiCapabilityMode::Operator);

        $fake->willPropose('propose_code_run', [
            'ticket' => $ticket->key(),
            'mode' => AiRunMode::Suggest->value,
        ]);

        $component = Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'Look into '.$ticket->key().' please.')
            ->call('send');

        // Proposed, and nothing started.
        $message = AiChatMessage::query()->where('role', 'assistant')->latest('id')->sole();

        $this->assertSame(AiActionType::CodeRun, $message->actionType());
        $this->assertTrue($message->awaitsConfirmation());
        $this->assertSame(0, AiRun::query()->count());

        // Confirmed.
        $component->call('startConfirming', $message->id)->call('confirm');

        $run = AiRun::query()->sole();

        $this->assertSame(AiRunMode::Suggest, $run->mode);
        $this->assertSame(AiRunTrigger::Manual, $run->trigger_source);
        $this->assertSame(AiRunStatus::Queued, $run->status);
        $this->assertSame((int) $ticket->getKey(), (int) $run->ticket_id);

        // Attributed to the person who pressed the button, not to the model.
        $this->assertSame((int) $team->getKey(), (int) $run->triggered_by_id);
    }

    public function test_a_confirmed_coding_session_is_recorded_in_the_audit_ledger(): void
    {
        Queue::fake();

        $fake = $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Audited run']);

        $this->aiMode(AiCapabilityMode::Operator);

        $fake->willPropose('propose_code_run', ['ticket' => $ticket->key()]);

        $component = Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'Have a look at '.$ticket->key().'.')
            ->call('send');

        $message = AiChatMessage::query()->where('role', 'assistant')->latest('id')->sole();

        $component->call('startConfirming', $message->id)->call('confirm');

        $row = AiToolInvocation::query()
            ->where('tool', 'propose_code_run')
            ->sole();

        // A change, not a read: starting a coding session is the most
        // consequential thing the assistant can cause.
        $this->assertSame(AiToolInvocation::CATEGORY_ACTION, $row->category);
        $this->assertTrue($row->success);
        $this->assertSame($ticket->key(), $row->target);
        $this->assertStringContainsString($team->name, (string) $row->message);
    }

    /**
     * A proposal naming a ticket on another board cannot be confirmed here.
     *
     * The proposal is a form submission from an untrusted client, so the
     * reference is re-resolved against THIS board rather than trusted.
     */
    public function test_a_proposal_naming_another_boards_ticket_is_refused(): void
    {
        Queue::fake();

        $fake = $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['ticket_prefix' => 'AAA']);
        $other = $this->boardWithColumns([$team], ['ticket_prefix' => 'BBB']);

        $elsewhere = $this->ticketOn($other, $team, ['title' => 'On the other board']);

        $this->aiMode(AiCapabilityMode::Operator);

        $fake->willPropose('propose_code_run', ['ticket' => $elsewhere->key()]);

        $component = Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'Fix it.')
            ->call('send');

        $message = AiChatMessage::query()->where('role', 'assistant')->latest('id')->sole();

        $component->call('startConfirming', $message->id)->call('confirm');

        $this->assertSame(0, AiRun::query()->count());

        $message->refresh();

        $this->assertSame(AiChatMessage::ACTION_FAILED, $message->metadata['action']['state']);
        $this->assertStringContainsString('not on this board', $message->metadata['action']['error']);
    }

    public function test_a_customer_cannot_confirm_a_coding_session(): void
    {
        Queue::fake();

        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $ticket = $this->ticketOn($board, $customer, ['title' => 'My problem']);

        $this->aiMode(AiCapabilityMode::Agent);

        /*
         * Forged directly, because a customer's assistant is never offered the
         * tool — so this is the case that matters: a message somebody has
         * fabricated, confirmed by a customer.
         */
        $message = new AiChatMessage;
        $message->board_id = $board->getKey();
        $message->user_id = $customer->getKey();
        $message->role = AiChatRole::Assistant;
        $message->content = 'Prepared a coding session.';
        $message->metadata = ['action' => [
            'type' => AiActionType::CodeRun->value,
            'input' => ['ticket' => $ticket->key(), 'mode' => AiRunMode::Apply->value],
            'state' => AiChatMessage::ACTION_PROPOSED,
        ]];
        $message->save();

        try {
            app(ExecuteChatAction::class)->handle($board, $message, $customer);

            $this->fail('A customer must not be able to start a coding session.');
        } catch (AuthorizationException) {
            // The capability guard refuses a customer before anything else.
        }

        $this->assertSame(0, AiRun::query()->count());
    }

    /**
     * Apply mode needs AI Agent, and the refusal explains itself.
     */
    public function test_an_apply_session_is_refused_below_agent_mode(): void
    {
        Queue::fake();

        $fake = $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->repositoryOn($board);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Needs a fix']);

        $this->aiMode(AiCapabilityMode::Operator);

        $fake->willPropose('propose_code_run', [
            'ticket' => $ticket->key(),
            'mode' => AiRunMode::Apply->value,
        ]);

        $component = Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'Fix '.$ticket->key().'.')
            ->call('send');

        $message = AiChatMessage::query()->where('role', 'assistant')->latest('id')->sole();

        $component->call('startConfirming', $message->id)->call('confirm');

        $this->assertSame(0, AiRun::query()->count());

        $message->refresh();

        // CreateAiRun's own words, which name the mode and the remedy.
        $this->assertStringContainsString(
            'AI Agent',
            (string) $message->metadata['action']['error'],
        );
    }

    public function test_an_apply_session_without_a_repository_is_refused(): void
    {
        Queue::fake();

        $fake = $this->fakeAiProvider();

        $team = $this->teamMember();
        // No repository attached at all.
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Needs a fix']);

        $this->aiMode(AiCapabilityMode::Agent);

        $fake->willPropose('propose_code_run', [
            'ticket' => $ticket->key(),
            'mode' => AiRunMode::Apply->value,
        ]);

        $component = Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'Fix '.$ticket->key().'.')
            ->call('send');

        $message = AiChatMessage::query()->where('role', 'assistant')->latest('id')->sole();

        $component->call('startConfirming', $message->id)->call('confirm');

        $this->assertSame(0, AiRun::query()->count());
        $this->assertNotNull($message->refresh()->metadata['action']['error']);
    }

    /**
     * The invariant the whole feature rests on.
     *
     * Not a rule enforced at runtime — an absence. There is no method on the
     * GitHub client that merges, so no path through the assistant, the
     * pipeline or a crafted proposal can reach one. A future phase that
     * genuinely needs to merge has to add the capability and be reviewed for it.
     */
    public function test_nothing_in_this_codebase_can_merge_a_pull_request(): void
    {
        $methods = get_class_methods(PullRequestClient::class);

        foreach ($methods as $method) {
            $this->assertStringNotContainsStringIgnoringCase('merge', $method);
        }

        $source = file_get_contents((new \ReflectionClass(PullRequestClient::class))->getFileName());

        // Nor a request to the merge endpoint by hand.
        $this->assertStringNotContainsString('/merge', (string) $source);
    }
}
