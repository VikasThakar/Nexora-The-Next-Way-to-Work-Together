<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Board;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_renders_for_every_role(): void
    {
        foreach ([$this->admin(), $this->teamMember(), $this->customer()] as $user) {
            $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertSee($user->role->label());
        }
    }

    public function test_an_administrator_sees_every_board_on_the_dashboard(): void
    {
        $admin = $this->admin();

        $first = Board::factory()->create(['name' => 'First Board']);
        $second = Board::factory()->create(['name' => 'Second Board']);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($first->name)
            ->assertSee($second->name);
    }

    public function test_only_administrators_are_offered_the_create_board_action(): void
    {
        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('boards.create'), escape: false);

        $this->actingAs($this->teamMember())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('boards.create'), escape: false);
    }

    public function test_the_root_url_redirects_to_the_dashboard(): void
    {
        $this->actingAs($this->teamMember())
            ->get('/')
            ->assertRedirect('/dashboard');
    }

    public function test_a_customer_is_told_their_view_is_limited(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithMembers([$customer]);

        $this->actingAs($customer)
            ->get(route('boards.show', $board))
            ->assertOk()
            ->assertSee('Customer view');
    }
}
