<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Actions\AI\UpdateGlobalAiSettings;
use App\Enums\AiCapabilityMode;
use App\Enums\AiChatMode;
use App\Enums\AiProvider;
use App\Livewire\Ai\Assistant;
use App\Models\AiChatMessage;
use App\Models\AiSession;
use App\Models\AiUsageRecord;
use App\Services\AI\AiContextScope;
use App\Services\AI\AiSessionManager;
use App\Support\AiModelCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sessions: starting one, continuing one, switching between them, and what
 * happens when one fills up.
 *
 * Sessions exist for context management. A month-old thread re-sends a month of
 * turns to answer a question about today, which is slow, dear and worse at
 * answering — so the important behaviours here are that a new session starts
 * with an empty context, that the old one survives, and that a full session
 * says so rather than silently costing more.
 */
class AiSessionTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Creation and continuation
    // -----------------------------------------------------------------

    public function test_asking_a_question_creates_a_session(): void
    {
        $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'How is the work going?')
            ->call('send')
            ->assertHasNoErrors();

        $session = AiSession::query()->sole();

        $this->assertSame($team->getKey(), $session->user_id);
        $this->assertSame($board->getKey(), $session->board_id);
        $this->assertTrue($session->isOpen());
        // Both turns.
        $this->assertSame(2, $session->message_count);
        $this->assertSame(2, AiChatMessage::query()->where('ai_session_id', $session->getKey())->count());
    }

    public function test_a_second_question_continues_the_same_session(): void
    {
        $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'First question.')
            ->call('send')
            ->set('draft', 'Second question.')
            ->call('send')
            ->assertHasNoErrors();

        $this->assertSame(1, AiSession::query()->count());
        $this->assertSame(4, AiSession::query()->sole()->message_count);
    }

    public function test_starting_a_new_session_ends_the_previous_one_without_deleting_it(): void
    {
        $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        $component = Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'First conversation.')
            ->call('send');

        $first = AiSession::query()->sole();

        $component->call('startNewSession')->assertHasNoErrors();

        $this->assertSame(2, AiSession::query()->count());

        // The old one is closed, and still there with its turns.
        $this->assertFalse($first->refresh()->isOpen());
        $this->assertSame(2, $first->message_count);
        $this->assertSame(2, AiChatMessage::query()->where('ai_session_id', $first->getKey())->count());

        // The new one is empty: a fresh context, which is the whole point.
        $second = AiSession::query()->whereKeyNot($first->getKey())->sole();
        $this->assertTrue($second->isOpen());
        $this->assertSame(0, $second->message_count);
    }

    /**
     * A new session is a new context, not merely a new label.
     *
     * The prompt sent after starting one must not replay the previous
     * conversation, or the feature would not solve the problem it exists for.
     */
    public function test_a_new_session_does_not_replay_the_old_transcript(): void
    {
        $fake = $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'A very distinctive first question about kumquats.')
            ->call('send')
            ->call('startNewSession')
            ->set('draft', 'An unrelated second question.')
            ->call('send')
            ->assertHasNoErrors();

        $this->assertStringNotContainsString('kumquats', $fake->lastPayload());
        $this->assertStringContainsString('An unrelated second question.', $fake->lastPayload());
    }

    /**
     * The session bar that used to offer a history list is gone — the mode
     * picker replaced it — but the resolution it relied on has not changed, and
     * it is the part that matters: `sessionUuid` is browser-writable, so a
     * conversation is reachable by uuid and re-authorized on every request.
     */
    public function test_a_previous_session_is_still_reachable_by_uuid(): void
    {
        $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        $component = Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'First conversation.')
            ->call('send')
            ->call('startNewSession');

        $first = AiSession::query()->orderBy('id')->first();

        $component->set('sessionUuid', $first->uuid)
            ->assertSet('sessionUuid', $first->uuid)
            ->assertSee('First conversation.');
    }

    public function test_a_session_can_be_renamed(): void
    {
        $session = AiSession::factory()->create(['title' => null]);

        app(AiSessionManager::class)->rename($session, 'Sprint planning');

        $this->assertSame('Sprint planning', $session->refresh()->title);
    }

    public function test_a_blank_name_falls_back_to_the_reference(): void
    {
        $session = AiSession::factory()->create(['title' => 'Something']);

        app(AiSessionManager::class)->rename($session, '   ');

        $this->assertNull($session->refresh()->title);
        $this->assertSame('AI-'.$session->getKey(), $session->displayTitle());
        $this->assertSame('AI-'.$session->getKey(), $session->reference());
    }

    // -----------------------------------------------------------------
    // Scope
    // -----------------------------------------------------------------

    /**
     * A session belongs to one scope. Switching context switches conversation,
     * because an answer built from board B's context must not be filed under
     * board A's history.
     */
    public function test_each_scope_gets_its_own_session(): void
    {
        $this->fakeAiProvider();
        $team = $this->teamMember();
        $boardA = $this->boardWithColumns([$team], ['name' => 'Alpha', 'ticket_prefix' => 'AL']);
        $boardB = $this->boardWithColumns([$team], ['name' => 'Beta', 'ticket_prefix' => 'BE']);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $boardA->slug)
            ->call('selectScope', $boardA->slug)
            ->set('draft', 'About Alpha.')
            ->call('send')
            ->call('selectScope', $boardB->slug)
            ->set('draft', 'About Beta.')
            ->call('send')
            ->assertHasNoErrors();

        $this->assertSame(2, AiSession::query()->count());
        $this->assertSame(1, AiSession::query()->where('board_id', $boardA->getKey())->count());
        $this->assertSame(1, AiSession::query()->where('board_id', $boardB->getKey())->count());
    }

    public function test_the_workspace_scope_has_its_own_session(): void
    {
        $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal')
            ->call('selectScope', AiContextScope::MODE_WORKSPACE)
            ->set('draft', 'Across everything.')
            ->call('send')
            ->assertHasNoErrors();

        $this->assertSame(1, AiSession::query()->whereNull('board_id')->count());
    }

    /**
     * A session uuid from another scope resolves to nothing, so the panel
     * cannot be pointed at a conversation about a different board.
     */
    public function test_a_session_from_another_scope_cannot_be_selected(): void
    {
        $this->fakeAiProvider();
        $team = $this->teamMember();
        $boardA = $this->boardWithColumns([$team], ['name' => 'Alpha', 'ticket_prefix' => 'AL']);
        $boardB = $this->boardWithColumns([$team], ['name' => 'Beta', 'ticket_prefix' => 'BE']);

        $other = AiSession::factory()->ownedBy($team)->forBoard($boardB)->create();

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $boardA->slug)
            ->call('selectScope', $boardA->slug)
            ->set('sessionUuid', $other->uuid)
            ->assertNotSet('sessionUuid', $other->uuid);
    }

    // -----------------------------------------------------------------
    // Ownership
    // -----------------------------------------------------------------

    public function test_a_session_is_private_to_the_person_who_started_it(): void
    {
        $mine = $this->teamMember();
        $theirs = $this->teamMember();
        $board = $this->boardWithColumns([$mine, $theirs]);

        $theirSession = AiSession::factory()->ownedBy($theirs)->forBoard($board)->create();

        $visible = AiSession::query()->visibleTo($mine)->pluck('id')->all();

        $this->assertNotContains($theirSession->getKey(), $visible);
    }

    public function test_an_administrator_cannot_read_somebody_elses_session(): void
    {
        $admin = $this->admin();
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $session = AiSession::factory()->ownedBy($team)->forBoard($board)->create();

        $this->assertSame([], AiSession::query()->visibleTo($admin)->pluck('id')->all());
        $this->assertNull(
            app(AiSessionManager::class)->find(AiContextScope::board($board), $admin, $session->uuid)
        );
    }

    /**
     * A customer has a conversation of their own, and only their own.
     *
     * The rule this scope used to enforce was "customers have no sessions",
     * because the assistant was staff-only. Customers now have a read-only
     * one, so the assertion that matters has moved: their own thread resolves,
     * and the delivery team's thread about the very same board does not.
     *
     * That second half is the leak this test exists to prevent. A customer is a
     * member of the board, so board reachability alone would hand them the
     * team's conversation about their project — which quotes internal tickets
     * and internal notes. Ownership is what stops it.
     */
    public function test_a_customer_sees_only_their_own_sessions(): void
    {
        $customer = $this->customer();
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$customer, $team]);

        $theirs = AiSession::factory()->ownedBy($customer)->forBoard($board)->create();
        $staffSession = AiSession::factory()->ownedBy($team)->forBoard($board)->create();
        $otherCustomer = AiSession::factory()->ownedBy($this->customer())->forBoard($board)->create();

        $visible = AiSession::query()->visibleTo($customer)->pluck('id')->all();

        $this->assertSame([$theirs->getKey()], $visible);
        $this->assertNotContains($staffSession->getKey(), $visible);
        $this->assertNotContains($otherCustomer->getKey(), $visible);
    }

    /**
     * A deactivated account reads nothing, including its own board-less thread.
     *
     * Worth its own test because it used to be implied rather than stated: the
     * customer check the scope opened with was itself "active and staff", and
     * removing it left "still has an account here" needing to be its own
     * condition.
     */
    public function test_a_deactivated_account_sees_no_sessions(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        AiSession::factory()->ownedBy($team)->forBoard($board)->create();
        AiSession::factory()->ownedBy($team)->create();

        $this->assertCount(2, AiSession::query()->visibleTo($team)->get());

        $team->deactivated_at = now();
        $team->save();

        $this->assertSame([], AiSession::query()->visibleTo($team->refresh())->pluck('id')->all());
    }

    public function test_a_session_on_a_board_the_owner_has_left_stops_being_readable(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $session = AiSession::factory()->ownedBy($team)->forBoard($board)->create();

        $this->assertContains($session->getKey(), AiSession::query()->visibleTo($team)->pluck('id')->all());

        $board->members()->detach($team->getKey());

        $this->assertSame([], AiSession::query()->visibleTo($team->refresh())->pluck('id')->all());
    }

    public function test_a_session_owned_by_somebody_else_cannot_be_adopted(): void
    {
        $this->fakeAiProvider();
        $mine = $this->teamMember();
        $theirs = $this->teamMember();
        $board = $this->boardWithColumns([$mine, $theirs]);

        $theirSession = AiSession::factory()->ownedBy($theirs)->forBoard($board)->create();

        Livewire::actingAs($mine)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('sessionUuid', $theirSession->uuid)
            ->assertNotSet('sessionUuid', $theirSession->uuid);
    }

    // -----------------------------------------------------------------
    // The mode, and the model that follows from it
    // -----------------------------------------------------------------

    public function test_the_session_records_the_model_and_provider_it_ran_under(): void
    {
        $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        $this->aiMode(AiCapabilityMode::Operator);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'A question.')
            ->call('send');

        $session = AiSession::query()->sole();

        $this->assertSame(AiProvider::Anthropic, $session->provider);
        // The model follows the chat mode, and a new conversation starts in
        // Reading — so this is the reading model, not the workspace default.
        $this->assertSame((string) config('ai.chat.modes.reading.model'), $session->model);
        $this->assertSame(AiCapabilityMode::Operator, $session->capability_mode);
        $this->assertSame(AiChatMode::Reading, $session->chat_mode);
    }

    /**
     * The snapshot is what makes an old session interpretable after the
     * workspace default changes.
     */
    public function test_an_old_session_keeps_its_model_when_the_default_changes(): void
    {
        $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'A question.')
            ->call('send');

        $session = AiSession::query()->sole();
        $originalModel = $session->model;

        app(UpdateGlobalAiSettings::class)->handle(['model' => 'claude-haiku-4-5']);

        $this->assertSame($originalModel, $session->refresh()->model);
    }

    /**
     * The model is not chosen directly any more. It follows from what the
     * conversation is set to do, which is the whole point of the setting: a
     * writing turn has to land on the deep-reasoning model whether or not
     * anybody remembered to switch a second dropdown.
     */
    public function test_switching_to_writing_switches_the_model(): void
    {
        $fake = $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        $this->aiMode(AiCapabilityMode::Operator);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->call('selectChatMode', AiChatMode::Writing->value)
            ->assertSet('chatMode', AiChatMode::Writing->value)
            ->set('draft', 'Draft a ticket for the login bug.')
            ->call('send')
            ->assertHasNoErrors();

        $session = AiSession::query()->sole();

        $this->assertSame(AiChatMode::Writing, $session->chat_mode);
        $this->assertSame((string) config('ai.chat.modes.writing.model'), $session->model);
        // And it is the model the provider was actually asked for.
        $this->assertSame((string) config('ai.chat.modes.writing.model'), $fake->lastPrompt()->model);
    }

    /**
     * The dropdown is browser-supplied, so a value the enum does not know must
     * not reach the session — and it narrows rather than erroring.
     */
    public function test_a_chat_mode_outside_the_enum_falls_back_to_reading(): void
    {
        $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->call('selectChatMode', 'root')
            ->assertSet('chatMode', AiChatMode::Reading->value);

        $this->assertSame(AiChatMode::Reading, AiSession::query()->sole()->chat_mode);
    }

    /**
     * A mode may name a model the effective provider does not serve — a
     * deployment that configured writing for Anthropic and then switched the
     * workspace to OpenAI. The conversation falls back to a model that can
     * answer rather than sending an id the vendor would reject.
     */
    public function test_a_mode_model_the_provider_does_not_serve_falls_back(): void
    {
        $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        $openAiModel = array_key_first(AiModelCatalogue::forProvider(AiProvider::OpenAi));

        if ($openAiModel === null) {
            $this->markTestSkipped('This deployment lists no OpenAI models.');
        }

        // The workspace runs on Anthropic; reading is pointed at an OpenAI id.
        config(['ai.chat.modes.reading.model' => $openAiModel]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->call('selectChatMode', AiChatMode::Reading->value);

        $this->assertNotSame($openAiModel, AiSession::query()->sole()->model);
    }

    // -----------------------------------------------------------------
    // Clearing
    // -----------------------------------------------------------------

    /**
     * Clearing removes the turns and keeps the session and its ledger: what was
     * spent cannot be un-spent from a screen, and the session list would
     * otherwise develop holes.
     */
    public function test_clearing_a_conversation_keeps_the_session_and_its_usage(): void
    {
        $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'A question.')
            ->call('send')
            ->call('clearHistory')
            ->assertHasNoErrors();

        $this->assertSame(1, AiSession::query()->count());
        $this->assertSame(0, AiChatMessage::query()->count());
        $this->assertSame(1, AiUsageRecord::query()->count());
    }

    public function test_nothing_in_the_product_deletes_a_session(): void
    {
        $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        $component = Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug);

        foreach (['One.', 'Two.', 'Three.'] as $question) {
            $component->set('draft', $question)->call('send')->call('startNewSession');
        }

        // Three conversations, three sessions, none removed.
        $this->assertSame(4, AiSession::query()->count());
        $this->assertSame(3, AiSession::query()->whereNotNull('ended_at')->count());
    }
}
