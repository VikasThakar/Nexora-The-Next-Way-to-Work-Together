<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Enums\AiActionType;
use App\Enums\AiCapabilityMode;
use App\Enums\AiChatRole;
use App\Livewire\Ai\Assistant;
use App\Livewire\Ai\Chat;
use App\Models\AiChatMessage;
use App\Models\Board;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The in-app assistant on a board split into tabs: what it is told about the
 * tabs, which tab is on screen, and how its proposals file and move tickets —
 * never into a tab nobody named.
 */
class AiBoardTabsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aiMode(AiCapabilityMode::Operator);
    }

    public function test_the_context_lists_each_tabs_columns_and_tags_tickets_with_their_tab(): void
    {
        $provider = $this->fakeAiProvider();
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');
        $this->ticketOn($board, $team, ['title' => 'Pricing page copy', 'board_tab_id' => $website->id]);

        Livewire::actingAs($team)->test(Chat::class, ['board' => $board])
            ->set('draft', 'What is on the website tab?')
            ->call('send');

        $payload = $provider->lastPayload();

        $this->assertStringContainsString('TABS AND COLUMNS', $payload);
        $this->assertStringContainsString('- Website: Backlog (1)', $payload);
        $this->assertStringContainsString('[Website › Backlog] Pricing page copy', $payload);
    }

    public function test_a_board_with_one_tab_is_described_without_tab_names(): void
    {
        $provider = $this->fakeAiProvider();
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->ticketOn($board, $team, ['title' => 'Plain ticket']);

        Livewire::actingAs($team)->test(Chat::class, ['board' => $board])
            ->set('draft', 'Anything new?')
            ->call('send');

        $payload = $provider->lastPayload();

        $this->assertStringContainsString('COLUMNS (in board order): Backlog (1)', $payload);
        $this->assertStringContainsString('[Backlog] Plain ticket', $payload);
        $this->assertStringNotContainsString('TABS AND COLUMNS', $payload);
    }

    public function test_the_page_says_which_tab_is_open_and_a_forged_tab_buys_nothing(): void
    {
        $provider = $this->fakeAiProvider();
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $other = $this->boardWithColumns([$team]);
        $this->tabOn($board, 'Website');
        $secret = $this->tabOn($other, 'Secret plans');

        Livewire::actingAs($team)->test(Assistant::class)
            ->call('reveal', $board->slug, null, null, 'website')
            ->set('draft', 'What is here?')
            ->call('send');

        $this->assertStringContainsString('looking at the Website tab of the '.$board->name.' board', $provider->lastPayload());

        Livewire::actingAs($team)->test(Assistant::class)
            ->call('reveal', $board->slug, null, null, $secret->slug)
            ->set('draft', 'And now?')
            ->call('send');

        $this->assertStringNotContainsString('Secret plans tab', $provider->lastPayload());
    }

    public function test_a_new_ticket_goes_in_the_named_tab_and_column(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');

        $this->confirmProposal($team, $board, AiActionType::CreateTicket, [
            'title' => 'Redesign the footer',
            'tab' => 'website',
            'column' => 'Review',
        ]);

        $ticket = Ticket::query()->where('title', 'Redesign the footer')->sole();

        $this->assertSame($this->columnNamed($board, 'Review', $website)->id, $ticket->board_column_id);
    }

    public function test_a_new_ticket_without_a_tab_goes_to_the_first_tab(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->tabOn($board, 'Website');

        $this->confirmProposal($team, $board, AiActionType::CreateTicket, ['title' => 'Somewhere sensible']);

        $ticket = Ticket::query()->where('title', 'Somewhere sensible')->sole();

        $this->assertTrue($ticket->column->tab->is($this->defaultTab($board)));
    }

    public function test_a_column_that_only_another_tab_has_is_refused_and_that_tab_is_named(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');
        $this->columnNamed($board, 'Review', $website)->update(['name' => 'Design review']);

        $component = $this->confirmProposal($team, $board, AiActionType::CreateTicket, [
            'title' => 'Never filed',
            'column' => 'Design review',
        ]);

        $this->assertSame(0, Ticket::query()->where('title', 'Never filed')->count());

        $component->assertSet('aiError', fn (?string $error): bool => is_string($error)
            && str_contains($error, 'no column called')
            && str_contains($error, 'The Website tab has a "Design review" column'));
    }

    public function test_an_unknown_tab_is_refused_and_the_tabs_are_named(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->tabOn($board, 'Website');

        $component = $this->confirmProposal($team, $board, AiActionType::CreateTicket, [
            'title' => 'Lost',
            'tab' => 'Mobile app',
        ]);

        $this->assertSame(0, Ticket::query()->where('title', 'Lost')->count());

        $component->assertSet('aiError', fn (?string $error): bool => is_string($error)
            && str_contains($error, 'no tab called')
            && str_contains($error, 'Main and Website'));
    }

    public function test_moving_to_another_tab_keeps_the_status_and_a_column_name_alone_stays_in_the_tab(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $main = $this->defaultTab($board);
        $website = $this->tabOn($board, 'Website');
        $ticket = $this->ticketOn($board, $team, ['board_column_id' => $this->columnNamed($board, 'In Progress', $main)->id]);

        $this->confirmProposal($team, $board, AiActionType::UpdateTicket, ['number' => $ticket->number, 'tab' => 'Website']);

        $this->assertSame($this->columnNamed($board, 'In Progress', $website)->id, $ticket->fresh()->board_column_id);

        // "Done" alone now means Website's Done — the tab it is in.
        $this->confirmProposal($team, $board, AiActionType::UpdateTicket, ['number' => $ticket->number, 'column' => 'Done']);

        $this->assertSame($this->columnNamed($board, 'Done', $website)->id, $ticket->fresh()->board_column_id);
    }

    public function test_a_move_with_a_tab_and_a_column_uses_the_new_tabs_column(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');
        $ticket = $this->ticketOn($board, $team);

        $this->confirmProposal($team, $board, AiActionType::UpdateTicket, [
            'number' => $ticket->number,
            'tab' => 'Website',
            'column' => 'Review',
        ]);

        $this->assertSame($this->columnNamed($board, 'Review', $website)->id, $ticket->fresh()->board_column_id);
        $this->assertStringContainsString(
            'moved it to Website › Review',
            (string) AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->latest('id')->first()?->metadata['action']['result']['label'],
        );
    }

    public function test_a_ticket_asked_for_on_a_tab_is_proposed_in_that_tab(): void
    {
        $provider = $this->fakeAiProvider();
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');

        $provider->willPropose(AiActionType::CreateTicket->toolName(), ['title' => 'Fix the hero image']);

        $component = Livewire::actingAs($team)->test(Assistant::class)
            ->call('reveal', $board->slug, null, null, 'website')
            ->set('draft', 'Make a ticket to fix the hero image')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();

        // Filled in when the proposal was drafted, so the card shows it.
        $this->assertSame('Website', $message->metadata['action']['input']['tab']);

        $component->call('startConfirming', $message->id)->call('confirm');

        $ticket = Ticket::query()->where('title', 'Fix the hero image')->sole();
        $this->assertTrue($ticket->column->tab->is($website));
    }

    // -----------------------------------------------------------------

    /**
     * Have the assistant propose one change on the board's chat, and confirm it.
     *
     * @param  array<string, mixed>  $input
     */
    private function confirmProposal(User $user, Board $board, AiActionType $type, array $input): Testable
    {
        $this->fakeAiProvider()->willPropose($type->toolName(), $input);

        $component = Livewire::actingAs($user)->test(Chat::class, ['board' => $board])
            ->set('draft', 'Please do it')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->latest('id')->firstOrFail();

        return $component->call('startConfirming', $message->id)->call('confirm');
    }
}
