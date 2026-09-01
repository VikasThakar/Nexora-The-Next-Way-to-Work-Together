<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Enums\UserRole;
use App\Livewire\Users\Index as UserIndex;
use App\Livewire\Users\ManageUser;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_administrator_can_create_a_user_with_each_role(): void
    {
        $admin = $this->admin();

        foreach (UserRole::cases() as $role) {
            Livewire::actingAs($admin)
                ->test(ManageUser::class)
                ->set('name', 'Person '.$role->value)
                ->set('email', $role->value.'@example.test')
                ->set('role', $role->value)
                ->set('password', 'a-long-enough-password-1')
                ->set('password_confirmation', 'a-long-enough-password-1')
                ->call('save')
                ->assertHasNoErrors();

            $created = User::query()->where('email', $role->value.'@example.test')->sole();

            $this->assertSame($role, $created->role);
            $this->assertTrue(Hash::check('a-long-enough-password-1', $created->password));
        }
    }

    public function test_a_created_user_has_no_board_access_until_invited(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ManageUser::class)
            ->set('name', 'Fresh Person')
            ->set('email', 'fresh@example.test')
            ->set('role', UserRole::Team->value)
            ->set('password', 'a-long-enough-password-1')
            ->set('password_confirmation', 'a-long-enough-password-1')
            ->call('save')
            ->assertHasNoErrors();

        $created = User::query()->where('email', 'fresh@example.test')->sole();

        $this->assertSame(0, $created->boards()->count());
    }

    public function test_email_addresses_must_be_unique(): void
    {
        $existing = $this->teamMember(['email' => 'taken@example.test']);

        Livewire::actingAs($this->admin())
            ->test(ManageUser::class)
            ->set('name', 'Duplicate')
            ->set('email', $existing->email)
            ->set('role', UserRole::Team->value)
            ->set('password', 'a-long-enough-password-1')
            ->set('password_confirmation', 'a-long-enough-password-1')
            ->call('save')
            ->assertHasErrors('email');
    }

    public function test_a_weak_password_is_rejected(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ManageUser::class)
            ->set('name', 'Weak')
            ->set('email', 'weak@example.test')
            ->set('role', UserRole::Team->value)
            ->set('password', 'short')
            ->set('password_confirmation', 'short')
            ->call('save')
            ->assertHasErrors('password');
    }

    public function test_the_user_screens_render_for_an_administrator(): void
    {
        $admin = $this->admin();
        $target = $this->customer(['name' => 'Casey Customer']);

        $this->actingAs($admin)->withConfirmedPassword()
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee($target->name);

        $this->actingAs($admin)->withConfirmedPassword()
            ->get(route('users.edit', $target))
            ->assertOk()
            ->assertSee($target->email);
    }

    public function test_an_administrator_can_change_another_users_role(): void
    {
        $admin = $this->admin();
        $target = $this->teamMember();

        Livewire::actingAs($admin)
            ->test(ManageUser::class, ['user' => $target])
            ->set('role', UserRole::Customer->value)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(UserRole::Customer, $target->fresh()->role);
    }

    public function test_editing_a_user_without_a_password_keeps_the_existing_one(): void
    {
        $target = $this->teamMember(['password' => 'original-password-1']);

        Livewire::actingAs($this->admin())
            ->test(ManageUser::class, ['user' => $target])
            ->set('name', 'Renamed Person')
            ->call('save')
            ->assertHasNoErrors();

        $target->refresh();

        $this->assertSame('Renamed Person', $target->name);
        $this->assertTrue(Hash::check('original-password-1', $target->password));
    }

    public function test_an_administrator_can_deactivate_and_reactivate_a_user(): void
    {
        $admin = $this->admin();
        $target = $this->teamMember();

        $component = Livewire::actingAs($admin)->test(UserIndex::class);

        $component->call('toggleActive', $target->getKey());
        $this->assertFalse($target->fresh()->isActive());

        $component->call('toggleActive', $target->getKey());
        $this->assertTrue($target->fresh()->isActive());
    }

    public function test_an_administrator_cannot_deactivate_themselves(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(UserIndex::class)
            ->call('toggleActive', $admin->getKey());

        $this->assertTrue($admin->fresh()->isActive());
    }

    public function test_the_role_column_is_not_mass_assignable(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new User)->fill([
            'name' => 'Mass Assigned',
            'email' => 'mass@example.test',
            'role' => UserRole::Admin->value,
        ]);
    }
}
