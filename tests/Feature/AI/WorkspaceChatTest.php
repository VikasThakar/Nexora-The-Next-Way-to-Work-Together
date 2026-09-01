<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Enums\AiActionType;
use App\Enums\AiChatRole;
use App\Livewire\Ai\Chat;
use App\Models\AiChatMessage;
use App\Models\DocPage;
use App\Models\Ticket;
use App\Services\AI\Exceptions\AiProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The team-only workspace chat.
 *
 * Two things are being pinned here: that conversation history persists per
 * board, and — much more importantly — that an action the model proposes is
 * never a write until a person confirms it.
 */
class WorkspaceChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_team_member_can_ask_a_question_and_both_turns_persist(): void
    {
        $provider = $this->fakeAiProvider();
        $provider->willReturn('Three tickets are in progress.');

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'What is in progress?')
            ->call('send')
            ->assertHasNoErrors();

        $messages = AiChatMessage::query()->ordered()->get();

        $this->assertCount(2, $messages);
        $this->assertSame(AiChatRole::User, $messages[0]->role);
        $this->assertSame('What is in progress?', $messages[0]->content);
        $this->assertSame($team->id, $messages[0]->user_id);
        $this->assertSame(AiChatRole::Assistant, $messages[1]->role);
        $this->assertStringContainsString('Three tickets', $messages[1]->content);
    }

    public function test_history_persists_per_board_and_is_replayed(): void
    {
        $provider = $this->fakeAiProvider();

        $team = $this->teamMember();
        $boardOne = $this->boardWithColumns([$team]);
        $boardTwo = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Chat::class, ['board' => $boardOne])
            ->set('draft', 'First board question')
            ->call('send');

        Livewire::actingAs($team)
            ->test(Chat::class, ['board' => $boardOne])
            ->set('draft', 'Follow-up on the same board')
            ->call('send');

        // The second question replays the first exchange as history.
        $payload = $provider->lastPayload();
        $this->assertStringContainsString('First board question', $payload);

        // And board two starts from nothing.
        Livewire::actingAs($team)
            ->test(Chat::class, ['board' => $boardTwo])
            ->set('draft', 'Different board')
            ->call('send');

        $this->assertStringNotContainsString('First board question', $provider->lastPayload());
        $this->assertSame(4, $boardOne->aiChatMessages()->count());
        $this->assertSame(2, $boardTwo->aiChatMessages()->count());
    }

    public function test_the_users_question_is_kept_even_when_the_provider_fails(): void
    {
        $provider = $this->fakeAiProvider();
        $provider->willFail(AiProviderException::overloaded());

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Did this survive?')
            ->call('send');

        // A gap in the transcript would be worse than a visible failure.
        $this->assertSame(1, AiChatMessage::query()->count());
        $this->assertSame('Did this survive?', AiChatMessage::query()->sole()->content);
    }

    // -----------------------------------------------------------------
    // Proposed actions
    // -----------------------------------------------------------------

    public function test_a_proposed_ticket_is_not_created_until_it_is_confirmed(): void
    {
        $provider = $this->fakeAiProvider();
        $provider->willPropose(AiActionType::CreateTicket->toolName(), [
            'title' => 'Add VAT column to the export',
            'description_md' => 'The finance export is missing it.',
        ]);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $component = Livewire::actingAs($team)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Raise a ticket for the missing VAT column')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();

        // Proposed, previewed — and nothing written.
        $this->assertTrue($message->awaitsConfirmation());
        $this->assertSame(AiActionType::CreateTicket, $message->actionType());
        $this->assertSame(0, Ticket::query()->count());

        $component->call('startConfirming', $message->id)->call('confirm');

        $ticket = Ticket::query()->sole();

        $this->assertSame('Add VAT column to the export', $ticket->title);
        $this->assertSame($board->id, $ticket->board_id);
        $this->assertSame($team->id, $ticket->created_by_id);

        $this->assertSame(AiChatMessage::ACTION_CONFIRMED, $message->refresh()->actionState());
    }

    public function test_a_ticket_created_from_the_chat_is_internal_by_default(): void
    {
        $provider = $this->fakeAiProvider();
        $provider->willPropose(AiActionType::CreateTicket->toolName(), ['title' => 'Internal work']);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $component = Livewire::actingAs($team)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Draft a ticket')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();

        $component->call('startConfirming', $message->id)->call('confirm');

        // A chat proposal must never be the thing that publishes work to a
        // customer: CreateTicket defaults staff-created tickets to internal and
        // the executor deliberately does not pass a visibility.
        $this->assertFalse((bool) Ticket::query()->sole()->customer_visible);
    }

    public function test_a_discarded_proposal_writes_nothing(): void
    {
        $provider = $this->fakeAiProvider();
        $provider->willPropose(AiActionType::CreateTicket->toolName(), ['title' => 'Never created']);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $component = Livewire::actingAs($team)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Draft something')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();

        $component->call('discard', $message->id);

        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(AiChatMessage::ACTION_DISCARDED, $message->refresh()->actionState());
    }

    public function test_a_proposal_cannot_be_confirmed_twice(): void
    {
        $provider = $this->fakeAiProvider();
        $provider->willPropose(AiActionType::CreateTicket->toolName(), ['title' => 'Only once']);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $component = Livewire::actingAs($team)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Draft it')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();

        $component->call('startConfirming', $message->id)->call('confirm');
        $component->call('startConfirming', $message->id)->call('confirm');

        $this->assertSame(1, Ticket::query()->count());
    }

    public function test_a_proposal_to_update_a_ticket_the_user_cannot_see_is_refused(): void
    {
        $provider = $this->fakeAiProvider();

        $team = $this->teamMember();
        $otherTeam = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $elsewhere = $this->boardWithColumns([$otherTeam]);

        // A ticket that exists, but on a board the asker is not a member of.
        $foreign = $this->ticketOn($elsewhere, $otherTeam, ['title' => 'Not yours']);

        $provider->willPropose(AiActionType::UpdateTicket->toolName(), [
            'number' => $foreign->number,
            'title' => 'Renamed by proxy',
        ]);

        $component = Livewire::actingAs($team)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Rename that ticket')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();

        // Resolved by number *within this board*, through TicketFinder with the
        // confirming user as viewer, so a number that exists elsewhere resolves
        // to nothing.
        $component->call('startConfirming', $message->id)->call('confirm');

        $this->assertSame('Not yours', $foreign->refresh()->title);
        $this->assertSame(AiChatMessage::ACTION_FAILED, $message->refresh()->actionState());
    }

    public function test_a_proposed_documentation_page_is_internal_until_published(): void
    {
        $provider = $this->fakeAiProvider();
        $provider->willPropose(AiActionType::CreateDocPage->toolName(), [
            'title' => 'Deployment runbook',
            'body_md' => 'Steps.',
        ]);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $component = Livewire::actingAs($team)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Write a runbook')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();
        $component->call('startConfirming', $message->id)->call('confirm');

        $page = DocPage::query()->sole();

        // CreatePage owns that rule; the chat has no way to reach publishing.
        $this->assertFalse((bool) $page->customer_visible);
        $this->assertSame('Deployment runbook', $page->title);
    }

    public function test_an_unknown_tool_name_is_not_stored_as_a_proposal(): void
    {
        $provider = $this->fakeAiProvider();
        $provider->willPropose('propose_delete_everything', ['confirm' => true]);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Do something drastic')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();

        // No preview, no executor, no confirm button that cannot work.
        $this->assertNull($message->actionType());
        $this->assertFalse($message->awaitsConfirmation());
    }

    public function test_only_someone_who_can_configure_the_board_may_clear_the_shared_transcript(): void
    {
        $provider = $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Something')
            ->call('send');

        $this->assertSame(2, AiChatMessage::query()->count());

        // A staff member of the board may configure it, so may clear it.
        Livewire::actingAs($team)
            ->test(Chat::class, ['board' => $board])
            ->call('clearHistory');

        $this->assertSame(0, AiChatMessage::query()->count());
    }
}
