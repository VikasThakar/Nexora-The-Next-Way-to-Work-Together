<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Livewire\Boards\Show;
use App\Models\Board;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Board membership is the authorization boundary of the product.
 *
 * These tests assert the rule from the outside (HTTP) and from the inside
 * (component mount), because a future screen might reach the component without
 * going through the route.
 */
class BoardMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_team_member_cannot_open_a_board_they_are_not_assigned_to(): void
    {
        $team = $this->teamMember();
        $board = Board::factory()->create();

        $this->actingAs($team)
            ->get(route('boards.show', $board))
            ->assertNotFound();
    }

    public function test_a_customer_cannot_open_a_board_they_were_not_invited_to(): void
    {
        $customer = $this->customer();
        $board = Board::factory()->create();

        $this->actingAs($customer)
            ->get(route('boards.show', $board))
            ->assertNotFound();
    }

    public function test_non_membership_returns_404_and_not_403_so_board_existence_is_not_leaked(): void
    {
        $customer = $this->customer();
        $existing = Board::factory()->create(['slug' => 'real-board']);

        $forExisting = $this->actingAs($customer)->get('/boards/real-board');
        $forMissing = $this->actingAs($customer)->get('/boards/no-such-board');

        // Both responses must be indistinguishable to the caller.
        $this->assertSame(404, $forExisting->status());
        $this->assertSame(404, $forMissing->status());
        $this->assertStringNotContainsString($existing->name, $forExisting->getContent());
    }

    public function test_a_member_can_open_their_board(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithMembers([$team]);

        $this->actingAs($team)
            ->get(route('boards.show', $board))
            ->assertOk()
            ->assertSee($board->name);
    }

    public function test_a_customer_member_can_open_their_board(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithMembers([$customer]);

        $this->actingAs($customer)
            ->get(route('boards.show', $board))
            ->assertOk()
            ->assertSee($board->name);
    }

    public function test_an_administrator_can_open_any_board_without_membership(): void
    {
        $admin = $this->admin();
        $board = Board::factory()->create();

        $this->assertFalse($board->hasMember($admin));

        $this->actingAs($admin)
            ->get(route('boards.show', $board))
            ->assertOk();
    }

    public function test_mounting_the_board_component_directly_is_still_authorized(): void
    {
        $customer = $this->customer();
        $board = Board::factory()->create();

        Livewire::actingAs($customer)
            ->test(Show::class, ['board' => $board])
            ->assertNotFound();
    }

    public function test_removing_a_membership_revokes_access_immediately(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithMembers([$team]);

        $this->actingAs($team)->get(route('boards.show', $board))->assertOk();

        $board->members()->detach($team->getKey());

        $this->actingAs($team)->get(route('boards.show', $board))->assertNotFound();
    }

    public function test_a_deactivated_member_loses_board_access(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithMembers([$team]);

        $team->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($team->fresh())
            ->get(route('boards.show', $board))
            ->assertRedirect(route('login'));
    }
}
