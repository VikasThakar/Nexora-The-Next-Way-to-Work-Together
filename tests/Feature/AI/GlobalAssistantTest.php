<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Enums\AiChatRole;
use App\Livewire\Ai\Assistant;
use App\Models\AiChatMessage;
use App\Services\AI\Exceptions\AiProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The assistant as a global panel.
 *
 * What these tests are really about is that the panel is reachable from
 * anywhere and yet never *breaks* anywhere — a component in the layout is on
 * every page, so its failure modes are every page's failure modes. The
 * companion file tests\Security\AiAssistantSecurityTest covers what it must
 * refuse.
 */
class GlobalAssistantTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Reachable from anywhere
    // -----------------------------------------------------------------

    /**
     * The trigger and the panel are present on every kind of page.
     *
     * Asserted through real page renders rather than by testing the component
     * in isolation, because "available everywhere" is a claim about the layout,
     * and the layout is what decides whether to mount it at all.
     */
    public function test_the_assistant_is_available_on_every_kind_of_page(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);
        $page = $this->docPageOn($board, $team, ['title' => 'Handbook']);

        $urls = [
            'dashboard' => route('dashboard'),
            'boards' => route('boards.index'),
            'board' => route('boards.show', $board),
            'ticket' => route('tickets.show', ['board' => $board, 'number' => $ticket->number]),
            'docs' => route('docs.show', ['board' => $board, 'slug' => $page->slug]),
            'statistics' => route('stats'),
            'settings' => route('settings'),
        ];

        foreach ($urls as $name => $url) {
            $response = $this->actingAs($team)->get($url);

            $response->assertOk();
            $response->assertSee('Workspace AI', escape: false);
            $this->assertStringContainsString(
                'x-persist="ai-panel"',
                $response->getContent(),
                "The assistant panel is not mounted on the {$name} page.",
            );
        }
    }

    /**
     * The panel's wiring, asserted on the rendered markup.
     *
     * Behaviour tests cannot see a mistyped directive: a `wire:stream` whose
     * name does not match the one the component streams to, or a selector bound
     * with wire:model instead of wire:change, would leave every other test in
     * this file passing while the panel did nothing in a browser.
     */
    public function test_the_panel_is_wired_to_stream_and_to_switch_context(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $this->boardWithColumns([$team], ['name' => 'NutriLens', 'slug' => 'nutrilens']);

        $html = Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', 'nutrilens')
            ->html();

        foreach ([
            // The streaming target, named exactly as send() streams to it.
            'wire:stream.replace="answer"',
            // An action, not a bound property. See Assistant::selectScope().
            'wire:change="selectScope',
            'All workspace',
            'NutriLens',
            'wire:submit="send"',
            // Accessibility: a non-modal region, and a live region for the answer.
            'role="complementary"',
            'aria-live="polite"',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, "The panel is missing: {$needle}");
        }

        // And the trigger reaches the store on a real page.
        $page = $this->actingAs($team)->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('$store.aiPanel.toggle(page)', $page);
        $this->assertStringContainsString('x-persist="ai-panel"', $page);
    }

    public function test_opening_the_panel_on_a_board_adopts_that_board_as_the_context(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['slug' => 'nutrilens']);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->assertSet('scopeValue', 'nutrilens')
            ->assertSet('revealed', true);
    }

    public function test_opening_the_panel_with_no_board_in_scope_starts_on_the_whole_workspace(): void
    {
        $team = $this->teamMember();
        $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal')
            ->assertSet('scopeValue', 'workspace')
            ->assertOk();
    }

    /**
     * Navigating elsewhere must not silently change the subject.
     */
    public function test_moving_to_another_board_does_not_switch_the_conversation(): void
    {
        $team = $this->teamMember();
        $one = $this->boardWithColumns([$team], ['slug' => 'one']);
        $two = $this->boardWithColumns([$team], ['slug' => 'two']);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $one->slug)
            ->assertSet('scopeValue', 'one')
            ->call('syncPage', $two->slug)
            // Offered in the header, never performed.
            ->assertSet('scopeValue', 'one')
            ->assertSet('pageBoard', 'two');
    }

    public function test_the_context_selector_switches_which_conversation_is_shown(): void
    {
        $provider = $this->fakeAiProvider();

        $team = $this->teamMember();
        $one = $this->boardWithColumns([$team], ['slug' => 'one', 'name' => 'Board One']);
        $two = $this->boardWithColumns([$team], ['slug' => 'two', 'name' => 'Board Two']);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $one->slug)
            ->set('draft', 'Question about one')
            ->call('send');

        $provider->willReturn('An answer about two.');

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $two->slug)
            ->call('selectScope', 'two')
            ->assertSet('scopeValue', 'two')
            // Board two's thread is empty; board one's question is not in it.
            ->assertDontSee('Question about one');

        $this->assertSame(2, $one->aiChatMessages()->count());
        $this->assertSame(0, $two->aiChatMessages()->count());
    }

    // -----------------------------------------------------------------
    // The workspace scope
    // -----------------------------------------------------------------

    public function test_a_workspace_question_is_filed_against_no_board_and_replays_its_own_history(): void
    {
        $provider = $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal')
            ->call('selectScope', 'workspace')
            ->set('draft', 'How is everything going?')
            ->call('send');

        $turns = AiChatMessage::query()->get();

        $this->assertCount(2, $turns);
        $this->assertTrue($turns->every(fn (AiChatMessage $m): bool => $m->board_id === null));

        // A second workspace question replays the first, and the board thread
        // stays empty.
        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal')
            ->call('selectScope', 'workspace')
            ->set('draft', 'And now?')
            ->call('send');

        $this->assertStringContainsString('How is everything going?', $provider->lastPayload());
        $this->assertSame(0, $board->aiChatMessages()->count());
    }

    public function test_the_workspace_context_summarises_boards_without_their_ticket_bodies(): void
    {
        $provider = $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['name' => 'NutriLens']);
        $this->ticketOn($board, $team, [
            'title' => 'Export drops the VAT column',
            'description_md' => 'A very long description that should not be sent in workspace mode.',
        ]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal')
            ->call('selectScope', 'workspace')
            ->set('draft', 'What is going on?')
            ->call('send');

        $payload = $provider->lastPayload();

        // Breadth, bought with depth: names and titles travel, bodies do not.
        $this->assertStringContainsString('WORKSPACE CONTEXT', $payload);
        $this->assertStringContainsString('NutriLens', $payload);
        $this->assertStringContainsString('Export drops the VAT column', $payload);
        $this->assertStringNotContainsString('should not be sent in workspace mode', $payload);
    }

    // -----------------------------------------------------------------
    // Page context
    // -----------------------------------------------------------------

    public function test_the_prompt_says_which_ticket_is_open(): void
    {
        $provider = $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Export drops the VAT column']);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug, $ticket->number)
            ->set('draft', 'Why is this blocked?')
            ->call('send');

        $payload = $provider->lastPayload();

        $this->assertStringContainsString('CURRENT PAGE', $payload);
        $this->assertStringContainsString($ticket->key(), $payload);
    }

    public function test_page_context_is_omitted_when_nothing_is_open(): void
    {
        $provider = $this->fakeAiProvider();

        $team = $this->teamMember();
        $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal')
            ->set('draft', 'Anything')
            ->call('send');

        $this->assertStringNotContainsString('CURRENT PAGE', $provider->lastPayload());
    }

    // -----------------------------------------------------------------
    // Streaming, errors, duplicates
    // -----------------------------------------------------------------

    public function test_an_answer_arrives_progressively_and_is_stored_whole(): void
    {
        $provider = $this->fakeAiProvider();
        $provider->willReturn('The export query drops the VAT column.');

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->set('draft', 'What is wrong?')
            ->call('send');

        // More than one fragment, or it was not streamed at all.
        $prose = array_values(array_filter($provider->streamedChunks, fn (string $c): bool => $c !== ''));
        $this->assertGreaterThan(1, count($prose));

        // And the fragments reassemble to exactly what was stored.
        $stored = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();

        $this->assertSame('The export query drops the VAT column.', $stored->content);
        $this->assertSame($stored->content, implode('', $prose));
    }

    public function test_a_provider_failure_shows_an_error_and_keeps_the_question(): void
    {
        $provider = $this->fakeAiProvider();
        $provider->willFail(AiProviderException::overloaded());

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $component = Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->set('draft', 'Did this survive?')
            ->call('send');

        // An error the person can read, and no wedged sending state.
        $this->assertNotNull($component->get('aiError'));
        $component->assertSet('sending', false);

        // A gap in the transcript would be worse than a visible failure.
        $this->assertSame(1, AiChatMessage::query()->count());
        $this->assertSame('Did this survive?', AiChatMessage::query()->sole()->content);
    }

    /**
     * The re-entry guard.
     *
     * `sending` is rehydrated from the browser, so a second submission of a
     * question already in flight arrives with it set. That request must do
     * nothing at all — not queue, not ask again, not double-charge.
     */
    public function test_a_second_submission_while_one_is_in_flight_is_dropped(): void
    {
        $provider = $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->set('sending', true)
            ->set('draft', 'Asked twice')
            ->call('send');

        $this->assertSame(0, $provider->calls);
        $this->assertSame(0, AiChatMessage::query()->count());
    }

    public function test_an_empty_question_is_refused(): void
    {
        $provider = $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->set('draft', '')
            ->call('send')
            ->assertHasErrors('draft');

        $this->assertSame(0, $provider->calls);
    }

    // -----------------------------------------------------------------
    // History
    // -----------------------------------------------------------------

    public function test_clearing_removes_only_this_persons_turns_in_this_scope(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $colleague = $this->teamMember();
        $board = $this->boardWithColumns([$team, $colleague]);

        foreach ([$team, $colleague] as $asker) {
            Livewire::actingAs($asker)
                ->test(Assistant::class)
                ->call('reveal', $board->slug)
                ->set('draft', 'Mine')
                ->call('send');
        }

        $this->assertSame(4, AiChatMessage::query()->count());

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('clearHistory');

        // The colleague's two turns are untouched.
        $this->assertSame(2, AiChatMessage::query()->count());
        $this->assertSame(
            2,
            AiChatMessage::query()->where('user_id', $colleague->getKey())->count(),
        );
    }

    public function test_the_full_page_board_chat_still_works(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        // The route was kept deliberately: it is still linked from the board's
        // AI settings screen and is still bookmarkable.
        $this->actingAs($team)
            ->get(route('boards.ai-chat', $board))
            ->assertOk()
            ->assertSee('Workspace AI', escape: false);
    }
}
