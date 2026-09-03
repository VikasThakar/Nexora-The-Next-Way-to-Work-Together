<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Actions\Boards\DeleteBoard;
use App\Actions\Boards\RemoveBoardMember;
use App\Actions\Tickets\MoveTicket;
use App\Enums\ActivityType;
use App\Enums\TicketEventType;
use App\Livewire\Activity\Index as ActivityIndex;
use App\Models\Activity;
use App\Services\ActivityLogger;
use App\Services\ActivityReader;
use App\Services\BoardAccess;
use App\Support\ActivityFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Who may read the workspace activity feed, and what they get.
 *
 * The feed is the most concentrated internal data in the application: one table
 * with a readable sentence per change across every board, naming internal
 * tickets, internal notes, membership and configuration. So the rules are
 * asserted at both levels — the screen, and the query underneath it — because
 * a screen that refuses politely while the query would have returned the rows
 * is one careless route away from a leak.
 */
class ActivityVisibilityTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Reaching the screen at all
    // -----------------------------------------------------------------

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('activity'))->assertRedirect(route('login'));
    }

    public function test_a_customer_cannot_reach_the_activity_screen(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $this->ticketOn($board, $customer);

        /*
         * 403, from the `role:admin,team` middleware — the same answer
         * /stats gives a customer, and deliberately the same: this is one
         * gate applied identically to both delivery-team screens.
         *
         * The 404 the brief prefers is what the component itself raises, which
         * is the path a Livewire request takes when it never passes through the
         * route. See the next test.
         */
        $this->actingAs($customer)->get(route('activity'))->assertForbidden();
    }

    public function test_a_customer_mounting_the_component_directly_is_refused(): void
    {
        $customer = $this->customer();
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team, $customer]);
        $this->ticketOn($board, $team, ['title' => 'Internal work', 'customer_visible' => false]);

        // The route middleware is not the only gate: a Livewire request can
        // mount a component without ever passing through it.
        Livewire::actingAs($customer)
            ->test(ActivityIndex::class)
            ->assertStatus(404);
    }

    public function test_a_deactivated_team_member_cannot_reach_the_screen(): void
    {
        $team = $this->teamMember(['deactivated_at' => now()]);
        $board = $this->boardWithColumns([$team]);

        $this->actingAs($team)->get(route('activity'))->assertRedirect(route('login'));
    }

    public function test_an_admin_and_a_team_member_can_reach_the_screen(): void
    {
        $admin = $this->admin();
        $team = $this->teamMember();
        $this->boardWithColumns([$team]);

        $this->actingAs($admin)->get(route('activity'))->assertOk();
        $this->actingAs($team)->get(route('activity'))->assertOk();
    }

    // -----------------------------------------------------------------
    // The query, independently of the screen
    // -----------------------------------------------------------------

    public function test_the_query_returns_nothing_at_all_for_a_customer(): void
    {
        $customer = $this->customer();
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team, $customer]);

        // Including a ticket the customer is allowed to read. Even then, the
        // activity feed is not for them: it is written in the delivery team's
        // voice and the whole table is refused.
        $this->ticketOn($board, $team, ['customer_visible' => true]);
        $this->ticketOn($board, $team, ['customer_visible' => false]);

        $this->assertGreaterThan(0, Activity::query()->count());
        $this->assertSame(0, Activity::query()->readableBy($customer)->count());
    }

    public function test_the_query_returns_nothing_for_an_unauthenticated_reader(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->ticketOn($board, $team);

        $this->assertSame(0, Activity::query()->readableBy(null)->count());
    }

    public function test_the_query_returns_nothing_for_a_deactivated_user(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->ticketOn($board, $team);

        $team->forceFill(['deactivated_at' => now()])->save();
        app(BoardAccess::class)->flush();

        $this->assertSame(0, Activity::query()->readableBy($team->refresh())->count());
    }

    // -----------------------------------------------------------------
    // Board scoping
    // -----------------------------------------------------------------

    public function test_a_team_member_only_sees_activity_from_boards_they_belong_to(): void
    {
        $mine = $this->teamMember();
        $stranger = $this->teamMember();

        $myBoard = $this->boardWithColumns([$mine], ['name' => 'My Board']);
        $theirBoard = $this->boardWithColumns([$stranger], ['name' => 'Their Board']);

        $myTicket = $this->ticketOn($myBoard, $mine, ['title' => 'My work']);
        $theirTicket = $this->ticketOn($theirBoard, $stranger, ['title' => 'Their work']);

        $readable = Activity::query()->readableBy($mine)->pluck('board_id')->unique()->all();

        $this->assertSame([(int) $myBoard->getKey()], array_map('intval', $readable));

        // And nothing about the other board reaches the rendered page — not the
        // ticket key, not the title, not the board name.
        Livewire::actingAs($mine)
            ->test(ActivityIndex::class)
            ->assertSee('My work')
            ->assertDontSee('Their work')
            ->assertDontSee($theirTicket->key())
            ->assertDontSee('Their Board');
    }

    public function test_losing_board_membership_hides_the_history_of_that_board(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->ticketOn($board, $team, ['title' => 'Was visible']);

        $this->assertGreaterThan(0, Activity::query()->readableBy($team)->count());

        app(RemoveBoardMember::class)->handle($board, $team);

        $this->assertSame(0, Activity::query()->readableBy($team->refresh())->count());

        Livewire::actingAs($team)
            ->test(ActivityIndex::class)
            ->assertDontSee('Was visible');
    }

    public function test_an_administrator_sees_every_board(): void
    {
        $admin = $this->admin();
        $one = $this->teamMember();
        $two = $this->teamMember();

        $boardOne = $this->boardWithColumns([$one]);
        $boardTwo = $this->boardWithColumns([$two]);

        $this->ticketOn($boardOne, $one, ['title' => 'First board work']);
        $this->ticketOn($boardTwo, $two, ['title' => 'Second board work']);

        Livewire::actingAs($admin)
            ->test(ActivityIndex::class)
            ->assertSee('First board work')
            ->assertSee('Second board work');
    }

    public function test_the_board_filter_cannot_be_used_to_read_another_boards_history(): void
    {
        $mine = $this->teamMember();
        $stranger = $this->teamMember();

        $this->boardWithColumns([$mine]);
        $theirBoard = $this->boardWithColumns([$stranger], ['name' => 'Their Board']);

        $this->ticketOn($theirBoard, $stranger, ['title' => 'Their secret work']);

        Livewire::actingAs($mine)
            ->test(ActivityIndex::class)
            ->set('boardSlug', $theirBoard->slug)
            ->assertDontSee('Their secret work')
            ->assertSee('No activity matches');
    }

    public function test_the_person_filter_cannot_be_used_to_read_another_boards_history(): void
    {
        $mine = $this->teamMember();
        $stranger = $this->teamMember(['name' => 'Stranger']);

        $this->boardWithColumns([$mine]);
        $theirBoard = $this->boardWithColumns([$stranger]);

        $this->ticketOn($theirBoard, $stranger, ['title' => 'Their secret work']);

        // A filter narrows; it can never widen past readableBy().
        $component = Livewire::actingAs($mine)
            ->test(ActivityIndex::class)
            ->set('userId', (string) $stranger->getKey());

        $this->assertSame(0, $component->viewData('activities')->total());
        $component->assertDontSee('Their secret work');
    }

    public function test_search_cannot_be_used_to_read_another_boards_history(): void
    {
        $mine = $this->teamMember();
        $stranger = $this->teamMember();

        $this->boardWithColumns([$mine]);
        $theirBoard = $this->boardWithColumns([$stranger], ['name' => 'Their Board']);

        $this->ticketOn($theirBoard, $stranger, ['title' => 'Their secret work']);

        $component = Livewire::actingAs($mine)
            ->test(ActivityIndex::class)
            ->set('search', 'secret');

        $this->assertSame(0, $component->viewData('activities')->total());
    }

    // -----------------------------------------------------------------
    // Workspace-level rows
    // -----------------------------------------------------------------

    public function test_workspace_level_activity_is_administrator_only(): void
    {
        $admin = $this->admin();
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['name' => 'Doomed Board']);

        $this->actingAs($admin);
        app(DeleteBoard::class)->handle($board);

        $row = Activity::query()->where('event', ActivityType::BoardDeleted->value)->sole();
        $this->assertNull($row->board_id);

        // The administrator who can see everything sees it; a team member —
        // who has no board to be a member of any more — does not.
        $this->assertSame(1, Activity::query()->readableBy($admin)->count());
        $this->assertSame(0, Activity::query()->readableBy($team->refresh())->count());
    }

    public function test_a_team_member_never_receives_a_row_with_no_board(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->ticketOn($board, $team);

        // A row that somehow reached the table without a board must not fall
        // through to "visible to everyone" — the rule is stated, not inferred
        // from NULL never matching the membership EXISTS.
        Activity::query()->create([
            'log_name' => 'boards',
            'description' => 'did something workspace-wide',
            'event' => ActivityType::BoardDeleted->value,
            'board_id' => null,
        ]);

        $boardIds = Activity::query()->readableBy($team)->pluck('board_id')->all();

        $this->assertNotContains(null, $boardIds);
    }

    // -----------------------------------------------------------------
    // Secrets and bodies
    // -----------------------------------------------------------------

    public function test_the_feed_never_holds_a_board_credential(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([], ['name' => 'Platform']);

        $this->actingAs($admin);

        $this->slackOn($board, null, 'https://hooks.slack.com/services/T1/B1/leaked-token-value');

        $rows = Activity::query()->get();

        foreach ($rows as $row) {
            $encoded = $row->description.' '.json_encode($row->properties?->toArray() ?? []);

            $this->assertStringNotContainsString('leaked-token-value', $encoded);
            $this->assertStringNotContainsString('hooks.slack.com', $encoded);
        }
    }

    public function test_the_feed_never_holds_a_comment_body(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $this->commentOn($ticket, $team, 'The client is threatening to leave, do not repeat this');

        $rows = Activity::query()->get();

        foreach ($rows as $row) {
            $encoded = $row->description.' '.json_encode($row->properties?->toArray() ?? []);

            $this->assertStringNotContainsString('threatening to leave', $encoded);
        }
    }

    // -----------------------------------------------------------------
    // The declaration that a future customer feed would read
    // -----------------------------------------------------------------

    public function test_every_internal_only_ticket_event_stays_internal_only_when_mirrored(): void
    {
        /*
         * App\Models\Activity::readableBy() refuses non-staff outright, so
         * ActivityType::isInternalOnly() has nothing to gate today. It is the
         * answer a customer-safe feed would need, and this is what stops it
         * drifting away from the rule the per-ticket timeline already applies:
         * anything TicketEventType marks internal must still be internal after
         * the mirror maps it.
         */
        foreach (TicketEventType::cases() as $event) {
            $mapped = ActivityLogger::forTicketEvent($event);

            if ($mapped === null) {
                continue;
            }

            if ($event->isInternalOnly()) {
                $this->assertTrue(
                    $mapped->isInternalOnly(),
                    $event->value.' is internal-only on the ticket timeline but not in the activity feed.'
                );
            }
        }
    }

    public function test_board_management_and_configuration_are_marked_internal_only(): void
    {
        $mustBeInternal = [
            ActivityType::BoardCreated,
            ActivityType::BoardUpdated,
            ActivityType::BoardArchived,
            ActivityType::BoardRestored,
            ActivityType::BoardDeleted,
            ActivityType::MemberAdded,
            ActivityType::MemberRemoved,
            ActivityType::BoardSettingsChanged,
            ActivityType::ColumnCreated,
            ActivityType::ColumnUpdated,
            ActivityType::ColumnDeleted,
            ActivityType::ColumnsReordered,
            ActivityType::TicketVisibilityChanged,
        ];

        foreach ($mustBeInternal as $type) {
            $this->assertTrue($type->isInternalOnly(), $type->value.' must never be customer-facing.');
        }
    }

    // -----------------------------------------------------------------
    // Option lists
    // -----------------------------------------------------------------

    public function test_the_board_dropdown_only_offers_boards_the_viewer_can_reach(): void
    {
        $mine = $this->teamMember();
        $stranger = $this->teamMember();

        $myBoard = $this->boardWithColumns([$mine], ['name' => 'My Board']);
        $theirBoard = $this->boardWithColumns([$stranger], ['name' => 'Their Board']);

        $options = app(ActivityReader::class)->boardOptions($mine);

        $this->assertArrayHasKey($myBoard->slug, $options);
        $this->assertArrayNotHasKey($theirBoard->slug, $options);
    }

    public function test_a_customer_is_offered_no_options_and_no_rows(): void
    {
        $customer = $this->customer();
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team, $customer]);
        $this->ticketOn($board, $team, ['customer_visible' => true]);

        $reader = app(ActivityReader::class);

        $this->assertSame([], $reader->actorOptions($customer));
        $this->assertSame(
            0,
            $reader->paginate($customer, ActivityFilters::fromArray([]))->total()
        );
    }

    // -----------------------------------------------------------------
    // A ticket the viewer could not otherwise read
    // -----------------------------------------------------------------

    public function test_movement_of_an_internal_ticket_is_visible_to_staff_on_that_board(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['customer_visible' => false]);

        app(MoveTicket::class)->handle($ticket, $this->columnNamed($board, 'Done'), 0, $team);

        // Staff on the board see internal work; that is the whole premise of
        // the screen. This is here so the board-scoping assertions above cannot
        // be satisfied by a scope that simply returns nothing to anybody.
        Livewire::actingAs($team)
            ->test(ActivityIndex::class)
            ->assertSee('moved '.$ticket->key().' from Backlog to Done');
    }
}
