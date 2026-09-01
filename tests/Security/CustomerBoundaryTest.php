<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Livewire\Boards\Members;
use App\Models\Board;
use App\Services\BoardAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The customer role is the security boundary of the product.
 *
 * A customer may be a fully fledged member of a board and still must never
 * observe internal content, and must never be able to change the board or its
 * membership. These tests pin that contract before any internal content
 * actually exists, so the later phases inherit it.
 */
class CustomerBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_can_never_see_internal_content_even_on_their_own_board(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithMembers([$customer]);

        $access = app(BoardAccess::class);

        $this->assertTrue($access->canView($customer, $board));
        $this->assertTrue($access->isMember($customer, $board));
        $this->assertFalse($access->canSeeInternalContent($customer));
        $this->assertFalse($customer->can('viewInternalContent', $board));
    }

    public function test_team_members_and_administrators_can_see_internal_content_on_their_boards(): void
    {
        $access = app(BoardAccess::class);

        $team = $this->teamMember();
        $admin = $this->admin();
        $board = $this->boardWithMembers([$team]);

        $this->assertTrue($access->canSeeInternalContent($team));
        $this->assertTrue($team->can('viewInternalContent', $board));

        $this->assertTrue($access->canSeeInternalContent($admin));
        $this->assertTrue($admin->can('viewInternalContent', $board));
    }

    public function test_internal_visibility_is_not_granted_by_membership_on_another_board(): void
    {
        $access = app(BoardAccess::class);

        $customer = $this->customer();
        $ownBoard = $this->boardWithMembers([$customer]);
        $otherBoard = Board::factory()->create();

        $this->assertFalse($customer->can('viewInternalContent', $ownBoard));
        $this->assertFalse($customer->can('viewInternalContent', $otherBoard));
    }

    public function test_hide_internal_filters_rows_for_customers_and_not_for_staff(): void
    {
        $customer = $this->customer();
        $team = $this->teamMember();

        // `archived_at IS NULL` stands in for a future `is_internal` column:
        // what matters is that the filter is applied for one role only.
        $query = fn () => Board::query();

        $access = app(BoardAccess::class);

        $this->assertNotSame(
            $access->hideInternal($query(), $customer, 'archived_at')->toSql(),
            $query()->toSql(),
            'Customers must have an extra visibility predicate applied.'
        );

        $this->assertSame(
            $access->hideInternal($query(), $team, 'archived_at')->toSql(),
            $query()->toSql(),
            'Staff queries must not be filtered.'
        );
    }

    public function test_a_customer_cannot_reach_the_board_settings_screen_for_their_own_board(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithMembers([$customer]);

        $this->actingAs($customer)
            ->withConfirmedPassword()
            ->get(route('boards.edit', $board))
            ->assertForbidden();
    }

    public function test_a_customer_cannot_add_themselves_or_anyone_else_to_a_board(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithMembers([$customer]);
        $outsider = $this->customer();

        Livewire::actingAs($customer)
            ->test(Members::class, ['board' => $board])
            ->assertForbidden();

        $this->assertFalse($board->fresh()->hasMember($outsider));
    }

    public function test_a_team_member_cannot_manage_membership_of_a_board_they_belong_to(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithMembers([$team]);

        $this->assertFalse($team->can('manageMembers', $board));
        $this->assertFalse($team->can('update', $board));
        $this->assertFalse($team->can('create', Board::class));
    }

    public function test_a_customer_cannot_create_boards(): void
    {
        $customer = $this->customer();

        $this->assertFalse($customer->can('create', Board::class));

        $this->actingAs($customer)
            ->withConfirmedPassword()
            ->get(route('boards.create'))
            ->assertForbidden();
    }
}
