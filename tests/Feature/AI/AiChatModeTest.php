<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Enums\AiActionType;
use App\Enums\AiCapabilityMode;
use App\Enums\AiChatMode;
use App\Livewire\Ai\Assistant;
use App\Models\AiChatMessage;
use App\Models\AiSession;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Reading, Writing, Everything — the setting beside Send.
 *
 * It is the asker's own choice about what the assistant should be doing, and it
 * has three consequences worth pinning separately, because they are enforced in
 * three different places:
 *
 *   the tools    which definitions are sent with the prompt at all
 *                (App\Services\AI\WorkspaceChatService)
 *   the model    reading runs on the balanced model, writing on the deep
 *                reasoning one (App\Services\AI\AiSessionManager)
 *   the ceiling  it narrows AiCapabilityMode and never widens it, so it grants
 *                a customer nothing and grants nobody anything under
 *                AI Observer (App\Services\AI\AiCapabilityGuard)
 *
 * The third is the one to be careful about, and it has its own section below.
 */
class AiChatModeTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Which tools each mode is given
    // -----------------------------------------------------------------

    public function test_reading_is_offered_no_write_tools(): void
    {
        $fake = $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        $this->aiMode(AiCapabilityMode::Agent);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->call('selectChatMode', AiChatMode::Reading->value)
            ->set('draft', 'What is in progress?')
            ->call('send')
            ->assertHasNoErrors();

        $names = $this->toolNames($fake);

        // AI Agent is in force, so the ceiling permits proposals. The
        // conversation does not, and the conversation is what decides here.
        $this->assertNotEmpty($names);

        foreach ($names as $name) {
            $this->assertStringStartsNotWith('propose_', $name);
        }
    }

    public function test_writing_is_offered_the_proposal_tools_and_not_the_lookups(): void
    {
        $fake = $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        $this->aiMode(AiCapabilityMode::Agent);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->call('selectChatMode', AiChatMode::Writing->value)
            ->set('draft', 'Raise a ticket for the login bug.')
            ->call('send')
            ->assertHasNoErrors();

        $names = $this->toolNames($fake);

        $this->assertContains(AiActionType::CreateTicket->toolName(), $names);

        // No lookups. A writing turn is about producing the change; the board
        // context block still goes in, so it is not drafting blind.
        foreach ($names as $name) {
            $this->assertStringStartsWith('propose_', $name);
        }
    }

    public function test_everything_is_offered_both(): void
    {
        $fake = $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        $this->aiMode(AiCapabilityMode::Agent);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->call('selectChatMode', AiChatMode::Everything->value)
            ->set('draft', 'What is overdue, and raise a ticket for it.')
            ->call('send')
            ->assertHasNoErrors();

        $names = $this->toolNames($fake);

        $this->assertContains(AiActionType::CreateTicket->toolName(), $names);
        $this->assertContains('get_ticket', $names);
    }

    // -----------------------------------------------------------------
    // The ceiling still wins
    // -----------------------------------------------------------------

    /**
     * The one property this whole feature depends on: the setting is a request,
     * not a grant. An administrator who has put the workspace in AI Observer
     * has said the AI changes nothing, and no dropdown overrides that.
     */
    public function test_writing_under_observer_is_still_offered_no_write_tools(): void
    {
        $fake = $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        $this->aiMode(AiCapabilityMode::Observer);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->call('selectChatMode', AiChatMode::Writing->value)
            // Narrowed on the way in, and said so rather than silently ignored.
            ->assertSet('chatMode', AiChatMode::Reading->value)
            ->set('draft', 'Raise a ticket for the login bug.')
            ->call('send');

        foreach ($this->toolNames($fake) as $name) {
            $this->assertStringStartsNotWith('propose_', $name);
        }

        $this->assertSame(AiChatMode::Reading, AiSession::query()->sole()->chat_mode);
    }

    /**
     * A customer is refused in every capability mode, AI Agent included — so
     * the picker refuses too, and the tools are withheld regardless.
     */
    public function test_a_customer_cannot_put_a_conversation_into_a_write_mode(): void
    {
        $fake = $this->fakeAiProvider();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        $this->aiMode(AiCapabilityMode::Agent);

        Livewire::actingAs($customer)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->call('selectChatMode', AiChatMode::Everything->value)
            ->assertSet('chatMode', AiChatMode::Reading->value)
            ->set('draft', 'Raise a ticket for the login bug.')
            ->call('send');

        foreach ($this->toolNames($fake) as $name) {
            $this->assertStringStartsNotWith('propose_', $name);
        }
    }

    /**
     * The property is browser-writable, so setting it directly must not be a
     * way around the picker's refusal. Nothing is trusted until the exchange
     * asks the guard again.
     */
    public function test_setting_the_property_directly_does_not_reach_the_tools(): void
    {
        $fake = $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        $this->aiMode(AiCapabilityMode::Observer);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('chatMode', AiChatMode::Everything->value)
            ->set('draft', 'Raise a ticket.')
            ->call('send');

        foreach ($this->toolNames($fake) as $name) {
            $this->assertStringStartsNotWith('propose_', $name);
        }
    }

    // -----------------------------------------------------------------
    // What the composer shows
    // -----------------------------------------------------------------

    public function test_the_picker_replaces_the_session_bar_model_picker_and_usage_panel(): void
    {
        $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        $this->aiMode(AiCapabilityMode::Agent);

        $html = Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->html();

        // Beside Send, with all three settings on offer.
        $this->assertStringContainsString('selectChatMode', $html);

        foreach (AiChatMode::cases() as $mode) {
            $this->assertStringContainsString('>'.$mode->label().'</option>', $html);
        }

        // And the three things it replaced are gone.
        $this->assertStringNotContainsString('Session usage', $html);
        $this->assertStringNotContainsString('New session', $html);
        $this->assertStringNotContainsString('selectModel', $html);
    }

    /**
     * A mode nobody may use here is shown greyed rather than hidden, with the
     * reason underneath — so the setting stays discoverable and somebody knows
     * what would have to change for it to work.
     */
    public function test_a_mode_that_is_unavailable_is_disabled_and_explained(): void
    {
        $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        $this->aiMode(AiCapabilityMode::Observer);

        $html = Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->html();

        $this->assertStringContainsString(AiChatMode::Writing->label(), $html);
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringContainsString(AiCapabilityMode::Observer->label(), $html);
    }

    // -----------------------------------------------------------------
    // What the turn records
    // -----------------------------------------------------------------

    public function test_the_turn_records_which_mode_it_was_asked_in(): void
    {
        $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        $this->aiMode(AiCapabilityMode::Agent);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->call('selectChatMode', AiChatMode::Writing->value)
            ->set('draft', 'Raise a ticket.')
            ->call('send');

        $answer = AiChatMessage::query()->where('role', 'assistant')->sole();

        $this->assertSame(
            AiChatMode::Writing->value,
            data_get($answer->metadata, 'chat_mode'),
        );
    }

    // -----------------------------------------------------------------
    // Naming a board from the workspace conversation
    // -----------------------------------------------------------------

    /**
     * "Make a ticket called X on NutriLens", asked with the whole workspace in
     * scope.
     *
     * Before the board field existed this was a proposal with nowhere to land:
     * the workspace thread has no board, and confirming refused. It now
     * resolves the named board at confirmation time, against the confirming
     * person's own membership, and the ordinary CreateTicket action makes the
     * ticket.
     */
    public function test_a_workspace_conversation_can_create_a_ticket_on_a_named_board(): void
    {
        $fake = $this->fakeAiProvider();
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['name' => 'NutriLens', 'ticket_prefix' => 'NL']);

        $this->aiMode(AiCapabilityMode::Operator);

        $fake->willPropose(AiActionType::CreateTicket->toolName(), [
            'title' => 'Add a privacy page',
            'description_md' => 'Nutrition+ needs a privacy page.',
            'board' => 'NutriLens',
        ]);

        $component = Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal')
            ->call('selectChatMode', AiChatMode::Writing->value)
            ->set('draft', 'Make a ticket called "Add a privacy page" on NutriLens.')
            ->call('send')
            ->assertHasNoErrors();

        $proposal = AiChatMessage::query()->where('role', 'assistant')->sole();

        // Filed against the workspace, exactly as the conversation was.
        $this->assertNull($proposal->board_id);

        $component->call('startConfirming', $proposal->id)
            ->call('confirm')
            ->assertHasNoErrors();

        $ticket = Ticket::query()->sole();

        $this->assertSame('Add a privacy page', $ticket->title);
        $this->assertSame($board->getKey(), $ticket->board_id);
        $this->assertSame(AiChatMessage::ACTION_CONFIRMED, $proposal->refresh()->actionState());
    }

    public function test_a_board_can_be_named_by_its_ticket_prefix(): void
    {
        $fake = $this->fakeAiProvider();
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['name' => 'NutriLens', 'ticket_prefix' => 'NL']);

        $this->aiMode(AiCapabilityMode::Operator);

        $fake->willPropose(AiActionType::CreateTicket->toolName(), [
            'title' => 'Add a terms page',
            'board' => 'nl',
        ]);

        $component = Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal')
            ->call('selectChatMode', AiChatMode::Writing->value)
            ->set('draft', 'Add a ticket to NL.')
            ->call('send');

        $proposal = AiChatMessage::query()->where('role', 'assistant')->sole();

        $component->call('startConfirming', $proposal->id)->call('confirm');

        $this->assertSame($board->getKey(), Ticket::query()->sole()->board_id);
    }

    /**
     * Resolution runs through BoardAccess with the confirming person as the
     * viewer, so naming a board they are not on resolves to nothing — the same
     * answer they get for a ticket they cannot see.
     */
    public function test_naming_a_board_the_person_is_not_on_creates_nothing(): void
    {
        $fake = $this->fakeAiProvider();
        $team = $this->teamMember();

        $mine = $this->boardWithColumns([$team], ['name' => 'Mine', 'ticket_prefix' => 'MI']);
        $this->boardWithColumns([], ['name' => 'Theirs', 'ticket_prefix' => 'TH']);

        $this->aiMode(AiCapabilityMode::Operator);

        $fake->willPropose(AiActionType::CreateTicket->toolName(), [
            'title' => 'Something they should not see',
            'board' => 'Theirs',
        ]);

        $component = Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal')
            ->call('selectChatMode', AiChatMode::Writing->value)
            ->set('draft', 'Add a ticket to Theirs.')
            ->call('send');

        $proposal = AiChatMessage::query()->where('role', 'assistant')->sole();

        $component->call('startConfirming', $proposal->id)->call('confirm');

        $this->assertSame(0, Ticket::query()->count());
        $this->assertNotNull($component->get('aiError'));

        // And nothing landed on the board they *are* on either.
        $this->assertSame(0, Ticket::query()->where('board_id', $mine->getKey())->count());
    }

    /**
     * A board conversation ignores a named board rather than obeying it.
     *
     * The failure this prevents: a proposal drafted while talking about board A
     * that quietly writes to board B because the model repeated a name from a
     * ticket description.
     */
    public function test_a_board_conversation_ignores_a_named_board(): void
    {
        $fake = $this->fakeAiProvider();
        $team = $this->teamMember();

        $here = $this->boardWithColumns([$team], ['name' => 'Here', 'ticket_prefix' => 'HE']);
        $elsewhere = $this->boardWithColumns([$team], ['name' => 'Elsewhere', 'ticket_prefix' => 'EL']);

        $this->aiMode(AiCapabilityMode::Operator);

        $fake->willPropose(AiActionType::CreateTicket->toolName(), [
            'title' => 'A ticket',
            'board' => 'Elsewhere',
        ]);

        $component = Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $here->slug)
            ->call('selectScope', $here->slug)
            ->call('selectChatMode', AiChatMode::Writing->value)
            ->set('draft', 'Add a ticket.')
            ->call('send');

        $proposal = AiChatMessage::query()->where('role', 'assistant')->sole();

        $component->call('startConfirming', $proposal->id)->call('confirm');

        $ticket = Ticket::query()->sole();

        $this->assertSame($here->getKey(), $ticket->board_id);
        $this->assertNotSame($elsewhere->getKey(), $ticket->board_id);
    }

    /**
     * An unnamed board in the workspace conversation is refused, not guessed.
     */
    public function test_a_workspace_proposal_with_no_board_creates_nothing(): void
    {
        $fake = $this->fakeAiProvider();
        $team = $this->teamMember();
        $this->boardWithColumns([$team]);

        $this->aiMode(AiCapabilityMode::Operator);

        $fake->willPropose(AiActionType::CreateTicket->toolName(), [
            'title' => 'A ticket with no home',
        ]);

        $component = Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal')
            ->call('selectChatMode', AiChatMode::Writing->value)
            ->set('draft', 'Make a ticket.')
            ->call('send');

        $proposal = AiChatMessage::query()->where('role', 'assistant')->sole();

        $component->call('startConfirming', $proposal->id)->call('confirm');

        $this->assertSame(0, Ticket::query()->count());
        $this->assertNotNull($component->get('aiError'));
    }

    // -----------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function toolNames(object $fake): array
    {
        return array_map(
            static fn ($tool): string => $tool->name,
            $fake->lastPrompt()->tools,
        );
    }
}
