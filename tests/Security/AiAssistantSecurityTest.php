<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Enums\AiActionType;
use App\Enums\AiChatRole;
use App\Livewire\Ai\Assistant;
use App\Models\AiChatMessage;
use App\Models\Ticket;
use App\Services\BoardAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What the global assistant panel must refuse.
 *
 * Moving the assistant into the layout changed its threat model in three ways,
 * and each has tests below.
 *
 *   It is on every page.       So it cannot abort on an authorization failure:
 *                              a 404 raised from the layout takes the whole
 *                              page with it. It must fail closed instead, and
 *                              closed has to mean *empty*, not *someone
 *                              else's*.
 *   Its scope comes from the   The board is no longer a route parameter model
 *   browser.                   binding. A slug in a form is browser-writable,
 *                              so it is re-resolved through BoardAccess on
 *                              every request.
 *   It streams.                Livewire assigns streamed content with
 *                              innerHTML, so a model answer — which repeats
 *                              text users wrote — has to be escaped before it
 *                              goes on the wire.
 *
 * Conversations also became per person, so a colleague's and an
 * administrator's threads are covered here too.
 */
class AiAssistantSecurityTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Customers
    // -----------------------------------------------------------------

    public function test_a_customers_page_contains_no_assistant_at_all(): void
    {
        $customer = $this->customer();
        $this->boardWithColumns([$customer]);

        $response = $this->actingAs($customer)->get(route('dashboard'))->assertOk();

        // No trigger, and no component id for a crafted request to address.
        $response->assertDontSee('Workspace AI', escape: false);
        $this->assertStringNotContainsString('x-persist="ai-panel"', $response->getContent());
    }

    public function test_a_customer_driving_the_panel_directly_reads_nothing(): void
    {
        $this->fakeAiProvider();

        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        Livewire::actingAs($customer)
            ->test(Assistant::class)
            // Not a 404 — the panel never aborts — but an empty one.
            ->assertOk()
            ->call('reveal', $board->slug)
            ->assertSet('scopeValue', 'workspace')
            ->set('draft', 'Tell me about the internal notes')
            ->call('send');

        // The question never reached a provider and nothing was written.
        $this->assertSame(0, AiChatMessage::query()->count());
    }

    // -----------------------------------------------------------------
    // Scope resolution
    // -----------------------------------------------------------------

    public function test_selecting_a_board_the_person_is_not_on_falls_back_to_the_workspace(): void
    {
        $team = $this->teamMember();
        $mine = $this->boardWithColumns([$team], ['slug' => 'mine', 'name' => 'Mine']);
        $theirs = $this->boardWithColumns([$this->teamMember()], ['slug' => 'theirs', 'name' => 'Theirs']);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $mine->slug)
            ->call('selectScope', $theirs->slug)
            // Closed down, not errored: an error would confirm the board exists.
            ->assertSet('scopeValue', 'workspace')
            ->assertDontSee('Theirs');
    }

    public function test_a_question_asked_on_a_tampered_scope_never_reaches_that_board(): void
    {
        $provider = $this->fakeAiProvider();

        $team = $this->teamMember();
        $this->boardWithColumns([$team], ['slug' => 'mine']);

        $outsider = $this->teamMember();
        $other = $this->boardWithColumns([$outsider], ['slug' => 'theirs', 'name' => 'Theirs']);
        $this->ticketOn($other, $outsider, ['title' => 'Their confidential roadmap item']);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal')
            ->call('selectScope', 'theirs')
            ->set('draft', 'What is on that board?')
            ->call('send');

        $payload = $provider->lastPayload();

        $this->assertStringNotContainsString('Their confidential roadmap item', $payload);
        $this->assertStringNotContainsString('Theirs', $payload);
    }

    /**
     * The highest-risk failure mode of a layout component.
     */
    public function test_losing_access_to_the_selected_board_falls_back_without_breaking_the_page(): void
    {
        $team = $this->teamMember();
        $revoked = $this->boardWithColumns([$team], ['slug' => 'gone', 'name' => 'Gone']);

        // A second board, so the person stays eligible for the assistant and
        // this exercises the scope fallback rather than the empty-panel branch.
        $this->boardWithColumns([$team], ['slug' => 'kept']);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $revoked->slug)
            ->assertSet('scopeValue', 'gone');

        // Membership revoked mid-session.
        $revoked->members()->detach($team->getKey());
        app(BoardAccess::class)->flush();

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->set('scopeValue', 'gone')
            ->call('reveal', 'gone')
            ->assertOk()
            ->assertSet('scopeValue', 'workspace')
            ->assertDontSee('Gone');

        // And the page it lives on is still a page.
        $this->actingAs($team)->get(route('dashboard'))->assertOk();
    }

    public function test_losing_every_board_empties_the_panel_rather_than_breaking_the_page(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['slug' => 'only']);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->assertSet('scopeValue', 'only');

        $board->members()->detach($team->getKey());
        app(BoardAccess::class)->flush();

        // No boards at all, so the panel is empty — and, critically, it renders
        // rather than aborting.
        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->set('scopeValue', 'only')
            ->call('reveal', 'only')
            ->assertOk()
            ->assertSet('scopeValue', 'workspace');

        $this->actingAs($team)->get(route('dashboard'))->assertOk();
    }

    // -----------------------------------------------------------------
    // Per-person conversations
    // -----------------------------------------------------------------

    public function test_a_colleague_on_the_same_board_cannot_read_this_conversation(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $colleague = $this->teamMember();
        $board = $this->boardWithColumns([$team, $colleague]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->set('draft', 'My private line of enquiry')
            ->call('send');

        Livewire::actingAs($colleague)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->assertOk()
            ->assertDontSee('My private line of enquiry');
    }

    /**
     * An administrator can read every *board*. A private conversation is not a
     * board, and BoardAccess::constrain waves administrators through, so the
     * workspace thread needs its own ownership rule. This is that rule.
     */
    public function test_an_administrator_cannot_read_another_persons_workspace_conversation(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();
        $admin = $this->admin();
        $this->boardWithColumns([$team, $admin]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal')
            ->call('selectScope', 'workspace')
            ->set('draft', 'Something I asked in confidence')
            ->call('send');

        $this->assertSame(2, AiChatMessage::query()->whereNull('board_id')->count());

        Livewire::actingAs($admin)
            ->test(Assistant::class)
            ->call('reveal')
            ->call('selectScope', 'workspace')
            ->assertOk()
            ->assertDontSee('Something I asked in confidence');

        // And not in SQL either.
        $this->assertSame(
            0,
            AiChatMessage::query()->visibleTo($admin)->whereNull('board_id')->count(),
        );
    }

    public function test_a_proposal_cannot_be_confirmed_from_a_different_scope(): void
    {
        $provider = $this->fakeAiProvider();
        $provider->willPropose(AiActionType::CreateTicket->toolName(), ['title' => 'Cross-scope']);

        $team = $this->teamMember();
        $one = $this->boardWithColumns([$team], ['slug' => 'one']);
        $this->boardWithColumns([$team], ['slug' => 'two']);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $one->slug)
            ->set('draft', 'Draft it')
            ->call('send');

        $message = AiChatMessage::query()
            ->where('role', AiChatRole::Assistant->value)
            ->sole();

        // Pointed at board two, board one's proposal does not resolve.
        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', 'two')
            ->call('selectScope', 'two')
            ->call('startConfirming', $message->id)
            ->assertNotFound();

        $this->assertSame(0, Ticket::query()->count());
    }

    // -----------------------------------------------------------------
    // Streaming
    // -----------------------------------------------------------------

    /**
     * Streamed content is assigned with innerHTML by Livewire's client, so an
     * unescaped answer would be stored XSS with extra steps — and the model is
     * repeating text that users wrote.
     */
    public function test_a_streamed_answer_is_escaped_before_it_goes_on_the_wire(): void
    {
        $provider = $this->fakeAiProvider();
        $provider->willReturn('<script>alert(1)</script> done');

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->set('draft', 'Say something dangerous')
            ->call('send');

        // The raw frames, exactly as they went to the browser.
        $streamed = $this->streamedOutput();

        $this->assertNotSame('', $streamed, 'Nothing was streamed, so nothing was asserted.');
        $this->assertStringContainsString('&lt;script&gt;', $streamed);
        $this->assertStringNotContainsString('<script>', $streamed);

        // The stored turn keeps the original text: escaping is a property of
        // the wire, and the transcript is rendered through ContentRenderer,
        // which strips raw HTML on the way out.
        $this->assertStringContainsString(
            '<script>',
            AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole()->content,
        );
    }

    public function test_the_page_context_hint_cannot_name_a_ticket_the_person_cannot_open(): void
    {
        $provider = $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['slug' => 'mine']);

        // An internal ticket on a board this person is not a member of.
        $outsider = $this->teamMember();
        $other = $this->boardWithColumns([$outsider], ['slug' => 'theirs']);
        $hidden = $this->ticketOn($other, $outsider, ['title' => 'Not for you']);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            // A forged hint: their board's slug, their ticket's number.
            ->call('reveal', 'theirs', $hidden->number)
            ->set('draft', 'What is this?')
            ->call('send');

        $payload = $provider->lastPayload();

        $this->assertStringNotContainsString('Not for you', $payload);
        $this->assertStringNotContainsString('CURRENT PAGE', $payload);

        // The forged board did not become the scope either.
        $this->assertSame(0, $other->aiChatMessages()->count());
        $this->assertSame(0, $board->aiChatMessages()->count());
    }
}
