<?php

declare(strict_types=1);

namespace Tests\Feature\Boards;

use App\Actions\Boards\CreateBoard;
use App\Events\BoardCreated;
use App\Events\BoardMemberAdded;
use App\Events\BoardMemberRemoved;
use App\Livewire\Boards\ManageBoard;
use App\Livewire\Boards\Members;
use App\Models\Board;
use App\Models\BoardColumn;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class BoardManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_administrator_can_create_a_board_with_initial_members(): void
    {
        Event::fake([BoardCreated::class, BoardMemberAdded::class]);

        $admin = $this->admin();
        $team = $this->teamMember();
        $customer = $this->customer();

        Livewire::actingAs($admin)
            ->test(ManageBoard::class)
            ->set('name', 'Aqueduct Platform')
            ->set('slug', 'aqueduct-platform')
            ->set('ticket_prefix', 'AQD')
            ->set('description', 'Shared delivery board.')
            ->set('memberIds', [$team->getKey(), $customer->getKey()])
            ->call('save')
            ->assertHasNoErrors();

        $board = Board::query()->where('slug', 'aqueduct-platform')->sole();

        $this->assertSame('AQD', $board->ticket_prefix);
        $this->assertSame($admin->getKey(), $board->created_by_id);
        $this->assertTrue($board->hasMember($team));
        $this->assertTrue($board->hasMember($customer));
        $this->assertFalse($board->hasMember($admin));

        Event::assertDispatched(BoardCreated::class);
        Event::assertDispatchedTimes(BoardMemberAdded::class, 2);
    }

    /**
     * A checkbox sends its value as a string, and the `integer` validation rule
     * accepts "3" without converting it — so this is what the browser actually
     * submits, and what the test above (which passes real ints) never covered.
     *
     * It reached AddBoardMember, which asks for an int, and under strict_types
     * that is a TypeError rather than a coercion: creating any board with a
     * member selected returned a 500.
     */
    public function test_a_board_can_be_created_with_member_ids_submitted_as_strings(): void
    {
        $admin = $this->admin();
        $team = $this->teamMember();
        $customer = $this->customer();

        Livewire::actingAs($admin)
            ->test(ManageBoard::class)
            ->set('name', 'Wedding Invitation of Alex')
            ->set('slug', 'wedding-invitation-of-alex')
            ->set('ticket_prefix', 'WIOA')
            ->set('description', "That's a demo board")
            ->set('memberIds', [(string) $team->getKey(), (string) $customer->getKey()])
            ->call('save')
            ->assertHasNoErrors();

        $board = Board::query()->where('slug', 'wedding-invitation-of-alex')->sole();

        $this->assertTrue($board->hasMember($team));
        $this->assertTrue($board->hasMember($customer));
        $this->assertSame(5, $board->columns()->count());
    }

    /**
     * The same coercion, one layer down: the action is also called by seeders
     * and imports, where ids routinely arrive as strings.
     */
    public function test_the_create_action_accepts_numeric_string_member_ids(): void
    {
        $admin = $this->admin();
        $team = $this->teamMember();

        $board = app(CreateBoard::class)->handle(
            ['name' => 'Imported Board', 'ticket_prefix' => 'IMP'],
            $admin,
            [(string) $team->getKey()],
        );

        $this->assertTrue($board->hasMember($team));
    }

    /**
     * The board and its columns are created in one transaction, so a failure
     * partway through must not leave a board nobody can use behind.
     */
    public function test_a_failed_member_addition_rolls_the_whole_board_back(): void
    {
        $admin = $this->admin();

        $this->assertSame(0, Board::query()->count());

        try {
            app(CreateBoard::class)->handle(
                ['name' => 'Doomed Board', 'ticket_prefix' => 'DOOM'],
                $admin,
                [999999],
            );

            $this->fail('Adding a nonexistent member should have failed.');
        } catch (ModelNotFoundException) {
            // Expected.
        }

        $this->assertSame(0, Board::query()->count(), 'The board should not survive a failed creation.');
        $this->assertSame(0, BoardColumn::query()->count());
    }

    public function test_a_deactivated_user_cannot_be_chosen_as_an_initial_member(): void
    {
        $admin = $this->admin();
        $dormant = $this->teamMember(['deactivated_at' => now()]);

        Livewire::actingAs($admin)
            ->test(ManageBoard::class)
            ->set('name', 'New Board')
            ->set('slug', 'new-board')
            ->set('ticket_prefix', 'NEW')
            ->set('memberIds', [(string) $dormant->getKey()])
            ->call('save')
            ->assertHasErrors('memberIds.0');

        $this->assertSame(0, Board::query()->count());
    }

    public function test_the_slug_and_prefix_are_suggested_from_the_name(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ManageBoard::class)
            ->set('name', 'Customer Success Portal')
            ->assertSet('slug', 'customer-success-portal')
            ->assertSet('ticket_prefix', 'CSP');
    }

    public function test_board_slugs_and_prefixes_must_be_unique(): void
    {
        Board::factory()->create(['slug' => 'taken-slug', 'ticket_prefix' => 'TKN']);

        Livewire::actingAs($this->admin())
            ->test(ManageBoard::class)
            ->set('name', 'Another Board')
            ->set('slug', 'taken-slug')
            ->set('ticket_prefix', 'TKN')
            ->call('save')
            ->assertHasErrors(['slug', 'ticket_prefix']);
    }

    public function test_the_ticket_prefix_must_be_uppercase_alphanumeric(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ManageBoard::class)
            ->set('name', 'Board One')
            ->set('slug', 'board-one')
            ->set('ticket_prefix', 'a b')
            ->call('save')
            ->assertHasErrors('ticket_prefix');
    }

    public function test_the_board_settings_screen_renders_for_an_administrator(): void
    {
        $board = Board::factory()->create();

        $this->actingAs($this->admin())
            ->withConfirmedPassword()
            ->get(route('boards.edit', $board))
            ->assertOk()
            ->assertSee($board->name)
            ->assertSee($board->ticket_prefix);
    }

    public function test_an_administrator_can_rename_and_archive_a_board(): void
    {
        $board = Board::factory()->create(['name' => 'Old Name']);

        Livewire::actingAs($this->admin())
            ->test(ManageBoard::class, ['board' => $board])
            ->set('name', 'New Name')
            ->set('archived', true)
            ->call('save')
            ->assertHasNoErrors();

        $board->refresh();

        $this->assertSame('New Name', $board->name);
        $this->assertTrue($board->isArchived());
    }

    public function test_archived_boards_are_hidden_from_the_default_index(): void
    {
        $admin = $this->admin();

        $active = Board::factory()->create(['name' => 'Active Board']);
        $archived = Board::factory()->archived()->create(['name' => 'Archived Board']);

        $this->actingAs($admin)
            ->get(route('boards.index'))
            ->assertOk()
            ->assertSee($active->name)
            ->assertDontSee($archived->name);
    }

    public function test_the_board_index_paginates(): void
    {
        Board::factory()->count(15)->create();

        $this->actingAs($this->admin())
            ->get(route('boards.index'))
            ->assertOk()
            ->assertSee('aria-label="Pagination Navigation"', escape: false)
            ->assertSee('Next')
            ->assertSee('nextPage', escape: false);
    }

    public function test_an_administrator_can_add_and_remove_board_members(): void
    {
        Event::fake([BoardMemberAdded::class, BoardMemberRemoved::class]);

        $admin = $this->admin();
        $board = Board::factory()->create();
        $team = $this->teamMember();

        $component = Livewire::actingAs($admin)
            ->test(Members::class, ['board' => $board])
            ->set('userId', (string) $team->getKey())
            ->call('addMember')
            ->assertHasNoErrors();

        $this->assertTrue($board->fresh()->hasMember($team));
        Event::assertDispatched(BoardMemberAdded::class);

        $component->call('removeMember', $team->getKey());

        $this->assertFalse($board->fresh()->hasMember($team));
        Event::assertDispatched(BoardMemberRemoved::class);
    }

    public function test_adding_the_same_member_twice_is_idempotent(): void
    {
        $board = Board::factory()->create();
        $team = $this->teamMember();

        $component = Livewire::actingAs($this->admin())->test(Members::class, ['board' => $board]);

        $component->set('userId', (string) $team->getKey())->call('addMember');
        $component->set('userId', (string) $team->getKey())->call('addMember');

        $this->assertSame(1, $board->memberships()->where('user_id', $team->getKey())->count());
    }

    public function test_a_deactivated_user_cannot_be_added_as_a_member(): void
    {
        $board = Board::factory()->create();
        $deactivated = $this->teamMember(['deactivated_at' => now()]);

        Livewire::actingAs($this->admin())
            ->test(Members::class, ['board' => $board])
            ->set('userId', (string) $deactivated->getKey())
            ->call('addMember')
            ->assertHasErrors('userId');

        $this->assertFalse($board->fresh()->hasMember($deactivated));
    }
}
