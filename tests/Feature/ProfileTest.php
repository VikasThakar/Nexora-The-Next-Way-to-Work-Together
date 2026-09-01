<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\Profile\UpdatePassword;
use App\Livewire\Profile\UpdateProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_profile_screen_renders_for_every_role(): void
    {
        foreach ([$this->admin(), $this->teamMember(), $this->customer()] as $user) {
            $this->actingAs($user)
                ->get(route('profile.edit'))
                ->assertOk()
                ->assertSee($user->email);
        }
    }

    public function test_a_user_can_update_their_name_and_email(): void
    {
        $user = $this->teamMember(['email' => 'before@example.test']);

        Livewire::actingAs($user)
            ->test(UpdateProfile::class)
            ->set('name', 'Renamed Person')
            ->set('email', 'AFTER@example.test')
            ->call('save')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertSame('Renamed Person', $user->name);
        $this->assertSame('after@example.test', $user->email);
        $this->assertNull($user->email_verified_at, 'Changing an email must reset verification.');
    }

    public function test_a_user_cannot_take_an_email_that_belongs_to_someone_else(): void
    {
        $other = $this->teamMember(['email' => 'taken@example.test']);
        $user = $this->teamMember();

        Livewire::actingAs($user)
            ->test(UpdateProfile::class)
            ->set('email', $other->email)
            ->call('save')
            ->assertHasErrors('email');
    }

    public function test_a_user_cannot_change_their_own_role_through_the_profile_screen(): void
    {
        $customer = $this->customer();

        // `role` is not a property of the component at all, so Livewire has
        // nothing to bind to; this asserts the surface stays that way.
        $component = Livewire::actingAs($customer)->test(UpdateProfile::class);

        $this->assertFalse(property_exists($component->instance(), 'role'));
        $this->assertSame(UserRole::Customer, $customer->fresh()->role);
    }

    public function test_a_user_can_change_their_password(): void
    {
        $user = $this->teamMember(['password' => 'current-password-1']);

        Livewire::actingAs($user)
            ->test(UpdatePassword::class)
            ->set('current_password', 'current-password-1')
            ->set('password', 'replacement-password-2')
            ->set('password_confirmation', 'replacement-password-2')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('replacement-password-2', $user->fresh()->password));
    }

    public function test_changing_a_password_requires_the_current_one(): void
    {
        $user = $this->teamMember(['password' => 'current-password-1']);

        Livewire::actingAs($user)
            ->test(UpdatePassword::class)
            ->set('current_password', 'not-the-current-password')
            ->set('password', 'replacement-password-2')
            ->set('password_confirmation', 'replacement-password-2')
            ->call('save')
            ->assertHasErrors('current_password');

        $this->assertTrue(Hash::check('current-password-1', $user->fresh()->password));
    }

    public function test_a_new_password_must_be_confirmed_and_strong(): void
    {
        $user = $this->teamMember(['password' => 'current-password-1']);

        Livewire::actingAs($user)
            ->test(UpdatePassword::class)
            ->set('current_password', 'current-password-1')
            ->set('password', 'replacement-password-2')
            ->set('password_confirmation', 'mismatch')
            ->call('save')
            ->assertHasErrors('password');

        Livewire::actingAs($user)
            ->test(UpdatePassword::class)
            ->set('current_password', 'current-password-1')
            ->set('password', 'short')
            ->set('password_confirmation', 'short')
            ->call('save')
            ->assertHasErrors('password');

        $this->assertTrue(Hash::check('current-password-1', $user->fresh()->password));
    }
}
