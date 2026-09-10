<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Actions\AI\ExecuteChatAction;
use App\Enums\AiActionType;
use App\Enums\AiCapabilityMode;
use App\Enums\AiChatRole;
use App\Enums\TicketPriority;
use App\Models\AiChatMessage;
use App\Models\Board;
use App\Models\Label;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The write boundary: what the assistant may change, for whom.
 *
 * Every test here goes straight at App\Actions\AI\ExecuteChatAction with a
 * proposal *forged in the database*, bypassing the model, the tool schema, the
 * prompt and the capability guard's decision about which tools to offer. That
 * is the point. The brief's §25 asks for proof that "an AI-generated request
 * cannot bypass Laravel authorization" — so these tests simulate the worst
 * case, which is not a badly behaved model but an attacker who has managed to
 * put an arbitrary action into a turn:
 *
 *   {"action": "delete_ticket", "number": 18}
 *
 * and then presses Confirm. Nothing about that request is trusted. The ticket
 * is re-resolved within the board through the reader that applies visibility,
 * and every field is authorized against the person confirming, with the
 * ordinary policy the ordinary screens use.
 *
 * The mode is AI Agent throughout — the most permissive setting in the product
 * — because the claim being tested is that raising the mode grants nobody
 * anything. If a customer could delete a ticket anywhere, it would be here.
 */
class AiWriteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aiMode(AiCapabilityMode::Agent);
    }

    /**
     * A proposal placed directly into a turn, as an attacker would need to.
     *
     * @param  array<string, mixed>  $input
     */
    private function forge(Board $board, User $owner, AiActionType $type, array $input): AiChatMessage
    {
        $message = new AiChatMessage;
        $message->board_id = $board->getKey();
        $message->user_id = $owner->getKey();
        $message->role = AiChatRole::Assistant;
        $message->content = 'Prepared a change.';
        $message->metadata = ['action' => [
            'type' => $type->value,
            'input' => $input,
            'state' => AiChatMessage::ACTION_PROPOSED,
        ]];
        $message->save();

        return $message;
    }

    // -----------------------------------------------------------------
    // Customers: read-only, whatever the request says
    // -----------------------------------------------------------------

    public function test_a_customer_cannot_create_a_ticket_through_the_assistant(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        $message = $this->forge($board, $customer, AiActionType::CreateTicket, [
            'title' => 'Payment failed',
        ]);

        // Refused by the capability guard's customer boundary, which is stated
        // as its own condition rather than left to a policy further down.
        $this->expectException(AuthorizationException::class);

        try {
            app(ExecuteChatAction::class)->handle($board, $message, $customer);
        } finally {
            $this->assertSame(0, Ticket::query()->count());
        }
    }

    public function test_a_customer_cannot_move_a_ticket_through_the_assistant(): void
    {
        $customer = $this->customer();
        $staff = $this->teamMember();
        $board = $this->boardWithColumns([$customer, $staff]);

        $ticket = $this->ticketOn($board, $staff, ['customer_visible' => true]);

        $message = $this->forge($board, $customer, AiActionType::UpdateTicket, [
            'number' => $ticket->number,
            'column' => 'Done',
        ]);

        $this->expectException(AuthorizationException::class);

        try {
            app(ExecuteChatAction::class)->handle($board, $message, $customer);
        } finally {
            $this->assertSame('Backlog', $ticket->refresh()->column->name);
        }
    }

    public function test_a_customer_cannot_assign_a_ticket_through_the_assistant(): void
    {
        $customer = $this->customer();
        $staff = $this->teamMember(['name' => 'Alex Round']);
        $board = $this->boardWithColumns([$customer, $staff]);

        $ticket = $this->ticketOn($board, $staff, ['customer_visible' => true]);

        $message = $this->forge($board, $customer, AiActionType::UpdateTicket, [
            'number' => $ticket->number,
            'assignee' => 'Alex Round',
        ]);

        $this->expectException(AuthorizationException::class);

        try {
            app(ExecuteChatAction::class)->handle($board, $message, $customer);
        } finally {
            $this->assertNull($ticket->refresh()->assignee_id);
        }
    }

    public function test_a_customer_cannot_delete_a_ticket_through_the_assistant(): void
    {
        $customer = $this->customer();
        $staff = $this->teamMember();
        $board = $this->boardWithColumns([$customer, $staff]);

        $ticket = $this->ticketOn($board, $staff, ['customer_visible' => true]);

        // The literal case §25 names: the model produces "action = delete_ticket"
        // and the backend rejects it because this person may not delete.
        $message = $this->forge($board, $customer, AiActionType::DeleteTicket, [
            'number' => $ticket->number,
        ]);

        $this->expectException(AuthorizationException::class);

        try {
            app(ExecuteChatAction::class)->handle($board, $message, $customer);
        } finally {
            $this->assertModelExists($ticket);
        }
    }

    public function test_a_customer_cannot_change_labels_through_the_assistant(): void
    {
        $customer = $this->customer();
        $staff = $this->teamMember();
        $board = $this->boardWithColumns([$customer, $staff]);

        $ticket = $this->ticketOn($board, $staff, ['customer_visible' => true]);
        Label::factory()->create(['board_id' => $board->id, 'name' => 'urgent']);

        $message = $this->forge($board, $customer, AiActionType::UpdateTicket, [
            'number' => $ticket->number,
            'labels' => ['urgent'],
        ]);

        $this->expectException(AuthorizationException::class);

        try {
            app(ExecuteChatAction::class)->handle($board, $message, $customer);
        } finally {
            $this->assertCount(0, $ticket->refresh()->labels);
        }
    }

    /**
     * A customer cannot reach an internal ticket even to read it, so a forged
     * change naming one resolves to nothing rather than being refused — the
     * same answer they get everywhere else in the product, and for the same
     * reason: "not allowed" would confirm the ticket exists.
     */
    public function test_a_forged_change_naming_an_internal_ticket_does_not_reveal_it(): void
    {
        $customer = $this->customer();
        $staff = $this->teamMember();
        $board = $this->boardWithColumns([$customer, $staff]);

        $internal = $this->ticketOn($board, $staff, [
            'title' => 'Internal only',
            'customer_visible' => false,
        ]);

        $message = $this->forge($board, $customer, AiActionType::UpdateTicket, [
            'number' => $internal->number,
            'title' => 'Renamed by a customer',
        ]);

        try {
            app(ExecuteChatAction::class)->handle($board, $message, $customer);
            $this->fail('A customer changed an internal ticket.');
        } catch (AuthorizationException|\RuntimeException) {
            // Either answer is acceptable; the ticket being untouched is not
            // negotiable.
        }

        $this->assertSame('Internal only', $internal->refresh()->title);
    }

    // -----------------------------------------------------------------
    // Team members: exactly what they already have
    // -----------------------------------------------------------------

    public function test_a_team_member_may_do_what_the_ordinary_screens_let_them_do(): void
    {
        $team = $this->teamMember();
        $alex = $this->teamMember(['name' => 'Alex Round']);
        $board = $this->boardWithColumns([$team, $alex]);
        $ticket = $this->ticketOn($board, $team);

        $message = $this->forge($board, $team, AiActionType::UpdateTicket, [
            'number' => $ticket->number,
            'assignee' => 'Alex Round',
            'column' => 'Review',
            'priority' => TicketPriority::High->value,
        ]);

        app(ExecuteChatAction::class)->handle($board, $message, $team);

        $ticket->refresh();

        // A team member on the board is staff, so TicketPolicy::assign, ::move
        // and ::update all allow it — exactly as they would from the board UI.
        $this->assertSame($alex->id, $ticket->assignee_id);
        $this->assertSame('Review', $ticket->column->name);
        $this->assertSame(TicketPriority::High, $ticket->priority);
    }

    public function test_a_team_member_cannot_touch_a_board_they_are_not_on(): void
    {
        $outsider = $this->teamMember();
        $insider = $this->teamMember();
        $board = $this->boardWithColumns([$insider]);
        $ticket = $this->ticketOn($board, $insider, ['title' => 'Not yours']);

        $message = $this->forge($board, $outsider, AiActionType::UpdateTicket, [
            'number' => $ticket->number,
            'title' => 'Renamed by an outsider',
        ]);

        try {
            app(ExecuteChatAction::class)->handle($board, $message, $outsider);
            $this->fail('A non-member changed a ticket.');
        } catch (AuthorizationException|\RuntimeException) {
            // Board membership is what the policy reads, and it is missing.
        }

        $this->assertSame('Not yours', $ticket->refresh()->title);
    }

    // -----------------------------------------------------------------
    // Nothing partial
    // -----------------------------------------------------------------

    /**
     * A change that is half-permitted is not half-applied.
     *
     * The customer here may update the ticket they raised — TicketPolicy::update
     * allows it — but may not move it. So the title change is permitted and the
     * move is not, and the correct outcome is that neither happens: everything
     * is authorized before anything is written.
     */
    public function test_a_mixed_request_where_one_field_is_refused_writes_nothing(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        // Raised by the customer, so they may edit it by hand.
        $ticket = $this->ticketOn($board, $customer, ['title' => 'My own request']);

        $this->assertTrue($customer->can('update', $ticket));
        $this->assertFalse($customer->can('move', $ticket));

        $message = $this->forge($board, $customer, AiActionType::UpdateTicket, [
            'number' => $ticket->number,
            'title' => 'Edited',
            'column' => 'Done',
        ]);

        try {
            app(ExecuteChatAction::class)->handle($board, $message, $customer);
            $this->fail('A customer moved a ticket.');
        } catch (AuthorizationException) {
            // Expected.
        }

        $ticket->refresh();

        // Neither half landed. A moved card with an unchanged title, or the
        // reverse, would be the ticket in a state nobody asked for.
        $this->assertSame('My own request', $ticket->title);
        $this->assertSame('Backlog', $ticket->column->name);
    }

    // -----------------------------------------------------------------
    // The mode is a ceiling, never a grant
    // -----------------------------------------------------------------

    public function test_ai_agent_grants_a_customer_nothing(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $ticket = $this->ticketOn($board, $customer);

        foreach (AiCapabilityMode::cases() as $mode) {
            $this->aiMode($mode);

            $message = $this->forge($board, $customer, AiActionType::DeleteTicket, [
                'number' => $ticket->number,
            ]);

            try {
                app(ExecuteChatAction::class)->handle($board, $message, $customer);
                $this->fail('A customer deleted a ticket under '.$mode->label().'.');
            } catch (AuthorizationException|\RuntimeException) {
                // Every mode, same answer.
            }

            $this->assertModelExists($ticket);
        }
    }

    /**
     * The refusal is on the record, which is what a security review reads.
     */
    public function test_a_refused_change_leaves_an_audit_row(): void
    {
        $customer = $this->customer();
        $staff = $this->teamMember();
        $board = $this->boardWithColumns([$customer, $staff]);
        $ticket = $this->ticketOn($board, $staff, ['customer_visible' => true]);

        $message = $this->forge($board, $customer, AiActionType::DeleteTicket, [
            'number' => $ticket->number,
        ]);

        try {
            app(ExecuteChatAction::class)->handle($board, $message, $customer);
        } catch (AuthorizationException) {
            // The point of the test is what was written, not what was thrown.
        }

        $this->assertDatabaseHas('ai_tool_invocations', [
            'tool' => AiActionType::DeleteTicket->toolName(),
            'category' => 'action',
            'success' => false,
            'user_id' => $customer->id,
        ]);
    }
}
