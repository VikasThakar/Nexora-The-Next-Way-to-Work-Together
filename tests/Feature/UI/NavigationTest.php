<?php

declare(strict_types=1);

namespace Tests\Feature\UI;

use App\Livewire\Settings\Index;
use App\Models\DocPage;
use App\Services\DocPageFinder;
use App\Support\Breadcrumbs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The application shell: breadcrumb trails, the sidebar and the settings index.
 *
 * Two kinds of test, and as in DialogTest the scan is the durable one. The
 * rendering tests say the trails are right today; the scan says nothing has
 * gone back to hand-building a trail out of anchors and slashes, which is what
 * made them two levels deep and mutually inconsistent in the first place.
 */
class NavigationTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Breadcrumb trails
    // -----------------------------------------------------------------

    public function test_a_trail_accumulates_rather_than_replacing_earlier_levels(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['name' => 'NutriLens', 'ticket_prefix' => 'NL']);
        $ticket = $this->ticketOn($board, $team);

        $labels = array_column(Breadcrumbs::ticket($ticket->load('board')), 'label');

        // Dashboard / Boards / NutriLens / NL / NL-1 — every level above the
        // ticket is still named, which is the whole point of the change.
        $this->assertSame(['Dashboard', 'Boards', 'NutriLens', 'NL', $ticket->key()], $labels);
    }

    public function test_only_the_last_item_is_marked_as_the_current_page(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['name' => 'NutriLens', 'ticket_prefix' => 'NL']);

        $this->actingAs($team)
            ->get(route('boards.show', $board))
            ->assertOk()
            // One current page, no matter how deep the trail is.
            ->assertSee('aria-current="page"', escape: false)
            ->assertSeeInOrder(['Dashboard', 'Boards', 'NutriLens'], escape: false);
    }

    public function test_the_ticket_prefix_is_an_identifier_rather_than_a_second_link_to_the_board(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['name' => 'NutriLens', 'ticket_prefix' => 'NL']);
        $ticket = $this->ticketOn($board, $team);

        $trail = collect(Breadcrumbs::ticket($ticket->load('board')));

        $this->assertNull($trail->firstWhere('label', 'NL')['href']);
        $this->assertSame(route('boards.show', $board), $trail->firstWhere('label', 'NutriLens')['href']);
    }

    public function test_a_documentation_trail_names_every_ancestor(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['name' => 'NutriLens']);

        $root = $this->docPageOn($board, $team, ['title' => 'Handbook']);
        $child = $this->docPageOn($board, $team, [
            'title' => 'Deployment',
            'parent_id' => $root->getKey(),
        ]);

        $ancestors = app(DocPageFinder::class)->ancestors($child, $team);
        $labels = array_column(Breadcrumbs::docs($board, $ancestors, $child), 'label');

        $this->assertSame(['Dashboard', 'Boards', 'NutriLens', 'Docs', 'Handbook', 'Deployment'], $labels);
    }

    /**
     * The ancestor rule, restated at the level the breadcrumb component sees.
     *
     * DocPageFinder::ancestors() already refuses to return a partial trail —
     * tests/Security/DocumentationVisibilityTest covers that directly. This
     * asserts the builder honours an empty collection rather than going looking
     * for parents of its own, which is the mistake a future edit would make.
     */
    public function test_a_documentation_trail_never_names_an_ancestor_the_viewer_cannot_see(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer], ['name' => 'NutriLens']);

        $internalParent = $this->docPageOn($board, $team, ['title' => 'Confidential root']);
        $child = $this->docPageOn($board, $team, [
            'title' => 'Child',
            'parent_id' => $internalParent->getKey(),
        ]);

        DocPage::query()->whereKey($child->getKey())->update(['customer_visible' => true]);
        $child->refresh();

        $ancestors = app(DocPageFinder::class)->ancestors($child, $customer);
        $labels = array_column(Breadcrumbs::docs($board, $ancestors, $child), 'label');

        $this->assertSame(['Dashboard', 'Boards', 'NutriLens', 'Docs', 'Child'], $labels);
        $this->assertNotContains('Confidential root', $labels);
    }

    /**
     * The durable guard. A page that builds its own trail is a page whose trail
     * will drift, so the shape is enforced rather than trusted.
     */
    public function test_no_page_hand_builds_a_breadcrumb_trail(): void
    {
        $offences = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = $file->getContents();
            $relative = str_replace('\\', '/', $file->getRelativePathname());

            // The separator the old hand-rolled trails used. The breadcrumbs
            // component draws its own, so nothing else should.
            if (str_contains($contents, '<span class="mx-1">/</span>')) {
                $offences[] = $relative.' draws its own breadcrumb separator';
            }

            // page-header still accepts a plain $breadcrumb slot for one-line
            // free text, but no screen should be using it for a trail.
            if (str_contains($contents, '<x-slot:breadcrumb>')) {
                $offences[] = $relative.' uses the breadcrumb slot instead of :trail';
            }
        }

        $this->assertSame([], $offences, "Breadcrumbs belong to App\\Support\\Breadcrumbs:\n".implode("\n", $offences));
    }

    // -----------------------------------------------------------------
    // Sidebar
    // -----------------------------------------------------------------

    public function test_the_sidebar_offers_the_five_main_destinations(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $response = $this->actingAs($team)->get(route('dashboard'))->assertOk();

        $response->assertSee('Dashboard')
            ->assertSee('Boards')
            ->assertSee('Statistics')
            ->assertSee('Documentation')
            ->assertSee('Settings');

        // Both new entries point at something real.
        $response->assertSee(route('docs.index', $board), escape: false)
            ->assertSee(route('settings'), escape: false);
    }

    public function test_documentation_is_reachable_from_the_sidebar_for_a_customer(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        // Docs carry customer-visible pages, so the entry is not staff-only.
        // What a customer then sees inside is DocPageFinder's business.
        $this->actingAs($customer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('docs.index', $board), escape: false);
    }

    public function test_the_documentation_entry_is_omitted_when_the_viewer_has_no_board(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer)
            ->get(route('dashboard'))
            ->assertOk()
            // Nothing to point at, so nothing is offered — rather than a link
            // to a board picker that would 404 or leak a board name.
            ->assertDontSee('Documentation');
    }

    public function test_the_sidebar_still_hides_staff_only_destinations_from_a_customer(): void
    {
        $customer = $this->customer();
        $this->boardWithColumns([$customer]);

        $this->actingAs($customer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('activity'), escape: false)
            ->assertDontSee(route('users.index'), escape: false);
    }

    // -----------------------------------------------------------------
    // Settings index
    // -----------------------------------------------------------------

    public function test_the_settings_index_gathers_the_existing_screens(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->assertOk()
            ->assertSee('Workspace')
            ->assertSee('Members')
            ->assertSee('Integrations')
            ->assertSee('Notifications')
            ->assertSee('Security')
            ->assertSee('Advanced')
            // Links out to what already exists rather than reimplementing it.
            ->assertSee(route('profile.edit'), escape: false)
            ->assertSee(route('users.index'), escape: false)
            ->assertSee(route('boards.settings', $board), escape: false)
            ->assertSee(route('boards.ai-settings', $board), escape: false)
            ->assertSee(route('boards.integrations', $board), escape: false);
    }

    public function test_a_customer_sees_only_the_sections_that_are_theirs(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        Livewire::actingAs($customer)
            ->test(Index::class)
            ->assertOk()
            ->assertSee('Security')
            ->assertSee(route('profile.edit'), escape: false)
            // Integrations and Advanced are internal, and so are the screens
            // behind them.
            ->assertDontSee('Advanced')
            ->assertDontSee(route('boards.ai-settings', $board), escape: false)
            ->assertDontSee(route('boards.integrations', $board), escape: false)
            ->assertDontSee(route('users.index'), escape: false);
    }

    public function test_the_board_selector_refuses_a_board_the_viewer_does_not_have(): void
    {
        $team = $this->teamMember();
        $mine = $this->boardWithColumns([$team], ['name' => 'Mine', 'slug' => 'mine']);
        $someone_elses = $this->boardWithColumns([$this->teamMember()], ['name' => 'Theirs', 'slug' => 'theirs']);

        Livewire::actingAs($team)
            ->test(Index::class)
            ->set('boardSlug', $someone_elses->slug)
            ->assertOk()
            // Falls back to a board they do have rather than honouring the slug
            // or aborting, and the query string is corrected on the way out.
            ->assertSet('boardSlug', $mine->slug)
            ->assertDontSee('Theirs');
    }

    public function test_the_settings_index_survives_a_viewer_with_no_boards(): void
    {
        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->assertOk()
            ->assertSet('boardSlug', '')
            ->assertSee('Security');
    }
}
