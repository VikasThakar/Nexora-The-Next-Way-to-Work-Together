<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Livewire\Search\Palette;
use App\Services\Search\GlobalSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The command palette.
 *
 * What it finds, and — in Tests\Security\GlobalSearchVisibilityTest — what it
 * refuses to find. The split is deliberate: this file is about the feature
 * working, that one is about it not leaking, and neither should be able to be
 * satisfied by the other passing.
 */
class CommandPaletteTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_ticket_is_found_by_its_key(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['ticket_prefix' => 'NL']);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Fix authentication']);

        Livewire::actingAs($team)
            ->test(Palette::class)
            ->set('term', 'NL-'.$ticket->number)
            ->assertSee('Fix authentication')
            ->assertSee($ticket->key());
    }

    public function test_the_key_lookup_is_case_insensitive_and_tolerates_spacing(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['ticket_prefix' => 'NL']);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Fix authentication']);

        foreach (['nl-'.$ticket->number, 'NL - '.$ticket->number] as $term) {
            Livewire::actingAs($team)
                ->test(Palette::class)
                ->set('term', $term)
                ->assertSee('Fix authentication');
        }
    }

    /**
     * The exact ticket goes first. Ticket::scopeSearch also matches any ticket
     * whose number ends in the digits — correct for a board filter, wrong for
     * somebody who pasted a key.
     */
    public function test_an_exact_key_outranks_a_number_that_merely_matches(): void
    {
        $team = $this->teamMember();
        $wanted = $this->boardWithColumns([$team], ['name' => 'NutriLens', 'ticket_prefix' => 'NL']);
        $other = $this->boardWithColumns([$team], ['name' => 'Marketing', 'ticket_prefix' => 'MK']);

        // Same number on two boards, so the digits alone are ambiguous.
        $target = $this->ticketOn($wanted, $team, ['title' => 'The one asked for']);
        $decoy = $this->ticketOn($other, $team, ['title' => 'The other one']);

        $this->assertSame($target->number, $decoy->number, 'Both boards number from 1.');

        $html = Livewire::actingAs($team)
            ->test(Palette::class)
            ->set('term', 'NL-'.$target->number)
            ->html();

        $this->assertLessThan(
            strpos($html, 'The other one'),
            strpos($html, 'The one asked for'),
            'The exactly named ticket should be listed first.'
        );
    }

    public function test_tickets_are_found_by_title_and_by_description(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->ticketOn($board, $team, ['title' => 'Dashboard issue']);
        $this->ticketOn($board, $team, [
            'title' => 'Something else',
            'description_md' => 'The chart on the **dashboard** renders empty.',
        ]);

        Livewire::actingAs($team)
            ->test(Palette::class)
            ->set('term', 'dashboard')
            ->assertSee('Dashboard issue')
            ->assertSee('Something else');
    }

    public function test_documentation_boards_and_people_are_found(): void
    {
        $team = $this->teamMember(['name' => 'Vikas Fell']);
        $board = $this->boardWithColumns([$team], ['name' => 'NutriLens', 'ticket_prefix' => 'NL']);

        $this->docPageOn($board, $team, ['title' => 'Authentication guide']);

        $html = Livewire::actingAs($team)
            ->test(Palette::class)
            ->set('term', 'auth')
            ->assertSee('Authentication guide')
            ->html();

        // Each category is announced, so a mixed list is readable.
        $this->assertStringContainsString('Documentation', $html);

        Livewire::actingAs($team)
            ->test(Palette::class)
            ->set('term', 'NutriLens')
            ->assertSee('NutriLens')
            ->assertSee('Boards');

        Livewire::actingAs($team)
            ->test(Palette::class)
            ->set('term', 'Vikas')
            ->assertSee('Vikas Fell')
            ->assertSee('People');
    }

    public function test_a_single_character_searches_nothing_and_says_so(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->ticketOn($board, $team, ['title' => 'Aardvark']);

        Livewire::actingAs($team)
            ->test(Palette::class)
            ->set('term', 'A')
            ->assertDontSee('Aardvark')
            ->assertSee('Keep typing');
    }

    public function test_an_empty_palette_costs_no_queries(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->ticketOn($board, $team, ['title' => 'Something']);

        $component = Livewire::actingAs($team)->test(Palette::class);

        DB::enableQueryLog();

        $component->set('term', '');

        $searches = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains((string) $query['query'], 'like'))
            ->count();

        DB::disableQueryLog();

        // The palette sits in the shell of every page. Rendering it closed must
        // not scan four tables.
        $this->assertSame(0, $searches);
    }

    /**
     * One query per category, whatever the results contain.
     */
    public function test_a_search_does_not_grow_a_query_per_result(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['name' => 'Alpha board']);

        foreach (range(1, 6) as $n) {
            $this->ticketOn($board, $team, ['title' => 'Alpha ticket '.$n]);
            $this->docPageOn($board, $team, ['title' => 'Alpha page '.$n]);
        }

        $component = Livewire::actingAs($team)->test(Palette::class);

        DB::enableQueryLog();

        $component->set('term', 'alpha');

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        // Four categories, plus the board resolution the people query needs.
        // The point is the ceiling, not the exact number: rendering the board
        // name beside each of five tickets must not cost five more queries.
        $this->assertLessThanOrEqual(10, $count, 'The palette should not have an N+1.');
    }

    public function test_the_result_count_per_category_is_capped(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        foreach (range(1, GlobalSearch::PER_GROUP + 4) as $n) {
            $this->ticketOn($board, $team, ['title' => 'Repeated title '.$n]);
        }

        $groups = app(GlobalSearch::class)->search($team, 'Repeated title');

        $tickets = collect($groups)->firstWhere('key', 'tickets');

        $this->assertNotNull($tickets);
        $this->assertCount(GlobalSearch::PER_GROUP, $tickets['hits']);
    }

    public function test_the_shell_carries_the_palette_and_its_shortcut(): void
    {
        $team = $this->teamMember();
        $this->boardWithColumns([$team]);

        $html = $this->actingAs($team)->get(route('dashboard'))->assertOk()->getContent();

        // The trigger, the store that owns the open state, and the component.
        $this->assertStringContainsString('$store.palette.show()', $html);
        $this->assertStringContainsString('$store.palette.shortcut', $html);
        $this->assertStringContainsString('commandPalette', $html);

        // Keyboard contract.
        $this->assertStringContainsString('keydown.escape', $html);
        $this->assertStringContainsString('keydown.down.prevent', $html);
        $this->assertStringContainsString('keydown.enter.prevent', $html);

        // Announced as a dialog with a labelled listbox, or arrow keys are
        // silent to a screen reader.
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('role="combobox"', $html);
        $this->assertStringContainsString('role="listbox"', $html);
        $this->assertStringContainsString('aria-activedescendant', $html);
    }

    public function test_clearing_empties_the_box_without_closing_the_palette(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->ticketOn($board, $team, ['title' => 'Findable']);

        Livewire::actingAs($team)
            ->test(Palette::class)
            ->set('term', 'Findable')
            ->assertSee('Findable')
            ->call('clear')
            ->assertSet('term', '')
            ->assertDontSee('Findable');
    }
}
