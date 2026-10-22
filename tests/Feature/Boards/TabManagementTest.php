<?php

declare(strict_types=1);

namespace Tests\Feature\Boards;

use App\Actions\Columns\CreateColumn;
use App\Actions\Tabs\CreateTab;
use App\Actions\Tabs\DeleteTab;
use App\Actions\Tabs\RenameTab;
use App\Actions\Tabs\ReorderTabs;
use App\Enums\ActivityType;
use App\Enums\TicketEventType;
use App\Events\BoardUpdated;
use App\Models\Activity;
use App\Models\BoardColumn;
use App\Models\BoardTab;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Notifications\Slack\TicketMovedToDone;
use App\Notifications\TicketStatusChanged;
use App\Services\TicketActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * The rules of a board's tabs, at the level of the actions — so they hold
 * however a tab is changed, not only from the board's tab strip.
 */
class TabManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_tab_copies_the_columns_of_the_tab_it_is_made_from(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $main = $this->defaultTab($board);

        // Make the source tab's workflow distinctive.
        $this->columnNamed($board, 'Review')->update(['name' => 'With legal']);

        $tab = app(CreateTab::class)->handle($board, '  Backend API ', $main, $team);

        $this->assertSame('Backend API', $tab->name);
        $this->assertSame('backend-api', $tab->slug);
        $this->assertSame(1, $tab->position);

        $this->assertSame(
            $main->columns()->get()->map(fn (BoardColumn $c): array => [$c->name, $c->is_done])->all(),
            $tab->columns()->get()->map(fn (BoardColumn $c): array => [$c->name, $c->is_done])->all(),
        );

        // Its own columns, not shared ones.
        $this->assertSame([], array_intersect(
            $main->columns()->pluck('id')->all(),
            $tab->columns()->pluck('id')->all(),
        ));

        $activity = Activity::query()->where('event', ActivityType::TabCreated->value)->sole();
        $this->assertSame('added the tab Backend API', $activity->description);
    }

    public function test_a_new_tab_with_nothing_to_copy_starts_with_the_standard_columns(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $tab = app(CreateTab::class)->handle($board, 'Website');

        $this->assertSame(
            array_column(BoardColumn::defaults(), 'name'),
            $tab->columns()->pluck('name')->all(),
        );
    }

    public function test_tab_names_are_required_short_and_unique_on_the_board_ignoring_case(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $other = $this->boardWithColumns([$team]);
        $create = app(CreateTab::class);

        foreach (['   ', str_repeat('x', BoardTab::MAX_NAME_LENGTH + 1), 'main', 'MAIN'] as $name) {
            try {
                $create->handle($board, $name);
                $this->fail('"'.$name.'" should have been refused.');
            } catch (RuntimeException) {
                // Refused, as it should be.
            }
        }

        $this->assertSame(1, $board->tabs()->count());

        // The same name on another board is a different tab.
        $this->assertSame('Website', $create->handle($board, 'Website')->name);
        $this->assertSame('Website', $create->handle($other, 'Website')->name);
    }

    public function test_a_slug_is_made_once_and_never_changes_on_rename(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $create = app(CreateTab::class);

        $tab = $create->handle($board, 'Backend API');
        app(RenameTab::class)->handle($tab, 'Server', $team);

        $this->assertSame('Server', $tab->fresh()->name);
        $this->assertSame('backend-api', $tab->fresh()->slug);

        // The old name is free again, but its slug is not: links to the
        // renamed tab keep working.
        $this->assertSame('backend-api-2', $create->handle($board, 'Backend API')->slug);
        $this->assertSame('tab', $create->handle($board, '🚀')->slug);
    }

    public function test_renaming_to_the_same_name_records_nothing_but_a_change_of_case_is_a_rename(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $tab = app(CreateTab::class)->handle($board, 'Website');
        $rename = app(RenameTab::class);

        $rename->handle($tab, 'Website', $team);
        $this->assertSame(0, Activity::query()->where('event', ActivityType::TabRenamed->value)->count());

        $rename->handle($tab, 'website', $team);
        $this->assertSame('website', $tab->fresh()->name);

        $activity = Activity::query()->where('event', ActivityType::TabRenamed->value)->sole();
        $this->assertSame('renamed the tab Website to website', $activity->description);

        $this->expectException(RuntimeException::class);
        $rename->handle($tab, 'Main', $team);
    }

    public function test_reordering_ignores_other_boards_tabs_and_the_leftmost_tab_becomes_the_default(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $other = $this->boardWithColumns([$team]);
        $main = $this->defaultTab($board);
        $website = $this->tabOn($board, 'Website');
        $foreign = $this->defaultTab($other);

        $reorder = app(ReorderTabs::class);

        $reorder->handle($board, [$main->id, $website->id], $team);
        $this->assertSame(0, Activity::query()->where('event', ActivityType::TabsReordered->value)->count());

        $reorder->handle($board, [$foreign->id, $website->id, $main->id], $team);

        $this->assertSame([$website->id, $main->id], $board->tabs()->pluck('id')->all());
        $this->assertTrue($board->fresh()->defaultTab()->is($website));
        $this->assertSame(0, $foreign->fresh()->position);
        $this->assertSame(1, Activity::query()->where('event', ActivityType::TabsReordered->value)->count());
    }

    public function test_an_empty_tab_is_deleted_with_its_columns(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');
        $columnIds = $website->columns()->pluck('id')->all();

        $moved = app(DeleteTab::class)->handle($website, null, $team);

        $this->assertSame(0, $moved);
        $this->assertNull(BoardTab::query()->find($website->id));
        $this->assertSame(0, BoardColumn::query()->whereIn('id', $columnIds)->count());

        $activity = Activity::query()->where('event', ActivityType::TabDeleted->value)->sole();
        $this->assertSame('removed the tab Website', $activity->description);
    }

    public function test_the_only_tab_cannot_be_deleted(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('at least one tab');

        app(DeleteTab::class)->handle($this->defaultTab($board), null, $team);
    }

    public function test_a_tab_holding_tickets_needs_a_destination_on_the_same_board(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $other = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');

        $this->ticketOn($board, $team, ['board_tab_id' => $website->id]);

        $delete = app(DeleteTab::class);

        foreach ([null, $this->defaultTab($other), $website] as $destination) {
            try {
                $delete->handle($website, $destination, $team);
                $this->fail('The deletion should have been refused.');
            } catch (RuntimeException) {
                // Refused.
            }
        }

        $this->assertNotNull(BoardTab::query()->find($website->id));
        $this->assertSame(1, Ticket::query()->inTab($website)->count());
    }

    public function test_deleting_a_tab_moves_its_tickets_by_name_then_done_then_first_column(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $main = $this->defaultTab($board);

        // A tab with a workflow of its own: one shared name, one unknown, one
        // done column under another name.
        $website = $this->tabOn($board, 'Website');
        $website->columns()->delete();
        $backlog = BoardColumn::factory()->inTab($website)->create(['name' => 'Backlog', 'position' => 0]);
        $qa = BoardColumn::factory()->inTab($website)->create(['name' => 'QA', 'position' => 1]);
        $live = BoardColumn::factory()->inTab($website)->done()->create(['name' => 'Live', 'position' => 2]);

        $already = $this->ticketOn($board, $team, ['title' => 'Already in Main']);
        $first = $this->ticketOn($board, $team, ['title' => 'Named', 'board_column_id' => $backlog->id]);
        $second = $this->ticketOn($board, $team, ['title' => 'Unknown column', 'board_column_id' => $qa->id]);
        $third = $this->ticketOn($board, $team, ['title' => 'Finished', 'board_column_id' => $live->id]);

        $moved = app(DeleteTab::class)->handle($website, $main, $team);

        $this->assertSame(3, $moved);
        $this->assertSame('Backlog', $first->fresh()->column->name);
        $this->assertSame('Backlog', $second->fresh()->column->name);
        $this->assertSame('Done', $third->fresh()->column->name);

        // Appended after what was already there, in the order they had.
        $this->assertSame(
            [$already->id, $first->id, $second->id],
            Ticket::query()->where('board_column_id', $this->columnNamed($board, 'Backlog', $main)->id)->ordered()->pluck('id')->all(),
        );

        $event = TicketEvent::query()->where('ticket_id', $third->id)->where('type', TicketEventType::TicketMoved->value)->sole();

        $this->assertSame('Website', $event->payload['from_tab']);
        $this->assertSame('Main', $event->payload['to_tab']);
        $this->assertSame(TicketActivity::REASON_TAB_DELETED, $event->payload['reason']);
        $this->assertSame($live->id, (int) $event->from_column_id);
        $this->assertSame($this->columnNamed($board, 'Done', $main)->id, (int) $event->to_column_id);

        $activity = Activity::query()->where('event', ActivityType::TabDeleted->value)->sole();
        $this->assertSame('removed the tab Website, moving its 3 tickets to Main', $activity->description);
    }

    public function test_deleting_a_tab_tells_nobody_about_each_card_it_moves(): void
    {
        $team = $this->teamMember();
        $reporter = $this->teamMember();
        $board = $this->slackOn($this->boardWithColumns([$team, $reporter]));
        $website = $this->tabOn($board, 'Website');

        $ticket = $this->ticketOn($board, $reporter, [
            'board_column_id' => $this->columnNamed($board, 'Done', $website)->id,
            'assignee_id' => $reporter->id,
        ]);

        Notification::fake();

        app(DeleteTab::class)->handle($website, $this->defaultTab($board), $team);

        $this->assertSame('Done', $ticket->fresh()->column->name);

        // Already finished: not announced as finished again, and nobody's bell rings.
        Notification::assertNotSentTo(new AnonymousNotifiable, TicketMovedToDone::class);
        Notification::assertNotSentTo($reporter, TicketStatusChanged::class);
    }

    public function test_tab_and_column_changes_wake_the_customer_channel_too(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Event::fake([BoardUpdated::class]);

        $tab = app(CreateTab::class)->handle($board, 'Website', $this->defaultTab($board), $team);

        Event::assertDispatched(BoardUpdated::class, fn (BoardUpdated $e): bool => $e->audience === BoardUpdated::AUDIENCE_CUSTOMER);
        Event::assertDispatched(BoardUpdated::class, fn (BoardUpdated $e): bool => $e->audience === BoardUpdated::AUDIENCE_INTERNAL);

        Event::fake([BoardUpdated::class]);

        app(CreateColumn::class)->handle($tab, ['name' => 'QA'], $team);

        Event::assertDispatched(BoardUpdated::class, fn (BoardUpdated $e): bool => $e->audience === BoardUpdated::AUDIENCE_CUSTOMER);
    }

    public function test_deleting_a_tab_with_many_tickets_wakes_each_audience_once(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');

        foreach (range(1, 4) as $n) {
            $this->ticketOn($board, $team, ['board_tab_id' => $website->id, 'customer_visible' => true]);
        }

        Event::fake([BoardUpdated::class]);

        app(DeleteTab::class)->handle($website, $this->defaultTab($board), $team);

        Event::assertDispatchedTimes(BoardUpdated::class, 2);
    }

    public function test_a_column_name_may_repeat_in_another_tab_but_not_in_its_own(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');

        $create = app(CreateColumn::class);

        $create->handle($website, ['name' => 'Staging'], $team);
        $create->handle($this->defaultTab($board), ['name' => 'Staging'], $team);

        $this->assertSame(2, $board->columns()->where('name', 'Staging')->count());

        $this->expectException(RuntimeException::class);
        $create->handle($website, ['name' => 'staging'], $team);
    }
}
