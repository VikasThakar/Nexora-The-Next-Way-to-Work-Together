<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Enums\UserRole;
use App\Livewire\Users\Index as UserIndex;
use App\Livewire\Users\ManageUser;
use App\Models\Board;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Route-level and policy-level role enforcement.
 */
class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every administration route, checked for each non-administrator role.
     *
     * @return array<string, array{UserRole}>
     */
    public static function nonAdminRoles(): array
    {
        return [
            'team member' => [UserRole::Team],
            'customer' => [UserRole::Customer],
        ];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_administrators_cannot_reach_user_administration(UserRole $role): void
    {
        $user = $this->userWithRole($role);

        $this->actingAs($user)->withConfirmedPassword()
            ->get(route('users.index'))->assertForbidden();

        $this->actingAs($user)->withConfirmedPassword()
            ->get(route('users.create'))->assertForbidden();

        $this->actingAs($user)->withConfirmedPassword()
            ->get(route('users.edit', $user))->assertForbidden();
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_administrators_cannot_reach_board_creation(UserRole $role): void
    {
        $user = $this->userWithRole($role);

        $this->actingAs($user)->withConfirmedPassword()
            ->get(route('boards.create'))->assertForbidden();
    }

    public function test_administrators_can_reach_administration_screens(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->withConfirmedPassword()
            ->get(route('users.index'))->assertOk();

        $this->actingAs($admin)->withConfirmedPassword()
            ->get(route('boards.create'))->assertOk();
    }

    public function test_administration_routes_require_a_recently_confirmed_password(): void
    {
        $this->actingAs($this->admin())
            ->get(route('users.index'))
            ->assertRedirect(route('password.confirm'));
    }

    #[DataProvider('nonAdminRoles')]
    public function test_the_user_management_component_cannot_be_mounted_by_non_administrators(UserRole $role): void
    {
        Livewire::actingAs($this->userWithRole($role))
            ->test(UserIndex::class)
            ->assertForbidden();
    }

    #[DataProvider('nonAdminRoles')]
    public function test_the_user_form_cannot_be_mounted_by_non_administrators(UserRole $role): void
    {
        Livewire::actingAs($this->userWithRole($role))
            ->test(ManageUser::class)
            ->assertForbidden();
    }

    public function test_an_administrator_cannot_change_their_own_role(): void
    {
        $admin = $this->admin();

        $this->assertFalse($admin->can('updateRole', $admin));
        $this->assertFalse($admin->can('delete', $admin));

        Livewire::actingAs($admin)
            ->test(ManageUser::class, ['user' => $admin])
            ->set('role', UserRole::Customer->value)
            ->call('save')
            ->assertForbidden();

        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
    }

    public function test_role_middleware_fails_closed_for_an_unknown_role_name(): void
    {
        Route::middleware(['web', 'auth', 'role:wizard'])
            ->get('/__test/unknown-role', fn () => 'reached')
            ->name('test.unknown-role');

        $this->actingAs($this->admin())
            ->get('/__test/unknown-role')
            ->assertForbidden();
    }

    public function test_board_membership_does_not_grant_workspace_administration(): void
    {
        $team = $this->teamMember();
        $this->boardWithMembers([$team]);

        $this->assertFalse($team->can('administer-workspace'));
        $this->assertFalse($team->can('viewAny', User::class));
        $this->assertFalse($team->can('create', Board::class));
    }
}
