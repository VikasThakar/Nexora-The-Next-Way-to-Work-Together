<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Enums\AiActionType;
use App\Enums\AiCapabilityMode;
use App\Enums\AiChatMode;
use App\Enums\AiChatRole;
use App\Enums\TicketPriority;
use App\Livewire\Ai\Assistant;
use App\Livewire\Ai\Chat;
use App\Models\AiChatMessage;
use App\Models\AiToolInvocation;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Break this plan into tickets": many tickets from one request.
 *
 * The assistant used to answer one ticket per reply, because one proposal per
 * turn was the rule and the prompt said so. Several new tickets are now one
 * proposal — one preview listing all of them, one confirmation, all or none —
 * and ticket creations the model happens to split across several calls in a
 * turn are merged into it. Everything else about authorization is unchanged:
 * each ticket goes through exactly what a single create goes through.
 */
class AiBulkTicketCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_under_operator_a_batch_is_one_proposal_then_every_ticket_in_order(): void
    {
        $this->aiMode(AiCapabilityMode::Operator);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        $this->fakeAiProvider()->willPropose(AiActionType::CreateTickets->toolName(), ['tickets' => [
            ['title' => 'Set up CI', 'description_md' => 'GitHub Actions for tests.'],
            ['title' => 'Design the schema', 'priority' => TicketPriority::High->value, 'column' => 'In Progress'],
            ['title' => 'Rotate the staging key', 'customer_visible' => false],
        ]]);

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Make the tickets from the plan')
            ->call('send')
            ->assertSee('Create 3 tickets')
            ->assertSee('Set up CI')
            ->assertSee('Design the schema')
            ->assertSee('Rotate the staging key');

        // Nothing is written by proposing.
        $this->assertSame(0, Ticket::query()->count());

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();
        $this->assertSame(AiActionType::CreateTickets, $message->actionType());

        $component->call('startConfirming', $message->id)->call('confirm')->assertHasNoErrors();

        $tickets = Ticket::query()->with(['board', 'column'])->orderBy('number')->get();

        $this->assertSame(['Set up CI', 'Design the schema', 'Rotate the staging key'], $tickets->pluck('title')->all());
        $this->assertSame([1, 2, 3], $tickets->pluck('number')->all());
        $this->assertSame(TicketPriority::High, $tickets[1]->priority);
        $this->assertSame('In Progress', $tickets[1]->column->name);
        $this->assertTrue((bool) $tickets[0]->customer_visible);
        $this->assertFalse((bool) $tickets[2]->customer_visible);
        $this->assertSame($admin->id, $tickets[0]->created_by_id);

        $message->refresh();
        $this->assertSame(AiChatMessage::ACTION_CONFIRMED, $message->actionState());
        $this->assertSame($tickets->map->key()->all(), array_column($message->action()['result']['items'], 'key'));
        $this->assertStringContainsString('Created 3 tickets', $message->action()['result']['label']);

        // One request, one audit row.
        $this->assertSame(1, AiToolInvocation::query()->where('tool', 'propose_create_tickets')->count());

        $component->assertSee($tickets[0]->key())->assertSee($tickets[2]->key());
    }

    public function test_under_ai_agent_the_batch_is_created_straight_away(): void
    {
        $this->aiMode(AiCapabilityMode::Agent);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        $this->fakeAiProvider()->willPropose(AiActionType::CreateTickets->toolName(), ['tickets' => [
            ['title' => 'One'], ['title' => 'Two'], ['title' => 'Three'], ['title' => 'Four'],
        ]]);

        Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Make four tickets')
            ->call('send');

        $this->assertSame(4, Ticket::query()->count());
        $this->assertSame(
            AiChatMessage::ACTION_CONFIRMED,
            AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole()->actionState(),
        );
    }

    /**
     * A model that answers "make the tickets" with several single-ticket calls
     * in one turn gets all of them, as one batch — not the first and silence.
     */
    public function test_several_single_ticket_calls_in_one_turn_become_one_batch(): void
    {
        $this->aiMode(AiCapabilityMode::Operator);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        $create = AiActionType::CreateTicket->toolName();

        $this->fakeAiProvider()->willProposeMany([
            [$create, ['title' => 'First']],
            [$create, ['title' => 'Second', 'priority' => TicketPriority::Low->value]],
            [AiActionType::CreateTickets->toolName(), ['tickets' => [['title' => 'Third'], ['title' => 'Fourth']]]],
        ]);

        Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Make the tickets')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();

        $this->assertSame(AiActionType::CreateTickets, $message->actionType());
        $this->assertSame(['First', 'Second', 'Third', 'Fourth'], array_column($message->actionInput()['tickets'], 'title'));
    }

    /**
     * A round holding a lookup and a proposal used to keep `toolCalls[0]` —
     * the lookup — and lose the proposal.
     */
    public function test_a_proposal_after_a_lookup_in_the_same_round_is_kept(): void
    {
        $this->aiMode(AiCapabilityMode::Operator);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        $this->fakeAiProvider()->willProposeMany([
            ['search_tickets', ['query' => 'login']],
            [AiActionType::CreateTicket->toolName(), ['title' => 'Fix the login page']],
        ]);

        Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Raise a ticket to fix the login page')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();

        $this->assertSame(AiActionType::CreateTicket, $message->actionType());
        $this->assertSame('Fix the login page', $message->actionInput()['title']);
    }

    /**
     * All or none: every ticket is authorized and resolved before any is
     * written, so one bad entry means no tickets and a message naming it.
     */
    public function test_one_bad_ticket_means_none_are_created_and_says_which(): void
    {
        $this->aiMode(AiCapabilityMode::Agent);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        $this->fakeAiProvider()->willPropose(AiActionType::CreateTickets->toolName(), ['tickets' => [
            ['title' => 'Fine'],
            ['title' => 'Also fine'],
            ['title' => 'Wrong column', 'column' => 'QA Lab'],
            ['title' => 'Never reached'],
        ]]);

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Make the tickets')
            ->call('send');

        $this->assertSame(0, Ticket::query()->count());

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();
        $error = (string) $message->action()['error'];

        $this->assertSame(AiChatMessage::ACTION_FAILED, $message->actionState());
        $this->assertStringContainsString('Ticket 3 ("Wrong column")', $error);
        // Said once: in the resolver's own words when it has them, otherwise
        // the batch's.
        $this->assertMatchesRegularExpression('/Nothing was (changed|created)\./', $error);
        $this->assertSame(1, preg_match_all('/Nothing was/', $error));
        $component->assertSet('aiError', $error);
    }

    public function test_an_assignee_who_is_not_on_the_board_refuses_the_whole_batch(): void
    {
        $this->aiMode(AiCapabilityMode::Agent);

        $admin = $this->admin();
        $outsider = $this->teamMember(['name' => 'Olive Outsider']);
        $board = $this->boardWithColumns([$admin]);

        $this->fakeAiProvider()->willPropose(AiActionType::CreateTickets->toolName(), ['tickets' => [
            ['title' => 'Mine', 'assignee' => 'me'],
            ['title' => 'Theirs', 'assignee' => $outsider->name],
        ]]);

        Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Make the tickets')
            ->call('send');

        $this->assertSame(0, Ticket::query()->count());
        $this->assertStringContainsString(
            'Ticket 2 ("Theirs")',
            (string) AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole()->action()['error'],
        );
    }

    public function test_more_than_the_per_reply_maximum_keeps_the_first_and_says_so(): void
    {
        config(['ai.chat.max_tickets_per_reply' => 3]);
        $this->aiMode(AiCapabilityMode::Operator);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        $this->fakeAiProvider()->willPropose(AiActionType::CreateTickets->toolName(), ['tickets' => [
            ['title' => 'A'], ['title' => 'B'], ['title' => 'C'], ['title' => 'D'], ['title' => 'E'],
        ]]);

        Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Make five tickets')
            ->call('send')
            ->assertSee('Only the first 3 tickets fit in one request; 2 more were left out.');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();

        $this->assertSame(['A', 'B', 'C'], array_column($message->actionInput()['tickets'], 'title'));
    }

    /**
     * One board per batch, because one board is what the confirmation is
     * authorized against. Tickets addressed elsewhere are left out, out loud.
     */
    public function test_tickets_for_another_board_are_left_out_of_the_batch(): void
    {
        $this->aiMode(AiCapabilityMode::Operator);

        $admin = $this->admin();
        $alpha = $this->boardWithColumns([$admin], ['name' => 'Alpha', 'ticket_prefix' => 'AL']);
        $this->boardWithColumns([$admin], ['name' => 'Beta', 'ticket_prefix' => 'BE']);

        $create = AiActionType::CreateTicket->toolName();

        $this->fakeAiProvider()->willProposeMany([
            [$create, ['title' => 'For Alpha', 'board' => 'Alpha']],
            [$create, ['title' => 'Also Alpha', 'board' => 'alpha']],
            [$create, ['title' => 'For Beta', 'board' => 'Beta']],
        ]);

        Livewire::actingAs($admin)
            ->test(Assistant::class)
            ->call('reveal')
            ->set('draft', 'Make the tickets')
            ->call('send')
            ->assertSee('1 ticket for another board was left out.');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();

        $this->assertSame('Alpha', $message->actionInput()['board']);
        $this->assertSame(['For Alpha', 'Also Alpha'], array_column($message->actionInput()['tickets'], 'title'));
        $this->assertNull($alpha->tickets()->first());
    }

    public function test_a_batch_cannot_be_confirmed_twice(): void
    {
        $this->aiMode(AiCapabilityMode::Operator);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        $this->fakeAiProvider()->willPropose(AiActionType::CreateTickets->toolName(), ['tickets' => [
            ['title' => 'Once'], ['title' => 'Only once'],
        ]]);

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Make the tickets')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();

        $component->call('startConfirming', $message->id)->call('confirm');
        $component->call('startConfirming', $message->id)->call('confirm');

        $this->assertSame(2, Ticket::query()->count());
    }

    /**
     * A reply cut off by the output ceiling usually ends inside the tool call,
     * whose arguments then arrive empty. That is a failure said plainly, not a
     * Confirm button for nothing.
     */
    public function test_a_reply_cut_off_mid_list_proposes_nothing_and_says_why(): void
    {
        $this->aiMode(AiCapabilityMode::Agent);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        $provider = $this->fakeAiProvider();
        $provider->stopReason = 'max_tokens';
        $provider->text = '';
        $provider->willProposeMany([[AiActionType::CreateTickets->toolName(), []]]);

        Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Make sixty tickets')
            ->call('send')
            ->assertSee('The reply was cut off before the request was complete');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();

        $this->assertSame(AiChatMessage::ACTION_FAILED, $message->actionState());
        $this->assertSame(0, Ticket::query()->count());
    }

    /**
     * The model is told what really happened, so "continue" picks up after the
     * tickets that exist rather than repeating them.
     */
    public function test_the_next_question_carries_what_was_created(): void
    {
        $this->aiMode(AiCapabilityMode::Agent);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        $provider = $this->fakeAiProvider();
        $provider->willPropose(AiActionType::CreateTickets->toolName(), ['tickets' => [
            ['title' => 'Set up CI'], ['title' => 'Write the README'],
        ]]);

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Make the first tickets')
            ->call('send');

        $provider->toolCalls = [];

        $component->set('draft', 'Continue with the rest')->call('send');

        $keys = Ticket::query()->with('board')->orderBy('number')->get()->map->key()->all();
        $payload = $provider->lastPayload();

        $this->assertStringContainsString('EARLIER CHANGES IN THIS CONVERSATION', $payload);
        $this->assertStringContainsString($keys[0].' Set up CI', $payload);
        $this->assertStringContainsString($keys[1].' Write the README', $payload);
    }

    public function test_the_prompt_asks_for_every_ticket_in_one_call(): void
    {
        $this->aiMode(AiCapabilityMode::Operator);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        $provider = $this->fakeAiProvider();

        Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->call('selectChatMode', AiChatMode::Everything->value)
            ->set('draft', 'Break the plan into tickets')
            ->call('send');

        $prompt = $provider->lastPrompt();

        $this->assertStringContainsString('MANY TICKETS', $prompt->system);
        $this->assertStringContainsString('propose_create_tickets', $prompt->system);
        $this->assertStringNotContainsString('at most one per reply;', $prompt->system);
        $this->assertContains('propose_create_tickets', array_map(fn ($tool) => $tool->name, $prompt->tools));
        $this->assertGreaterThanOrEqual(16000, $prompt->maxOutputTokens);
    }
}
