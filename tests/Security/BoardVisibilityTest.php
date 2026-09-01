<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Models\Board;
use App\Models\User;
use App\Services\BoardAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Board listings must never contain a board the viewer cannot open.
 *
 * These tests exercise BoardAccess directly, because every list in the product
 * (dashboard, board index, sidebar, and later ticket and documentation
 * queries) is built from it.
 */
class BoardVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private BoardAccess $access;

    protected function setUp(): void
    {
        parent::setUp();

        $this->access = app(BoardAccess::class);
    }

    public function test_a_team_member_only_sees_boards_they_belong_to(): void
    {
        $team = $this->teamMember();

        $mine = $this->boardWithMembers([$team]);
        $theirs = Board::factory()->create();

        $visible = $this->access->query($team)->pluck('id');

        $this->assertTrue($visible->contains($mine->id));
        $this->assertFalse($visible->contains($theirs->id));
    }

    public function test_a_customer_only_sees_boards_they_belong_to(): void
    {
        $customer = $this->customer();

        $mine = $this->boardWithMembers([$customer]);
        $theirs = Board::factory()->create();

        $visible = $this->access->query($customer)->pluck('id');

        $this->assertEquals([$mine->id], $visible->all());
        $this->assertFalse($visible->contains($theirs->id));
    }

    public function test_an_administrator_sees_every_board(): void
    {
        $admin = $this->admin();

        Board::factory()->count(3)->create();

        $this->assertSame(3, $this->access->query($admin)->count());
    }

    public function test_a_guest_sees_nothing(): void
    {
        Board::factory()->count(3)->create();

        $this->assertSame(0, $this->access->query(null)->count());
        $this->assertSame([], $this->access->boardIdsFor(null));
    }

    public function test_a_deactivated_user_sees_nothing_even_with_memberships(): void
    {
        $team = User::factory()->team()->deactivated()->create();

        $this->boardWithMembers([$team]);

        $this->assertSame(0, $this->access->query($team)->count());
    }

    public function test_the_dashboard_lists_only_boards_the_viewer_may_open(): void
    {
        $team = $this->teamMember();

        $mine = $this->boardWithMembers([$team], ['name' => 'Visible Board']);
        $theirs = Board::factory()->create(['name' => 'Hidden Board']);

        $this->actingAs($team)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($mine->name)
            ->assertDontSee($theirs->name);
    }

    public function test_the_board_index_lists_only_boards_the_viewer_may_open(): void
    {
        $customer = $this->customer();

        $mine = $this->boardWithMembers([$customer], ['name' => 'Customer Board']);
        $theirs = Board::factory()->create(['name' => 'Internal Only Board']);

        $this->actingAs($customer)
            ->get(route('boards.index'))
            ->assertOk()
            ->assertSee($mine->name)
            ->assertDontSee($theirs->name);
    }

    public function test_a_user_with_no_boards_sees_an_empty_state_rather_than_an_error(): void
    {
        Board::factory()->count(2)->create();

        $this->actingAs($this->customer())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('No boards yet');
    }

    public function test_constrain_scopes_an_arbitrary_board_scoped_query(): void
    {
        $team = $this->teamMember();

        $mine = $this->boardWithMembers([$team]);
        Board::factory()->create();

        // Board itself carries a board id, so it doubles as a stand-in for the
        // ticket/comment/document models arriving in later phases.
        $rows = $this->access
            ->constrain(Board::query(), $team, 'id')
            ->pluck('id');

        $this->assertEquals([$mine->id], $rows->all());
    }

    public function test_constrain_denies_everything_for_a_guest(): void
    {
        Board::factory()->count(2)->create();

        $this->assertSame(0, $this->access->constrain(Board::query(), null, 'id')->count());
    }
}
