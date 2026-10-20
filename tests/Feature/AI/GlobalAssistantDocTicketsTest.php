<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Actions\Docs\SetPageVisibility;
use App\Enums\AiChatMode;
use App\Livewire\Ai\Assistant;
use App\Models\AiSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Create tickets with AI" on a documentation page, and the page's text
 * reaching the model.
 */
class GlobalAssistantDocTicketsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_button_opens_the_panel_on_the_page_in_writing_with_the_request_written(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Sprint plan', 'body_md' => '- [ ] Ship it']);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('prepareDocTickets', $board->slug, $page->slug)
            ->assertSet('revealed', true)
            ->assertSet('scopeValue', $board->slug)
            ->assertSet('chatMode', AiChatMode::Writing->value)
            ->assertSet('draft', 'Create tickets for the to-dos and bugs on the page "Sprint plan".');

        // Nothing was asked: the person reads the request and presses Send.
        $this->assertSame(0, (int) AiSession::query()->sole()->message_count);
    }

    public function test_a_board_or_page_this_person_cannot_reach_leaves_the_panel_open_and_empty(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $theirs = $this->boardWithColumns([$this->teamMember()]);
        $page = $this->docPageOn($theirs, $this->teamMember(), ['title' => 'Secret']);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('prepareDocTickets', $theirs->slug, $page->slug)
            ->assertSet('revealed', true)
            ->assertSet('draft', '');

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('prepareDocTickets', $board->slug, 'no-such-page')
            ->assertSet('draft', '');
    }

    /**
     * The page's whole text goes with the question — that is what a request
     * about "this page" is about — read as the asker sees it.
     */
    public function test_the_page_on_screen_reaches_the_model(): void
    {
        $fake = $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, [
            'title' => 'Sprint plan',
            'body_md' => "Intro.\n\n".str_repeat('Filler paragraph. ', 100)."\n\n- [ ] DISTINCTIVE-TODO deep in the page",
        ]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('prepareDocTickets', $board->slug, $page->slug)
            ->call('send');

        $payload = $fake->lastPayload();

        $this->assertStringContainsString('THE PAGE ON SCREEN', $payload);
        $this->assertStringContainsString('"Sprint plan" (slug sprint-plan)', $payload);
        $this->assertStringContainsString('DISTINCTIVE-TODO deep in the page', $payload);
    }

    public function test_a_page_on_another_board_than_the_conversation_is_not_sent(): void
    {
        $fake = $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $other = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($other, $team, ['title' => 'Elsewhere', 'body_md' => 'OTHER-BOARD-TEXT']);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->call('syncPage', $other->slug, null, $page->slug)
            ->set('draft', 'What is on this page?')
            ->call('send');

        $this->assertStringNotContainsString('OTHER-BOARD-TEXT', $fake->lastPayload());
    }

    /**
     * A customer gets a published page or nothing: a forged hint naming an
     * internal page puts none of it in front of the model.
     */
    public function test_a_customer_never_gets_an_internal_page_on_screen(): void
    {
        $fake = $this->fakeAiProvider();

        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $internal = $this->docPageOn($board, $team, ['title' => 'Internal notes', 'body_md' => 'INTERNAL-ONLY-TEXT']);
        $published = $this->docPageOn($board, $team, ['title' => 'Guide', 'body_md' => 'PUBLISHED-TEXT']);
        app(SetPageVisibility::class)->handle($published, true, $team);

        Livewire::actingAs($customer)
            ->test(Assistant::class)
            ->call('reveal', $board->slug, null, $internal->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'Summarise this page.')
            ->call('send');

        $this->assertStringNotContainsString('INTERNAL-ONLY-TEXT', $fake->lastPayload());
        $this->assertStringNotContainsString('THE PAGE ON SCREEN', $fake->lastPayload());

        Livewire::actingAs($customer)
            ->test(Assistant::class)
            ->call('reveal', $board->slug, null, $published->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'Summarise this page.')
            ->call('send');

        $this->assertStringContainsString('PUBLISHED-TEXT', $fake->lastPayload());
    }

    /**
     * A customer is never moved into Writing: there is nothing they could
     * propose, and the button is not shown to them anyway.
     */
    public function test_a_customer_is_not_moved_into_writing(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $page = $this->docPageOn($board, $team, ['title' => 'Guide', 'body_md' => '- [ ] x']);
        app(SetPageVisibility::class)->handle($page, true, $team);

        Livewire::actingAs($customer)
            ->test(Assistant::class)
            ->call('prepareDocTickets', $board->slug, $page->slug)
            ->assertSet('chatMode', AiChatMode::Reading->value);
    }
}
