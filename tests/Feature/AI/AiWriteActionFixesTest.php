<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Actions\AI\ExecuteChatAction;
use App\Enums\AiActionType;
use App\Enums\AiCapabilityMode;
use App\Enums\AiChatRole;
use App\Livewire\Ai\Chat;
use App\Models\AiChatMessage;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * The shared write path, ExecuteChatAction::perform(), on the cases the remote
 * MCP server made worth pinning down: who can be assigned, due dates and
 * estimates, archived boards, somebody else's unsaved draft, and the audit row
 * for a target that cannot be seen.
 */
class AiWriteActionFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aiMode(AiCapabilityMode::Operator);
    }

    private function perform(AiActionType $type, $board, array $input, $actor): array
    {
        return app(ExecuteChatAction::class)->perform($type, $board, $input, $actor);
    }

    public function test_a_customer_on_the_board_is_not_an_assignee_and_nothing_claims_otherwise(): void
    {
        $admin = $this->admin();
        $customer = $this->customer(['name' => 'Cara Client']);
        $board = $this->boardWithColumns([$admin, $customer]);
        $ticket = $this->ticketOn($board, $admin);

        try {
            $this->perform(AiActionType::UpdateTicket, $board, ['number' => $ticket->number, 'assignee' => 'Cara Client'], $admin);
            $this->fail('A customer was accepted as an assignee.');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('not customers', $refusal->getMessage());
        }

        $this->assertNull($ticket->refresh()->assignee_id);
    }

    public function test_a_deactivated_member_is_not_an_assignee(): void
    {
        $admin = $this->admin();
        $gone = $this->teamMember(['name' => 'Gone Person', 'deactivated_at' => now()]);
        $board = $this->boardWithColumns([$admin, $gone]);

        $this->expectException(RuntimeException::class);

        $this->perform(AiActionType::CreateTicket, $board, ['title' => 'Something', 'assignee' => 'Gone Person'], $admin);
    }

    public function test_due_date_and_estimate_are_set_and_reported_and_none_clears_the_date(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        $result = $this->perform(AiActionType::CreateTicket, $board, [
            'title' => 'Ship the release notes',
            'due_date' => '2026-11-03',
            'estimate' => 3,
        ], $admin);

        $ticket = Ticket::query()->sole();

        $this->assertSame('2026-11-03', $ticket->due_date?->toDateString());
        $this->assertEquals(3.0, (float) $ticket->estimate);
        $this->assertStringContainsString('due 2026-11-03', $result['label']);

        $result = $this->perform(AiActionType::UpdateTicket, $board, ['number' => $ticket->number, 'due_date' => 'none'], $admin);

        $this->assertNull($ticket->refresh()->due_date);
        $this->assertStringContainsString('cleared the due date', $result['label']);
    }

    public function test_a_date_that_is_not_on_the_calendar_is_refused_and_nothing_is_created(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        try {
            $this->perform(AiActionType::CreateTicket, $board, ['title' => 'Leap', 'due_date' => '2026-02-30'], $admin);
            $this->fail('An impossible date was accepted.');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('2026-10-25', $refusal->getMessage());
        }

        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_an_archived_board_is_not_changed(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $board->forceFill(['archived_at' => now()])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('archived');

        $this->perform(AiActionType::CreateTicket, $board, ['title' => 'Too late'], $admin);
    }

    public function test_a_page_with_somebody_elses_unsaved_draft_is_not_overwritten(): void
    {
        $admin = $this->admin();
        $writer = $this->teamMember(['name' => 'Wendy Writer']);
        $board = $this->boardWithColumns([$admin, $writer]);
        $page = $this->docPageOn($board, $writer, ['title' => 'Runbook', 'body_md' => 'Saved text']);

        $page->forceFill([
            'draft_title' => 'Runbook',
            'draft_md' => 'Half-written edit',
            'draft_saved_at' => now(),
            'draft_by_id' => $writer->id,
        ])->save();

        try {
            $this->perform(AiActionType::UpdateDocPage, $board, ['slug' => $page->slug, 'body_md' => 'AI text'], $admin);
            $this->fail('Somebody else\'s draft was overwritten.');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('Wendy Writer has unsaved edits', $refusal->getMessage());
        }

        $page->refresh();
        $this->assertSame('Saved text', $page->body_md);
        $this->assertSame('Half-written edit', $page->draft_md);

        // The draft's own author may replace it.
        $result = $this->perform(AiActionType::UpdateDocPage, $board, ['slug' => $page->slug, 'body_md' => 'Her own text'], $writer);

        $this->assertSame('Her own text', $page->refresh()->body_md);
        $this->assertSame($page->slug, $result['target']);
    }

    public function test_a_proposal_for_a_ticket_nobody_can_see_is_audited(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        $this->fakeAiProvider()->willPropose(AiActionType::UpdateTicket->toolName(), [
            'number' => 999,
            'priority' => 'high',
        ]);

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Make 999 high priority')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();
        $component->call('startConfirming', $message->id)->call('confirm');

        $this->assertDatabaseHas('ai_tool_invocations', [
            'tool' => AiActionType::UpdateTicket->toolName(),
            'category' => 'action',
            'outcome' => 'error',
            'success' => false,
            'user_id' => $admin->id,
        ]);
    }
}
