<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Livewire\Auth\Register;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_is_disabled_by_default(): void
    {
        config()->set('workspace.registration.public', false);

        $this->get(route('register'))->assertNotFound();
    }

    public function test_registration_screen_can_be_rendered_when_enabled(): void
    {
        config()->set('workspace.registration.public', true);

        $this->get(route('register'))->assertOk();
    }

    public function test_a_self_registered_account_gets_the_least_privileged_role_and_no_boards(): void
    {
        config()->set('workspace.registration.public', true);

        Livewire::test(Register::class)
            ->set('name', 'Outside Person')
            ->set('email', 'outside@example.test')
            ->set('password', 'a-long-enough-password-1')
            ->set('password_confirmation', 'a-long-enough-password-1')
            ->call('register')
            ->assertHasNoErrors();

        $user = User::query()->where('email', 'outside@example.test')->sole();

        $this->assertSame(UserRole::Customer, $user->role);
        $this->assertSame(0, $user->boards()->count());
        $this->assertAuthenticatedAs($user);
    }

    public function test_mounting_the_component_is_rejected_when_registration_is_disabled(): void
    {
        config()->set('workspace.registration.public', false);

        Livewire::test(Register::class)->assertNotFound();

        $this->assertSame(0, User::query()->count());
    }
}
