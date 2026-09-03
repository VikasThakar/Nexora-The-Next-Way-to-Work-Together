<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Actions\Boards\AddBoardMember;
use App\Actions\Boards\CreateBoard;
use App\Actions\Boards\DeleteBoard;
use App\Actions\Boards\RemoveBoardMember;
use App\Actions\Boards\UpdateBoard;
use App\Actions\Columns\CreateColumn;
use App\Actions\Columns\DeleteColumn;
use App\Actions\Columns\ReorderColumns;
use App\Actions\Columns\UpdateColumn;
use App\Actions\Comments\DeleteComment;
use App\Actions\Comments\UpdateComment;
use App\Actions\Docs\DeletePage;
use App\Actions\Docs\MovePage;
use App\Actions\Docs\SetPageVisibility;
use App\Actions\Docs\UpdatePage;
use App\Actions\Tickets\DeleteTicket;
use App\Actions\Tickets\LinkTickets;
use App\Actions\Tickets\MoveTicket;
use App\Actions\Tickets\SyncTicketLabels;
use App\Actions\Tickets\UpdateTicket;
use App\Enums\ActivityCategory;
use App\Enums\ActivityType;
use App\Enums\CommentStream;
use App\Enums\TicketEventType;
use App\Enums\TicketLinkType;
use App\Enums\TicketPriority;
use App\Livewire\Boards\Show as BoardShow;
use App\Models\Activity;
use App\Models\Label;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What reaches the workspace activity feed, and what it says.
 *
 * The assertions are deliberately about the stored `description` as well as the
 * type: the description is what the screen renders and what search matches, so
 * a change that quietly turned "moved AQD-1 from To Do to In Progress" back
 * into "updated ticket" would break the feature while leaving every
 * type-only assertion green.
 */
class ActivityLoggingTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Tickets
    // -----------------------------------------------------------------

    public function test_creating_a_ticket_records_an_activity(): void
    {
        $team = $this->teamMember(['name' => 'Vikas Jamariya']);
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team, ['title' => 'Fix Safari login issue']);

        $activity = $this->soleActivity(ActivityType::TicketCreated);

        $this->assertSame('created ticket '.$ticket->key().' "Fix Safari login issue"', $activity->description);
        $this->assertSame(ActivityCategory::Tickets->value, $activity->log_name);
        $this->assertSame((int) $board->getKey(), $activity->board_id);
    }

    public function test_an_activity_records_the_user_who_performed_it(): void
    {
        $team = $this->teamMember(['name' => 'Vikas Jamariya']);
        $board = $this->boardWithColumns([$team]);

        $this->ticketOn($board, $team);

        $activity = $this->soleActivity(ActivityType::TicketCreated);

        $this->assertSame((new User)->getMorphClass(), $activity->causer_type);
        $this->assertSame((int) $team->getKey(), (int) $activity->causer_id);
        $this->assertTrue($activity->actor?->is($team));
    }

    public function test_an_activity_records_the_subject_it_is_about(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team);

        $activity = $this->soleActivity(ActivityType::TicketCreated);

        $this->assertSame((new Ticket)->getMorphClass(), $activity->subject_type);
        $this->assertSame((int) $ticket->getKey(), (int) $activity->subject_id);
        $this->assertTrue($activity->subject?->is($ticket));
    }

    public function test_updating_a_ticket_records_which_fields_changed(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Before']);

        app(UpdateTicket::class)->handle($ticket, [
            'title' => 'After',
            'due_date' => '2026-11-30',
        ], $team);

        $activity = $this->soleActivity(ActivityType::TicketUpdated);

        // One row for one save, naming both fields — not two rows, and not a
        // bare "updated ticket".
        $this->assertStringContainsString('title', $activity->description);
        $this->assertStringContainsString('due date', $activity->description);
        $this->assertStringContainsString($ticket->key(), $activity->description);
    }

    public function test_an_update_that_changes_nothing_records_nothing(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Same']);

        app(UpdateTicket::class)->handle($ticket, ['title' => 'Same'], $team);

        $this->assertSame(0, $this->activityCount(ActivityType::TicketUpdated));
    }

    public function test_deleting_a_ticket_records_an_activity_that_outlives_it(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Doomed']);
        $key = $ticket->key();

        app(DeleteTicket::class)->handle($ticket);

        $activity = $this->soleActivity(ActivityType::TicketDeleted);

        $this->assertSame('deleted ticket '.$key.' "Doomed"', $activity->description);

        // The ticket is gone; the row still knows what it was called.
        $this->assertNull(Ticket::query()->find($ticket->getKey()));
        $this->assertSame($key, $activity->property('ticket.key'));
        $this->assertSame('Doomed', $activity->property('ticket.title'));
    }

    // -----------------------------------------------------------------
    // Movement — the important one
    // -----------------------------------------------------------------

    public function test_moving_a_ticket_records_both_ends_of_the_move(): void
    {
        $team = $this->teamMember(['name' => 'Vikas Jamariya']);
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $from = $this->columnNamed($board, 'To Do');
        $inProgress = $this->columnNamed($board, 'In Progress');

        app(MoveTicket::class)->handle($ticket, $from, 0, $team);
        Activity::query()->delete();

        app(MoveTicket::class)->handle($ticket, $inProgress, 0, $team);

        $activity = $this->soleActivity(ActivityType::TicketMoved);

        $this->assertSame(
            'moved '.$ticket->key().' from To Do to In Progress',
            $activity->description
        );

        // The previous and the new column, both stored.
        $this->assertSame('To Do', $activity->property('from'));
        $this->assertSame('In Progress', $activity->property('to'));
        $this->assertSame((int) $from->getKey(), $activity->property('from_column_id'));
        $this->assertSame((int) $inProgress->getKey(), $activity->property('to_column_id'));
    }

    public function test_a_move_stores_the_column_names_so_they_survive_a_rename(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);
        $target = $this->columnNamed($board, 'In Progress');

        app(MoveTicket::class)->handle($ticket, $target, 0, $team);

        // Rename the column afterwards. History must not be rewritten.
        app(UpdateColumn::class)->handle($target, ['name' => 'Doing']);

        $activity = $this->soleActivity(ActivityType::TicketMoved);

        $this->assertStringContainsString('In Progress', $activity->description);
        $this->assertSame('In Progress', $activity->property('to'));
    }

    public function test_a_drag_on_the_board_records_exactly_one_movement(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);
        $target = $this->columnNamed($board, 'In Progress');

        Activity::query()->delete();

        Livewire::actingAs($team)
            ->test(BoardShow::class, ['board' => $board])
            ->call('moveTicket', $ticket->id, 0, $target->id);

        // One row, from the one detection point. The observer that broadcasts
        // and notifies on the same save must not add a second.
        $this->assertSame(1, $this->activityCount(ActivityType::TicketMoved));
        $this->assertSame(1, Activity::query()->count());
    }

    public function test_the_status_dropdown_and_a_drag_produce_the_same_single_activity(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Activity::query()->delete();

        // Moving through the action directly, as the ticket screen's status
        // dropdown does.
        app(MoveTicket::class)->handle($ticket, $this->columnNamed($board, 'Review'), 0, $team);

        $this->assertSame(1, $this->activityCount(ActivityType::TicketMoved));
    }

    public function test_reordering_inside_one_column_records_no_movement(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);
        $this->ticketOn($board, $team);

        $column = $board->columns()->ordered()->first();

        Activity::query()->delete();

        app(MoveTicket::class)->handle($ticket, $column, 1, $team);

        $this->assertSame(0, $this->activityCount(ActivityType::TicketMoved));
    }

    public function test_a_column_being_deleted_moves_its_tickets_and_says_why(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $source = $ticket->refresh()->column;
        $destination = $this->columnNamed($board, 'Done');

        Activity::query()->delete();

        app(DeleteColumn::class)->handle($source, $destination, $team);

        $move = $this->soleActivity(ActivityType::TicketMoved);
        $this->assertStringContainsString('its column was removed', $move->description);

        $removal = $this->soleActivity(ActivityType::ColumnDeleted);
        $this->assertStringContainsString('removed the column '.$source->name, $removal->description);
        $this->assertStringContainsString('moving its tickets to Done', $removal->description);
    }

    // -----------------------------------------------------------------
    // Individual ticket fields
    // -----------------------------------------------------------------

    public function test_a_priority_change_names_both_values(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['priority' => TicketPriority::Medium->value]);

        app(UpdateTicket::class)->handle($ticket, [
            'priority' => TicketPriority::Critical->value,
        ], $team);

        $activity = $this->soleActivity(ActivityType::TicketPriorityChanged);

        $this->assertSame(
            'changed the priority of '.$ticket->key().' from '
                .TicketPriority::Medium->label().' to '.TicketPriority::Critical->label(),
            $activity->description
        );
    }

    public function test_an_assignment_names_the_person(): void
    {
        $team = $this->teamMember();
        $alex = $this->teamMember(['name' => 'Alex Smith']);
        $board = $this->boardWithColumns([$team, $alex]);
        $ticket = $this->ticketOn($board, $team);

        app(UpdateTicket::class)->handle($ticket, ['assignee_id' => $alex->id], $team);

        $activity = $this->soleActivity(ActivityType::TicketAssigneeChanged);

        $this->assertSame('assigned '.$ticket->key().' to Alex Smith', $activity->description);
        $this->assertSame('Alex Smith', $activity->property('to'));
    }

    public function test_unassigning_reads_as_unassigning(): void
    {
        $team = $this->teamMember();
        $alex = $this->teamMember(['name' => 'Alex Smith']);
        $board = $this->boardWithColumns([$team, $alex]);
        $ticket = $this->ticketOn($board, $team, ['assignee_id' => $alex->id]);

        Activity::query()->delete();

        app(UpdateTicket::class)->handle($ticket, ['assignee_id' => null], $team);

        $activity = $this->soleActivity(ActivityType::TicketAssigneeChanged);

        $this->assertSame('unassigned '.$ticket->key(), $activity->description);
    }

    public function test_a_visibility_change_reads_in_words_not_booleans(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        app(UpdateTicket::class)->handle($ticket, ['customer_visible' => true], $team);

        $activity = $this->soleActivity(ActivityType::TicketVisibilityChanged);

        $this->assertSame('made '.$ticket->key().' visible to the customer', $activity->description);
        $this->assertSame('Customer-visible', $activity->property('to'));
    }

    public function test_label_changes_name_what_was_added_and_removed(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $bug = Label::factory()->create(['board_id' => $board->id, 'name' => 'Bug']);

        app(SyncTicketLabels::class)->handle($ticket, [$bug->id], $team);

        $activity = $this->soleActivity(ActivityType::TicketLabelsChanged);

        $this->assertStringContainsString('added Bug', $activity->description);
    }

    public function test_a_ticket_raised_by_a_customer_is_recorded_as_such(): void
    {
        $customer = $this->customer(['name' => 'Dana Client']);
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $customer, ['title' => 'Cannot log in']);

        $activity = $this->soleActivity(ActivityType::TicketCreated);

        $this->assertTrue($activity->actor?->is($customer));
        $this->assertTrue($activity->property('raised_by_customer'));
        $this->assertSame('created ticket '.$ticket->key().' "Cannot log in"', $activity->description);
    }

    // -----------------------------------------------------------------
    // Ticket links are deliberately not mirrored
    // -----------------------------------------------------------------

    public function test_a_link_between_two_tickets_does_not_double_up_in_the_feed(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $source = $this->ticketOn($board, $team);
        $target = $this->ticketOn($board, $team);

        Activity::query()->delete();

        app(LinkTickets::class)->handle(
            $source,
            $target,
            TicketLinkType::Blocks,
            $team
        );

        // Two ticket_events, one per side, so both timelines read correctly —
        // and nothing in the workspace feed, which would otherwise show the
        // same link twice from opposite ends.
        $linkEvents = TicketEvent::query()
            ->where('type', TicketEventType::LinkChanged)
            ->count();

        $this->assertSame(2, $linkEvents);
        $this->assertSame(0, Activity::query()->count());
    }

    // -----------------------------------------------------------------
    // Boards
    // -----------------------------------------------------------------

    public function test_creating_a_board_records_an_activity(): void
    {
        $admin = $this->admin(['name' => 'Vikas Jamariya']);

        $board = app(CreateBoard::class)->handle(['name' => 'Software for Wedding Invitation'], $admin);

        $activity = $this->soleActivity(ActivityType::BoardCreated);

        $this->assertSame('created the board Software for Wedding Invitation', $activity->description);
        $this->assertSame(ActivityCategory::Boards->value, $activity->log_name);
        $this->assertSame((int) $board->getKey(), $activity->board_id);
        $this->assertTrue($activity->actor?->is($admin));
    }

    public function test_the_default_columns_of_a_new_board_are_not_five_separate_activities(): void
    {
        $admin = $this->admin();

        app(CreateBoard::class)->handle(['name' => 'Quiet Board'], $admin);

        // Creating a board must read as one event, not as one plus a column
        // for every stage of the default workflow.
        $this->assertSame(0, $this->activityCount(ActivityType::ColumnCreated));
        $this->assertSame(1, Activity::query()->count());
    }

    public function test_renaming_a_board_names_both_names(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithMembers([], ['name' => 'Platform']);

        $this->actingAs($admin);

        app(UpdateBoard::class)->handle($board, ['name' => 'Delivery']);

        $activity = $this->soleActivity(ActivityType::BoardUpdated);

        $this->assertSame('renamed the board Platform to Delivery', $activity->description);
        $this->assertSame('Platform', $activity->property('from'));
        $this->assertSame('Delivery', $activity->property('to'));
    }

    public function test_archiving_and_restoring_a_board_are_their_own_activities(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithMembers([], ['name' => 'Platform']);

        $this->actingAs($admin);

        app(UpdateBoard::class)->handle($board, ['archived' => true]);
        app(UpdateBoard::class)->handle($board->refresh(), ['archived' => false]);

        $this->assertSame('archived the board Platform', $this->soleActivity(ActivityType::BoardArchived)->description);
        $this->assertSame('restored the board Platform', $this->soleActivity(ActivityType::BoardRestored)->description);
    }

    public function test_a_board_settings_change_records_the_group_but_never_the_values(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithMembers([], ['name' => 'Platform']);

        $this->actingAs($admin);

        $secret = 'https://hooks.slack.com/services/T000/B000/super-secret';

        $this->slackOn($board, null, $secret);

        $activity = $this->soleActivity(ActivityType::BoardSettingsChanged);

        $this->assertStringContainsString('Slack', $activity->description);
        $this->assertSame(ActivityCategory::Settings->value, $activity->log_name);

        // The whole point: the feed knows something changed, and does not hold
        // a second copy of the credential.
        $encoded = json_encode($activity->properties?->toArray() ?? []);
        $this->assertStringNotContainsString('hooks.slack.com', (string) $encoded);
        $this->assertStringNotContainsString('super-secret', (string) $encoded);
        $this->assertStringNotContainsString('super-secret', $activity->description);
    }

    public function test_deleting_a_board_records_a_workspace_level_activity_that_survives_it(): void
    {
        $admin = $this->admin();
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['name' => 'Platform']);
        $this->ticketOn($board, $team);

        $this->actingAs($admin);

        app(DeleteBoard::class)->handle($board);

        $activity = $this->soleActivity(ActivityType::BoardDeleted);

        $this->assertSame('deleted the board Platform and everything on it', $activity->description);

        // Filed with no board, precisely so the cascade cannot take it.
        $this->assertNull($activity->board_id);
        $this->assertSame('Platform', $activity->property('name'));

        // And everything that was about the board went with the board.
        $this->assertSame(1, Activity::query()->count());
    }

    // -----------------------------------------------------------------
    // Membership
    // -----------------------------------------------------------------

    public function test_adding_and_removing_a_member_are_recorded(): void
    {
        $admin = $this->admin(['name' => 'Alex Smith']);
        $sarah = $this->teamMember(['name' => 'Sarah Lee']);
        $board = $this->boardWithMembers([], ['name' => 'Platform']);

        app(AddBoardMember::class)->handle($board, $sarah, $admin);
        app(RemoveBoardMember::class)->handle($board, $sarah, $admin);

        $added = $this->soleActivity(ActivityType::MemberAdded);
        $removed = $this->soleActivity(ActivityType::MemberRemoved);

        $this->assertSame('added Sarah Lee to the board', $added->description);
        $this->assertSame('removed Sarah Lee from the board', $removed->description);
        $this->assertSame((int) $board->getKey(), $added->board_id);
        $this->assertTrue($added->actor?->is($admin));
        $this->assertSame((new User)->getMorphClass(), $added->subject_type);
    }

    public function test_re_adding_an_existing_member_records_nothing(): void
    {
        $admin = $this->admin();
        $sarah = $this->teamMember();
        $board = $this->boardWithMembers([$sarah]);

        app(AddBoardMember::class)->handle($board, $sarah, $admin);

        $this->assertSame(0, $this->activityCount(ActivityType::MemberAdded));
    }

    // -----------------------------------------------------------------
    // Comments
    // -----------------------------------------------------------------

    public function test_a_comment_is_recorded_without_its_body(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $this->commentOn($ticket, $team, 'This is a private internal note', CommentStream::Internal);

        $activity = $this->soleActivity(ActivityType::CommentCreated);

        $this->assertSame('added an internal note on '.$ticket->key(), $activity->description);
        $this->assertStringNotContainsString('private internal note', $activity->description);

        $encoded = json_encode($activity->properties?->toArray() ?? []);
        $this->assertStringNotContainsString('private internal note', (string) $encoded);
    }

    public function test_a_customer_facing_reply_reads_differently_from_an_internal_note(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);

        $this->commentOn($ticket, $team, 'We are on it', CommentStream::Customer);

        $activity = $this->soleActivity(ActivityType::CommentCreated);

        $this->assertSame('replied to the customer on '.$ticket->key(), $activity->description);
        $this->assertSame(CommentStream::Customer->value, $activity->property('stream'));
    }

    public function test_editing_and_deleting_a_comment_are_recorded(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);
        $comment = $this->commentOn($ticket, $team);

        app(UpdateComment::class)->handle($comment, 'Rewritten', $team);
        app(DeleteComment::class)->handle($comment->refresh(), $team);

        $this->assertSame('edited a comment on '.$ticket->key(), $this->soleActivity(ActivityType::CommentEdited)->description);
        $this->assertSame('deleted a comment on '.$ticket->key(), $this->soleActivity(ActivityType::CommentDeleted)->description);
    }

    public function test_an_edit_that_changes_nothing_records_no_comment_edit(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);
        $comment = $this->commentOn($ticket, $team, 'Unchanged');

        app(UpdateComment::class)->handle($comment, 'Unchanged', $team);

        $this->assertSame(0, $this->activityCount(ActivityType::CommentEdited));
    }

    // -----------------------------------------------------------------
    // Documentation
    // -----------------------------------------------------------------

    public function test_documentation_pages_are_recorded_through_their_lifecycle(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $page = $this->docPageOn($board, $team, ['title' => 'Deployment']);

        app(UpdatePage::class)->handle($page, ['body_md' => 'Run the pipeline'], $team);
        app(SetPageVisibility::class)->handle($page->refresh(), true, $team);
        app(SetPageVisibility::class)->handle($page->refresh(), false, $team);
        app(DeletePage::class)->handle($page->refresh());

        $this->assertSame('created the documentation page "Deployment"', $this->soleActivity(ActivityType::PageCreated)->description);
        $this->assertSame('updated the documentation page "Deployment"', $this->soleActivity(ActivityType::PageUpdated)->description);
        $this->assertSame('published the documentation page "Deployment" to the customer', $this->soleActivity(ActivityType::PagePublished)->description);
        $this->assertSame('made the documentation page "Deployment" internal only', $this->soleActivity(ActivityType::PageRetracted)->description);
        $this->assertSame('deleted the documentation page "Deployment"', $this->soleActivity(ActivityType::PageDeleted)->description);

        $this->assertSame(
            ActivityCategory::Documentation->value,
            $this->soleActivity(ActivityType::PageCreated)->log_name
        );
    }

    public function test_refiling_a_page_under_another_parent_is_recorded(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $parent = $this->docPageOn($board, $team, ['title' => 'Handbook']);
        $page = $this->docPageOn($board, $team, ['title' => 'Onboarding']);

        Activity::query()->delete();

        app(MovePage::class)->handle($page, $parent, 0, $team);

        $activity = $this->soleActivity(ActivityType::PageMoved);

        $this->assertSame('moved the documentation page "Onboarding"', $activity->description);
        $this->assertTrue($activity->actor?->is($team));
    }

    public function test_reordering_a_page_among_its_siblings_is_not_recorded(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $first = $this->docPageOn($board, $team, ['title' => 'First']);
        $this->docPageOn($board, $team, ['title' => 'Second']);

        Activity::query()->delete();

        // Dragging a page up and down a list happens constantly while somebody
        // tidies a tree; only a change of parent is worth a row.
        app(MovePage::class)->handle($first, null, 1, $team);

        $this->assertSame(0, $this->activityCount(ActivityType::PageMoved));
    }

    public function test_deleting_a_parent_page_says_how_much_went_with_it(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $parent = $this->docPageOn($board, $team, ['title' => 'Handbook']);
        $this->docPageOn($board, $team, ['title' => 'Onboarding', 'parent_id' => $parent->id]);

        Activity::query()->delete();

        app(DeletePage::class)->handle($parent->refresh());

        $activity = $this->soleActivity(ActivityType::PageDeleted);

        $this->assertSame('deleted the documentation page "Handbook" and 1 page below it', $activity->description);
        $this->assertSame(2, $activity->property('pages_removed'));
    }

    // -----------------------------------------------------------------
    // Columns
    // -----------------------------------------------------------------

    public function test_adding_and_renaming_a_column_are_recorded_as_settings(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->actingAs($team);

        Activity::query()->delete();

        $column = app(CreateColumn::class)->handle($board, ['name' => 'In Review']);
        app(UpdateColumn::class)->handle($column, ['name' => 'Reviewing']);

        $created = $this->soleActivity(ActivityType::ColumnCreated);
        $renamed = $this->soleActivity(ActivityType::ColumnUpdated);

        $this->assertSame('added the column In Review', $created->description);
        $this->assertSame('renamed the column In Review to Reviewing', $renamed->description);
        $this->assertSame(ActivityCategory::Settings->value, $created->log_name);
    }

    public function test_a_column_save_that_changes_nothing_records_nothing(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $column = $this->columnNamed($board, 'To Do');

        Activity::query()->delete();

        app(UpdateColumn::class)->handle($column, ['name' => 'To Do']);

        $this->assertSame(0, $this->activityCount(ActivityType::ColumnUpdated));
    }

    public function test_reordering_columns_into_the_same_order_records_nothing(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ordered = $board->columns()->ordered()->pluck('id')->all();

        Activity::query()->delete();

        app(ReorderColumns::class)->handle($board, $ordered);
        $this->assertSame(0, $this->activityCount(ActivityType::ColumnsReordered));

        app(ReorderColumns::class)->handle($board, array_reverse($ordered));
        $this->assertSame(1, $this->activityCount(ActivityType::ColumnsReordered));
    }

    // -----------------------------------------------------------------
    // Robustness
    // -----------------------------------------------------------------

    public function test_a_title_that_looks_like_a_package_placeholder_is_stored_literally(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        // The package substitutes :subject.x, :causer.x and :properties.x into
        // every description it writes. A title is user input, so it must not be
        // able to rewrite its own history entry.
        $this->ticketOn($board, $team, ['title' => 'Broken :subject.id and :causer.name']);

        $activity = $this->soleActivity(ActivityType::TicketCreated);

        // The text survives; the tokens that would have triggered substitution
        // do not, so nothing from the row was interpolated into it.
        $this->assertStringContainsString('subject.id', $activity->description);
        $this->assertStringContainsString('causer.name', $activity->description);
        $this->assertStringNotContainsString(':subject.', $activity->description);
        $this->assertStringNotContainsString(':causer.', $activity->description);
        $this->assertStringNotContainsString($team->name, $activity->description);
    }

    public function test_turning_the_logger_off_stops_the_feed_and_breaks_nothing_else(): void
    {
        config(['activitylog.enabled' => false]);

        // ActivityLogStatus reads the config once, when the container first
        // resolves it, so the scoped instance has to go for the change to bite.
        $this->app->forgetScopedInstances();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Still works']);

        app(MoveTicket::class)->handle($ticket, $this->columnNamed($board, 'Done'), 0, $team);
        app(UpdateTicket::class)->handle($ticket, ['priority' => TicketPriority::Critical->value], $team);

        // Nothing in the feed…
        $this->assertSame(0, Activity::query()->count());

        // …and everything else untouched. This is what the switch is for: an
        // import can be run without writing a thousand rows nobody will read,
        // and it must not cost the application its ticket history.
        $this->assertSame('Still works', $ticket->refresh()->title);
        $this->assertSame(TicketPriority::Critical, $ticket->priority);
        $this->assertSame(3, $ticket->events()->count());
    }

    public function test_the_ticket_timeline_still_gets_its_own_events(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        app(MoveTicket::class)->handle($ticket, $this->columnNamed($board, 'Done'), 0, $team);

        // The workspace feed is a mirror, not a replacement: `ticket_events`
        // still backs the per-ticket timeline and every flow metric.
        $this->assertSame(2, $ticket->events()->count());
        $this->assertNotNull($ticket->events()->whereNotNull('to_column_id')->first());
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function soleActivity(ActivityType $type): Activity
    {
        return Activity::query()->where('event', $type->value)->sole();
    }

    private function activityCount(ActivityType $type): int
    {
        return Activity::query()->where('event', $type->value)->count();
    }
}
