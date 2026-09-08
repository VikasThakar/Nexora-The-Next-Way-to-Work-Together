<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Actions\Docs\SetPageVisibility;
use App\Livewire\Search\Palette;
use App\Services\Search\GlobalSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A search box is the most efficient leak in any application.
 *
 * Every other screen shows what somebody navigated to; a search box answers
 * questions about records the asker never had to find. So the rule is absolute:
 * a hit may only exist for something the viewer could already have opened by
 * typing its URL.
 *
 * The tests below attack each category separately, because each one has its own
 * rule and they are not the same rule — a ticket has a flag, a documentation
 * page has a flag AND an ancestor chain, a board has membership, and people are
 * not searchable by a customer at all.
 */
class GlobalSearchVisibilityTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Tickets
    // -----------------------------------------------------------------

    public function test_a_customer_cannot_find_an_internal_ticket(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer], ['ticket_prefix' => 'NL']);

        $internal = $this->ticketOn($board, $team, [
            'title' => 'Rotate the production credentials',
            'customer_visible' => false,
        ]);

        Livewire::actingAs($customer)
            ->test(Palette::class)
            ->set('term', 'production credentials')
            ->assertDontSee('Rotate the production credentials');

        // Not by its key either, which is the shortcut a customer would guess.
        Livewire::actingAs($customer)
            ->test(Palette::class)
            ->set('term', 'NL-'.$internal->number)
            ->assertDontSee('Rotate the production credentials');
    }

    public function test_a_non_member_finds_nothing_on_a_board_they_are_not_on(): void
    {
        $owner = $this->teamMember();
        $outsider = $this->teamMember();
        $board = $this->boardWithColumns([$owner], ['name' => 'Confidential', 'ticket_prefix' => 'CF']);

        $ticket = $this->ticketOn($board, $owner, ['title' => 'Acquisition timeline']);
        $this->docPageOn($board, $owner, ['title' => 'Acquisition runbook']);

        // A staff member, so people and internal content are on the table —
        // but not this board's.
        $groups = app(GlobalSearch::class)->search($outsider, 'Acquisition');

        $this->assertSame([], $groups);

        Livewire::actingAs($outsider)
            ->test(Palette::class)
            ->set('term', 'CF-'.$ticket->number)
            ->assertDontSee('Acquisition timeline');

        // Asserted on the link rather than on the name: the palette echoes the
        // term back in "nothing matches", so the name alone would be in the
        // markup either way.
        Livewire::actingAs($outsider)
            ->test(Palette::class)
            ->set('term', 'Confidential')
            ->assertDontSee(route('boards.show', $board->slug));
    }

    public function test_a_customer_finds_their_own_visible_ticket(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $this->ticketOn($board, $customer, ['title' => 'Login is broken']);

        // The other half of the rule: refusing everything would also pass the
        // tests above.
        Livewire::actingAs($customer)
            ->test(Palette::class)
            ->set('term', 'Login is broken')
            ->assertSee('Login is broken');
    }

    // -----------------------------------------------------------------
    // Documentation
    // -----------------------------------------------------------------

    public function test_a_customer_cannot_find_an_internal_page(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $this->docPageOn($board, $team, ['title' => 'Incident postmortem']);

        Livewire::actingAs($customer)
            ->test(Palette::class)
            ->set('term', 'postmortem')
            ->assertDontSee('Incident postmortem');
    }

    /**
     * The rule that only documentation has, and the reason the palette searches
     * pages through DocPageFinder rather than through a scope of its own.
     */
    public function test_a_published_page_under_an_internal_parent_is_not_findable(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $parent = $this->docPageOn($board, $team, ['title' => 'Internal handbook']);
        $child = $this->docPageOn($board, $team, [
            'title' => 'Escalation matrix',
            'parent_id' => $parent->getKey(),
        ]);

        // Published directly, bypassing the action's own refusal, so the read
        // side is tested on its own rather than on the write side's behalf.
        $child->forceFill(['customer_visible' => true])->save();

        Livewire::actingAs($customer)
            ->test(Palette::class)
            ->set('term', 'Escalation')
            ->assertDontSee('Escalation matrix');

        // Publish the parent and the child becomes reachable — so the test
        // above is about ancestry, not about the page being unfindable full
        // stop.
        app(SetPageVisibility::class)->handle($parent, true, $team);

        Livewire::actingAs($customer)
            ->test(Palette::class)
            ->set('term', 'Escalation')
            ->assertSee('Escalation matrix');
    }

    public function test_a_customer_finds_a_published_page(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $this->publishedPageOn($board, $team, ['title' => 'Service levels']);

        Livewire::actingAs($customer)
            ->test(Palette::class)
            ->set('term', 'Service levels')
            ->assertSee('Service levels');
    }

    // -----------------------------------------------------------------
    // People
    // -----------------------------------------------------------------

    public function test_a_customer_cannot_search_people_at_all(): void
    {
        $team = $this->teamMember(['name' => 'Ada Fell', 'email' => 'ada@example.com']);
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        Livewire::actingAs($customer)
            ->test(Palette::class)
            ->set('term', 'Ada')
            ->assertDontSee('Ada Fell')
            ->assertDontSee('People');

        // Nor by the email address, which is the part worth harvesting.
        Livewire::actingAs($customer)
            ->test(Palette::class)
            ->set('term', 'ada@example.com')
            ->assertDontSee('Ada Fell');
    }

    public function test_a_team_member_finds_only_people_they_share_a_board_with(): void
    {
        $viewer = $this->teamMember(['name' => 'Viewer Vale']);
        $colleague = $this->teamMember(['name' => 'Colleague Crane']);
        $stranger = $this->teamMember(['name' => 'Stranger Crane']);

        $this->boardWithColumns([$viewer, $colleague]);
        $this->boardWithColumns([$stranger]);

        Livewire::actingAs($viewer)
            ->test(Palette::class)
            ->set('term', 'Crane')
            ->assertSee('Colleague Crane')
            ->assertDontSee('Stranger Crane');
    }

    public function test_an_administrator_finds_everybody(): void
    {
        $admin = $this->admin();
        $stranger = $this->teamMember(['name' => 'Stranger Crane']);

        $this->boardWithColumns([$stranger]);

        Livewire::actingAs($admin)
            ->test(Palette::class)
            ->set('term', 'Crane')
            ->assertSee('Stranger Crane');
    }

    public function test_a_team_member_with_no_boards_finds_no_people(): void
    {
        $lonely = $this->teamMember(['name' => 'Lonely Lane']);
        $other = $this->teamMember(['name' => 'Other Lane']);

        $this->boardWithColumns([$other]);

        Livewire::actingAs($lonely)
            ->test(Palette::class)
            ->set('term', 'Lane')
            ->assertDontSee('Other Lane');
    }

    // -----------------------------------------------------------------
    // The box itself
    // -----------------------------------------------------------------

    public function test_a_deactivated_account_searches_nothing(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->ticketOn($board, $team, ['title' => 'Still here']);

        $team->forceFill(['deactivated_at' => now()])->save();

        // BoardAccess::constrain refuses an inactive user outright, so every
        // category comes back empty without this having to be restated.
        $this->assertSame([], app(GlobalSearch::class)->search($team->refresh(), 'Still here'));
    }

    public function test_a_guest_gets_no_palette_in_the_page(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        // The palette is only mounted for a signed-in viewer, so there is no
        // component id for an unauthenticated request to address.
        $this->assertStringNotContainsString('commandPalette', $html);
    }

    /**
     * A term long enough to be a payload is not a term.
     */
    public function test_an_absurd_term_is_bounded_before_it_reaches_the_database(): void
    {
        $team = $this->teamMember();
        $this->boardWithColumns([$team]);

        $term = str_repeat('a', 5000);

        $this->assertSame(120, mb_strlen((string) app(GlobalSearch::class)->normalise($term)));

        Livewire::actingAs($team)
            ->test(Palette::class)
            ->set('term', $term)
            ->assertHasNoErrors();
    }
}
