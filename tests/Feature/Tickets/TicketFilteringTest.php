<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Enums\TicketPriority;
use App\Livewire\Boards\Show as BoardShow;
use App\Services\TicketFinder;
use App\Support\TicketFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TicketFilteringTest extends TestCase
{
    use RefreshDatabase;

    public function test_tickets_can_be_filtered_by_label(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $bug = $board->labels()->create(['name' => 'bug', 'color' => 'rose']);
        $feature = $board->labels()->create(['name' => 'feature', 'color' => 'brand']);

        $bugTicket = $this->ticketOn($board, $team, ['title' => 'Crash on save', 'label_ids' => [$bug->id]]);
        $featureTicket = $this->ticketOn($board, $team, ['title' => 'Add export', 'label_ids' => [$feature->id]]);
        $this->ticketOn($board, $team, ['title' => 'Unlabelled work']);

        $results = app(TicketFinder::class)->forBoard($board, $team, new TicketFilters(labelIds: [$bug->id]));

        $this->assertEquals(['Crash on save'], $results->pluck('title')->all());
    }

    public function test_filtering_by_several_labels_matches_any_of_them(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $bug = $board->labels()->create(['name' => 'bug', 'color' => 'rose']);
        $feature = $board->labels()->create(['name' => 'feature', 'color' => 'brand']);

        $this->ticketOn($board, $team, ['title' => 'A', 'label_ids' => [$bug->id]]);
        $this->ticketOn($board, $team, ['title' => 'B', 'label_ids' => [$feature->id]]);
        $this->ticketOn($board, $team, ['title' => 'C']);

        $results = app(TicketFinder::class)
            ->forBoard($board, $team, new TicketFilters(labelIds: [$bug->id, $feature->id]));

        $this->assertEqualsCanonicalizing(['A', 'B'], $results->pluck('title')->all());
    }

    public function test_a_label_from_another_board_matches_nothing(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $other = $this->boardWithColumns([$team]);
        $foreignLabel = $other->labels()->create(['name' => 'foreign', 'color' => 'slate']);

        $this->ticketOn($board, $team, ['title' => 'Local work']);

        $results = app(TicketFinder::class)
            ->forBoard($board, $team, new TicketFilters(labelIds: [$foreignLabel->id]));

        $this->assertCount(0, $results);
    }

    public function test_the_label_filter_works_through_the_board_component(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $bug = $board->labels()->create(['name' => 'bug', 'color' => 'rose']);

        $this->ticketOn($board, $team, ['title' => 'Labelled item', 'label_ids' => [$bug->id]]);
        $this->ticketOn($board, $team, ['title' => 'Plain item']);

        Livewire::actingAs($team)
            ->test(BoardShow::class, ['board' => $board])
            ->set('labelIds', [$bug->id])
            ->assertSee('Labelled item')
            ->assertDontSee('Plain item');
    }

    public function test_tickets_can_be_filtered_by_assignee_and_by_being_unassigned(): void
    {
        $team = $this->teamMember();
        $other = $this->teamMember();
        $board = $this->boardWithColumns([$team, $other]);

        $this->ticketOn($board, $team, ['title' => 'Mine', 'assignee_id' => $team->id]);
        $this->ticketOn($board, $team, ['title' => 'Theirs', 'assignee_id' => $other->id]);
        $this->ticketOn($board, $team, ['title' => 'Nobody']);

        $finder = app(TicketFinder::class);

        $this->assertEquals(
            ['Mine'],
            $finder->forBoard($board, $team, new TicketFilters(assignee: (string) $team->id))->pluck('title')->all()
        );

        $this->assertEquals(
            ['Nobody'],
            $finder->forBoard($board, $team, new TicketFilters(assignee: TicketFilters::UNASSIGNED))->pluck('title')->all()
        );
    }

    public function test_tickets_can_be_filtered_by_priority(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->ticketOn($board, $team, ['title' => 'Urgent', 'priority' => TicketPriority::Critical]);
        $this->ticketOn($board, $team, ['title' => 'Whenever', 'priority' => TicketPriority::NiceToHave]);

        $results = app(TicketFinder::class)->forBoard(
            $board,
            $team,
            new TicketFilters(priorities: [TicketPriority::Critical->value])
        );

        $this->assertEquals(['Urgent'], $results->pluck('title')->all());
    }

    public function test_staff_can_filter_to_internal_or_customer_visible_work(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->ticketOn($board, $team, ['title' => 'Behind the scenes']);
        $this->ticketOn($board, $team, ['title' => 'On show', 'customer_visible' => true]);

        $finder = app(TicketFinder::class);

        $this->assertEquals(
            ['Behind the scenes'],
            $finder->forBoard($board, $team, new TicketFilters(visibility: TicketFilters::VISIBILITY_INTERNAL))
                ->pluck('title')->all()
        );

        $this->assertEquals(
            ['On show'],
            $finder->forBoard($board, $team, new TicketFilters(visibility: TicketFilters::VISIBILITY_CUSTOMER))
                ->pluck('title')->all()
        );
    }

    public function test_text_search_matches_title_description_and_number(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['ticket_prefix' => 'ZZZ']);

        $first = $this->ticketOn($board, $team, ['title' => 'Pelican migration', 'description_md' => 'Nothing here']);
        $this->ticketOn($board, $team, ['title' => 'Unrelated', 'description_md' => 'mentions pelican inside']);
        $this->ticketOn($board, $team, ['title' => 'Third']);

        $finder = app(TicketFinder::class);

        $this->assertCount(2, $finder->forBoard($board, $team, new TicketFilters(search: 'pelican')));

        $this->assertEquals(
            ['Pelican migration'],
            $finder->forBoard($board, $team, new TicketFilters(search: 'ZZZ-'.$first->number))->pluck('title')->all()
        );
    }

    public function test_filters_combine(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $bug = $board->labels()->create(['name' => 'bug', 'color' => 'rose']);

        $this->ticketOn($board, $team, [
            'title' => 'Critical bug assigned',
            'priority' => TicketPriority::Critical,
            'assignee_id' => $team->id,
            'label_ids' => [$bug->id],
        ]);
        $this->ticketOn($board, $team, [
            'title' => 'Critical bug unassigned',
            'priority' => TicketPriority::Critical,
            'label_ids' => [$bug->id],
        ]);

        $results = app(TicketFinder::class)->forBoard($board, $team, new TicketFilters(
            assignee: (string) $team->id,
            priorities: [TicketPriority::Critical->value],
            labelIds: [$bug->id],
        ));

        $this->assertEquals(['Critical bug assigned'], $results->pluck('title')->all());
    }

    public function test_unknown_filter_values_are_discarded_rather_than_trusted(): void
    {
        $filters = TicketFilters::fromArray([
            'priorities' => ['critical', 'not-a-priority'],
            'visibility' => 'everything-please',
            'labelIds' => ['3', 'abc', 0],
            'search' => '  spaced  ',
        ]);

        $this->assertSame(['critical'], $filters->priorities);
        $this->assertSame(TicketFilters::VISIBILITY_ALL, $filters->visibility);
        $this->assertSame([3], $filters->labelIds);
        $this->assertSame('spaced', $filters->search);
    }

    public function test_clearing_filters_restores_the_whole_board(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->ticketOn($board, $team, ['title' => 'Alpha']);
        $this->ticketOn($board, $team, ['title' => 'Beta']);

        Livewire::actingAs($team)
            ->test(BoardShow::class, ['board' => $board])
            ->set('search', 'Alpha')
            ->assertDontSee('Beta')
            ->call('clearFilters')
            ->assertSee('Alpha')
            ->assertSee('Beta');
    }
}
