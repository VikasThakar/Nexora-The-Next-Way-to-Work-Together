<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Enums\AiActionType;
use App\Enums\AiCapabilityMode;
use App\Enums\AiChatRole;
use App\Enums\TicketPriority;
use App\Livewire\Ai\Chat;
use App\Models\AiChatMessage;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * When a change happens without being confirmed, and when it never does.
 *
 * The brief asks for two things that pull in opposite directions: reversible
 * operations should run when somebody explicitly asked for them (§15, §16), and
 * destructive ones must wait for explicit confirmation (§15). Both are settled
 * by one predicate — ExecuteChatAction::executesWithoutConfirmation() — and this
 * suite is the pair of tests that pins it down from both sides.
 *
 * The dial is the existing capability mode rather than a new setting:
 *
 *   AI Operator  every change is a proposal with a Confirm button. This is the
 *                behaviour the product had before, and a workspace that has
 *                tightened to Operator keeps it exactly.
 *   AI Agent     a reversible change somebody asked for is carried out. A
 *                deletion still is not, in any mode.
 *
 * What is deliberately NOT tested here is whether the write was allowed —
 * that is the same code path either way, and it has its own suites. These tests
 * are only about *when* it runs.
 */
class AiUnattendedWriteTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Reversible changes, under AI Agent
    // -----------------------------------------------------------------

    public function test_under_ai_agent_a_requested_ticket_is_created_without_a_confirmation_step(): void
    {
        $this->aiMode(AiCapabilityMode::Agent);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        $this->fakeAiProvider()->willPropose(AiActionType::CreateTicket->toolName(), [
            'title' => 'I spotted a bug in the system',
            'priority' => TicketPriority::Critical->value,
        ]);

        Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', "Create a critical ticket called 'I spotted a bug in the system'")
            ->call('send');

        $ticket = Ticket::query()->sole();

        $this->assertSame('I spotted a bug in the system', $ticket->title);
        $this->assertSame(TicketPriority::Critical, $ticket->priority);
        $this->assertSame($admin->id, $ticket->created_by_id);

        // No confirm button left behind: the turn records what was done.
        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();

        $this->assertFalse($message->awaitsConfirmation());
        $this->assertSame(AiChatMessage::ACTION_CONFIRMED, $message->actionState());
        $this->assertStringContainsString(
            $ticket->key(),
            (string) ($message->action()['result']['label'] ?? '')
        );
    }

    public function test_under_ai_operator_the_same_request_waits_to_be_confirmed(): void
    {
        $this->aiMode(AiCapabilityMode::Operator);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        $this->fakeAiProvider()->willPropose(AiActionType::CreateTicket->toolName(), [
            'title' => 'I spotted a bug in the system',
        ]);

        Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Create a ticket')
            ->call('send');

        // Same words, same tool call, nothing written. The mode is the only
        // difference between this test and the one above it.
        $this->assertSame(0, Ticket::query()->count());
        $this->assertTrue(
            AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole()->awaitsConfirmation()
        );
    }

    /**
     * §2: a spoken request must use the same pipeline as a typed one.
     *
     * Which is why this test asserts on the database rather than on a
     * transcription: sendSpoken() puts the words into the same draft and calls
     * the same send(), so the change happening is the evidence that voice
     * inherits the write path rather than having one of its own.
     */
    public function test_a_spoken_request_takes_the_same_write_path_as_a_typed_one(): void
    {
        $this->aiMode(AiCapabilityMode::Agent);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $ticket = $this->ticketOn($board, $admin);

        $this->fakeAiProvider()->willPropose(AiActionType::UpdateTicket->toolName(), [
            'number' => $ticket->number,
            'column' => 'Done',
        ]);

        Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->call('sendSpoken', 'Move '.$ticket->key().' to Done');

        $this->assertSame('Done', $ticket->refresh()->column->name);
    }

    // -----------------------------------------------------------------
    // Deletion: never unattended, in any mode
    // -----------------------------------------------------------------

    public function test_a_deletion_is_never_carried_out_unattended_even_under_ai_agent(): void
    {
        $this->aiMode(AiCapabilityMode::Agent);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $ticket = $this->ticketOn($board, $admin);

        $this->fakeAiProvider()->willPropose(AiActionType::DeleteTicket->toolName(), [
            'number' => $ticket->number,
        ]);

        Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Delete '.$ticket->key())
            ->call('send');

        // The one action isDestructive() answers true for, so the most
        // permissive mode in the product still does not carry it out.
        $this->assertModelExists($ticket);

        $this->assertTrue(
            AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole()->awaitsConfirmation()
        );
    }

    public function test_a_deletion_needs_the_tickets_key_typed_and_a_wrong_one_changes_nothing(): void
    {
        $this->aiMode(AiCapabilityMode::Agent);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $ticket = $this->ticketOn($board, $admin);

        $this->fakeAiProvider()->willPropose(AiActionType::DeleteTicket->toolName(), [
            'number' => $ticket->number,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Delete '.$ticket->key())
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();

        $component->call('startConfirming', $message->id);

        // The box says which key to type, read from the resolved ticket rather
        // than from the model's arguments.
        $this->assertSame($ticket->key(), $component->instance()->pendingDeletionKey());

        // Pressing the button with nothing typed does nothing. "Yes", "go
        // ahead" and every other phrase are equally not a confirmation — this
        // is the empty case that stands for all of them.
        $component->call('confirm');

        $this->assertModelExists($ticket);
        $component->assertSet('aiError', fn (?string $error): bool => is_string($error)
            && str_contains($error, 'Type '.$ticket->key()));

        // A near miss is still a miss.
        $component->set('deleteConfirmation', 'delete')->call('confirm');

        $this->assertModelExists($ticket);
        $this->assertTrue($message->refresh()->awaitsConfirmation());
    }

    public function test_a_deletion_with_the_key_typed_is_carried_out(): void
    {
        $this->aiMode(AiCapabilityMode::Agent);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $ticket = $this->ticketOn($board, $admin, ['title' => 'Raised by mistake']);
        $key = $ticket->key();

        $this->fakeAiProvider()->willPropose(AiActionType::DeleteTicket->toolName(), [
            'number' => $ticket->number,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Delete '.$key.' permanently')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();

        $component
            ->call('startConfirming', $message->id)
            ->set('deleteConfirmation', $key)
            ->call('confirm');

        $this->assertModelMissing($ticket);

        $label = (string) ($message->refresh()->action()['result']['label'] ?? '');
        $this->assertStringContainsString($key, $label);
        $this->assertStringContainsString('permanently', $label);

        // And the deletion is in the AI ledger, naming who caused it.
        $this->assertDatabaseHas('ai_tool_invocations', [
            'tool' => AiActionType::DeleteTicket->toolName(),
            'category' => 'action',
            'outcome' => 'ok',
            'user_id' => $admin->id,
            'target' => $key,
        ]);
    }

    /**
     * Case does not matter; the key does.
     */
    public function test_the_typed_key_is_matched_case_insensitively(): void
    {
        $this->aiMode(AiCapabilityMode::Agent);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $ticket = $this->ticketOn($board, $admin);

        $this->fakeAiProvider()->willPropose(AiActionType::DeleteTicket->toolName(), [
            'number' => $ticket->number,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Delete it')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();

        $component
            ->call('startConfirming', $message->id)
            ->set('deleteConfirmation', strtolower($ticket->key()))
            ->call('confirm');

        $this->assertModelMissing($ticket);
    }
}
