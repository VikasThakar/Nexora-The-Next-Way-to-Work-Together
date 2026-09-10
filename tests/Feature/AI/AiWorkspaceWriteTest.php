<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Enums\AiActionType;
use App\Enums\AiCapabilityMode;
use App\Enums\AiChatRole;
use App\Enums\CommentStream;
use App\Enums\TicketEventType;
use App\Enums\TicketPriority;
use App\Enums\TicketType;
use App\Livewire\Ai\Chat;
use App\Models\AiChatMessage;
use App\Models\Label;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The assistant's write surface, field by field.
 *
 * Everything here goes through the real pipeline — a faked provider proposes a
 * tool call, the surface stores it, and App\Actions\AI\ExecuteChatAction
 * authorizes and carries it out through the ordinary actions. Nothing stubs the
 * executor, so what these tests assert is what the database actually ends up
 * holding.
 *
 * The mode is pinned to AI Operator throughout, so each test exercises the
 * propose-and-confirm path explicitly rather than depending on the deployment
 * default. The unattended path has its own suite.
 *
 * Two things are asserted about every write, and the second matters as much as
 * the first: that the change landed, and that the sentence reported back
 * describes what landed. §16 of the brief — "do not say an operation succeeded
 * unless the backend confirms success" — is only kept if the label is built
 * from the saved row, so these tests read it.
 */
class AiWorkspaceWriteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aiMode(AiCapabilityMode::Operator);
    }

    // -----------------------------------------------------------------
    // Creating
    // -----------------------------------------------------------------

    /**
     * The brief's §7 example, end to end: one request, five fields, one ticket.
     */
    public function test_an_admin_creates_a_ticket_with_every_field_in_one_request(): void
    {
        $admin = $this->admin();
        $alex = $this->teamMember(['name' => 'Alex Round']);
        $board = $this->boardWithColumns([$admin, $alex]);

        $bug = Label::factory()->create(['board_id' => $board->id, 'name' => 'bug']);

        $this->fakeAiProvider()->willPropose(AiActionType::CreateTicket->toolName(), [
            'title' => 'Payment gateway is failing',
            'description_md' => 'Customers cannot complete payment.',
            'priority' => TicketPriority::Critical->value,
            'type' => TicketType::Bug->value,
            'assignee' => 'Alex Round',
            'column' => 'In Progress',
            'labels' => ['bug'],
        ]);

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Raise a critical bug for the payment gateway, assign it to Alex, put it in progress')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();

        $component->call('startConfirming', $message->id)->call('confirm');

        $ticket = Ticket::query()->sole();

        $this->assertSame('Payment gateway is failing', $ticket->title);
        $this->assertSame('Customers cannot complete payment.', $ticket->description_md);
        $this->assertSame(TicketPriority::Critical, $ticket->priority);
        $this->assertSame(TicketType::Bug, $ticket->type);
        $this->assertSame($alex->id, $ticket->assignee_id);
        $this->assertSame('In Progress', $ticket->column->name);
        $this->assertSame(['bug'], $ticket->labels->pluck('name')->all());
        $this->assertSame($bug->id, $ticket->labels->sole()->id);

        // Raised by the admin, not by "the AI". The assistant acts on somebody's
        // behalf and the row records whose.
        $this->assertSame($admin->id, $ticket->created_by_id);

        // And still internal: nothing said to the assistant publishes work.
        $this->assertFalse($ticket->customer_visible);
    }

    /**
     * The reported line is built from the saved ticket, so it cannot flatter.
     */
    public function test_the_result_line_describes_the_ticket_that_was_actually_saved(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        $this->fakeAiProvider()->willPropose(AiActionType::CreateTicket->toolName(), [
            'title' => 'Something broke',
            'priority' => TicketPriority::High->value,
            'column' => 'Review',
        ]);

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Raise it')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();

        $component->call('startConfirming', $message->id)->call('confirm');

        $label = (string) ($message->refresh()->action()['result']['label'] ?? '');

        $this->assertStringContainsString(Ticket::query()->sole()->key(), $label);
        $this->assertStringContainsString('priority High', $label);
        $this->assertStringContainsString('in Review', $label);
        // Nobody was named, so it says so rather than omitting the fact.
        $this->assertStringContainsString('unassigned', $label);
    }

    // -----------------------------------------------------------------
    // Updating: the mutations the brief lists for an admin
    // -----------------------------------------------------------------

    public function test_an_admin_assigns_a_ticket_and_changes_its_priority_in_one_request(): void
    {
        $admin = $this->admin();
        $alex = $this->teamMember(['name' => 'Alex Round']);
        $board = $this->boardWithColumns([$admin, $alex]);
        $ticket = $this->ticketOn($board, $admin, ['title' => 'I spotted a bug in the system']);

        $this->fakeAiProvider()->willPropose(AiActionType::UpdateTicket->toolName(), [
            'number' => $ticket->number,
            'assignee' => 'Alex Round',
            'priority' => TicketPriority::Critical->value,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Assign '.$ticket->key().' to Alex Round and make it critical')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();

        $component->call('startConfirming', $message->id)->call('confirm');

        $ticket->refresh();

        $this->assertSame($alex->id, $ticket->assignee_id);
        $this->assertSame(TicketPriority::Critical, $ticket->priority);

        $label = (string) ($message->refresh()->action()['result']['label'] ?? '');
        $this->assertStringContainsString('assigned it to Alex Round', $label);
        $this->assertStringContainsString('Critical', $label);
    }

    public function test_an_admin_moves_a_ticket_between_columns(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $ticket = $this->ticketOn($board, $admin);

        $this->assertSame('Backlog', $ticket->column->name);

        $this->fakeAiProvider()->willPropose(AiActionType::UpdateTicket->toolName(), [
            'number' => $ticket->number,
            'column' => 'Done',
        ]);

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Move '.$ticket->key().' to Done')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();

        $component->call('startConfirming', $message->id)->call('confirm');

        $this->assertSame('Done', $ticket->refresh()->column->name);

        // Through MoveTicket, so the move is in the ticket's own history where
        // somebody reading the ticket will find it.
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'type' => TicketEventType::TicketMoved->value,
            'actor_id' => $admin->id,
        ]);
    }

    public function test_labels_replace_the_current_set_and_an_empty_list_clears_them(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $ticket = $this->ticketOn($board, $admin);

        $bug = Label::factory()->create(['board_id' => $board->id, 'name' => 'bug']);
        $urgent = Label::factory()->create(['board_id' => $board->id, 'name' => 'urgent']);

        $ticket->labels()->sync([$bug->id]);

        $this->fakeAiProvider()->willPropose(AiActionType::UpdateTicket->toolName(), [
            'number' => $ticket->number,
            'labels' => ['urgent'],
        ]);

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Label it urgent')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();
        $component->call('startConfirming', $message->id)->call('confirm');

        // Replaced, not added to — which is what the schema tells the model.
        $this->assertSame([$urgent->id], $ticket->refresh()->labels->pluck('id')->all());
    }

    // -----------------------------------------------------------------
    // Commenting
    // -----------------------------------------------------------------

    public function test_a_comment_from_the_assistant_is_internal_unless_it_was_asked_to_be_visible(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $ticket = $this->ticketOn($board, $admin);

        $this->fakeAiProvider()->willPropose(AiActionType::CommentTicket->toolName(), [
            'number' => $ticket->number,
            'body_md' => 'Reproduced on staging.',
        ]);

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Note on '.$ticket->key().' that it reproduces on staging')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();
        $component->call('startConfirming', $message->id)->call('confirm');

        $comment = $ticket->comments()->sole();

        $this->assertSame('Reproduced on staging.', $comment->body_md);
        $this->assertSame($admin->id, $comment->author_id);
        // Fail-closed: an unqualified request is an internal note.
        $this->assertSame(CommentStream::Internal, $comment->stream);
    }

    // -----------------------------------------------------------------
    // Where a natural-language request is nearly right
    // -----------------------------------------------------------------

    /**
     * The brief's own sentence: "I couldn't assign NL-18 to Alex because Alex is
     * not a member of this board."
     */
    public function test_assigning_somebody_who_is_not_a_board_member_is_refused_and_changes_nothing(): void
    {
        $admin = $this->admin();
        $outsider = $this->teamMember(['name' => 'Alex Round']);
        $board = $this->boardWithColumns([$admin]); // Alex is deliberately not a member.
        $ticket = $this->ticketOn($board, $admin);

        $this->fakeAiProvider()->willPropose(AiActionType::UpdateTicket->toolName(), [
            'number' => $ticket->number,
            'assignee' => 'Alex Round',
        ]);

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Assign it to Alex Round')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();
        $component->call('startConfirming', $message->id)->call('confirm');

        $this->assertNull($ticket->refresh()->assignee_id);
        $this->assertNotSame($outsider->id, $ticket->assignee_id);

        $component->assertSet('aiError', fn (?string $error): bool => is_string($error)
            && str_contains($error, 'not a member of'));

        // Recorded as failed, so the transcript does not show a Confirm button
        // that looks as though it never worked.
        $this->assertSame(AiChatMessage::ACTION_FAILED, $message->refresh()->actionState());
    }

    public function test_an_ambiguous_assignee_is_a_question_rather_than_a_guess(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([
            $admin,
            $this->teamMember(['name' => 'Alex Round']),
            $this->teamMember(['name' => 'Alex Rowe']),
        ]);
        $ticket = $this->ticketOn($board, $admin);

        $this->fakeAiProvider()->willPropose(AiActionType::UpdateTicket->toolName(), [
            'number' => $ticket->number,
            'assignee' => 'Alex',
        ]);

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Assign it to Alex')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();
        $component->call('startConfirming', $message->id)->call('confirm');

        $this->assertNull($ticket->refresh()->assignee_id);

        $component->assertSet('aiError', fn (?string $error): bool => is_string($error)
            && str_contains($error, 'More than one member')
            && str_contains($error, 'Alex Round')
            && str_contains($error, 'Alex Rowe'));
    }

    /**
     * An exact match wins outright, so the ambiguity rule does not make the
     * common case unusable.
     */
    public function test_an_exact_name_wins_over_a_partial_one(): void
    {
        $admin = $this->admin();
        $alex = $this->teamMember(['name' => 'Alex']);
        $board = $this->boardWithColumns([$admin, $alex, $this->teamMember(['name' => 'Alexandra Bell'])]);
        $ticket = $this->ticketOn($board, $admin);

        $this->fakeAiProvider()->willPropose(AiActionType::UpdateTicket->toolName(), [
            'number' => $ticket->number,
            'assignee' => 'Alex',
        ]);

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Assign it to Alex')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();
        $component->call('startConfirming', $message->id)->call('confirm');

        $this->assertSame($alex->id, $ticket->refresh()->assignee_id);
    }

    public function test_a_column_the_board_does_not_have_is_refused_and_names_the_ones_it_does(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $ticket = $this->ticketOn($board, $admin);

        $this->fakeAiProvider()->willPropose(AiActionType::UpdateTicket->toolName(), [
            'number' => $ticket->number,
            'column' => 'Shipped',
        ]);

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Move it to Shipped')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();
        $component->call('startConfirming', $message->id)->call('confirm');

        $this->assertSame('Backlog', $ticket->refresh()->column->name);

        $component->assertSet('aiError', fn (?string $error): bool => is_string($error)
            && str_contains($error, 'no column called')
            && str_contains($error, 'In Progress'));
    }

    public function test_a_label_that_does_not_exist_refuses_the_whole_change(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $ticket = $this->ticketOn($board, $admin, ['title' => 'Untouched']);

        Label::factory()->create(['board_id' => $board->id, 'name' => 'bug']);

        $this->fakeAiProvider()->willPropose(AiActionType::UpdateTicket->toolName(), [
            'number' => $ticket->number,
            'title' => 'Renamed',
            'labels' => ['bug', 'nonexistent'],
        ]);

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Rename it and label it')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();
        $component->call('startConfirming', $message->id)->call('confirm');

        $ticket->refresh();

        // Nothing at all: the labels are resolved before anything is written, so
        // the rename does not land either. Partial success is the outcome being
        // avoided here.
        $this->assertSame('Untouched', $ticket->title);
        $this->assertCount(0, $ticket->labels);

        $component->assertSet('aiError', fn (?string $error): bool => is_string($error)
            && str_contains($error, 'no label called'));
    }

    /**
     * "me" is the person asking, which is how people actually speak.
     */
    public function test_assigning_to_me_resolves_to_the_person_asking(): void
    {
        $admin = $this->admin(['name' => 'Sam Carter']);
        $board = $this->boardWithColumns([$admin]);
        $ticket = $this->ticketOn($board, $admin);

        $this->fakeAiProvider()->willPropose(AiActionType::UpdateTicket->toolName(), [
            'number' => $ticket->number,
            'assignee' => 'me',
        ]);

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Assign it to me')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();
        $component->call('startConfirming', $message->id)->call('confirm');

        $this->assertSame($admin->id, $ticket->refresh()->assignee_id);
    }

    public function test_nobody_unassigns_a_ticket(): void
    {
        $admin = $this->admin();
        $alex = $this->teamMember(['name' => 'Alex Round']);
        $board = $this->boardWithColumns([$admin, $alex]);
        $ticket = $this->ticketOn($board, $admin, ['assignee_id' => $alex->id]);

        $this->assertSame($alex->id, $ticket->assignee_id);

        $this->fakeAiProvider()->willPropose(AiActionType::UpdateTicket->toolName(), [
            'number' => $ticket->number,
            'assignee' => 'nobody',
        ]);

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Unassign it')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();
        $component->call('startConfirming', $message->id)->call('confirm');

        $this->assertNull($ticket->refresh()->assignee_id);
    }

    // -----------------------------------------------------------------
    // The audit trail
    // -----------------------------------------------------------------

    public function test_every_executed_change_is_recorded_against_the_person_and_the_turn(): void
    {
        $admin = $this->admin(['name' => 'Sam Carter']);
        $board = $this->boardWithColumns([$admin]);
        $ticket = $this->ticketOn($board, $admin);

        $this->fakeAiProvider()->willPropose(AiActionType::UpdateTicket->toolName(), [
            'number' => $ticket->number,
            'priority' => TicketPriority::Critical->value,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Make it critical')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();
        $component->call('startConfirming', $message->id)->call('confirm');

        $this->assertDatabaseHas('ai_tool_invocations', [
            'tool' => AiActionType::UpdateTicket->toolName(),
            'category' => 'action',
            'outcome' => 'ok',
            'user_id' => $admin->id,
            'board_id' => $board->id,
            'ai_chat_message_id' => $message->id,
            'target' => $ticket->key(),
        ]);
    }

    public function test_a_refused_change_is_recorded_too(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $ticket = $this->ticketOn($board, $admin);

        $this->fakeAiProvider()->willPropose(AiActionType::UpdateTicket->toolName(), [
            'number' => $ticket->number,
            'column' => 'Nowhere',
        ]);

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Move it')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();
        $component->call('startConfirming', $message->id)->call('confirm');

        // A refused write is the more interesting half of an audit trail: it is
        // the trace of somebody asking for something that did not happen.
        $this->assertDatabaseHas('ai_tool_invocations', [
            'tool' => AiActionType::UpdateTicket->toolName(),
            'category' => 'action',
            'outcome' => 'error',
            'success' => false,
            'user_id' => $admin->id,
        ]);
    }

    /**
     * Board membership is what makes an assignee resolvable, so a member of one
     * board is not resolvable on another even by exact name.
     */
    public function test_resolution_is_scoped_to_the_board_the_change_lands_on(): void
    {
        $admin = $this->admin();
        $alex = $this->teamMember(['name' => 'Alex Round']);

        $here = $this->boardWithColumns([$admin]);
        $this->boardWithColumns([$admin, $alex], ['slug' => 'elsewhere']);

        $ticket = $this->ticketOn($here, $admin);

        $this->fakeAiProvider()->willPropose(AiActionType::UpdateTicket->toolName(), [
            'number' => $ticket->number,
            'assignee' => 'Alex Round',
        ]);

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $here])
            ->set('draft', 'Assign it to Alex')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();
        $component->call('startConfirming', $message->id)->call('confirm');

        $this->assertNull($ticket->refresh()->assignee_id);

        $component->assertSet('aiError', fn (?string $error): bool => is_string($error)
            && str_contains($error, 'not a member of'));
    }
}
