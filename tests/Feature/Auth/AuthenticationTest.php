<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Livewire\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSeeLivewire(Login::class);
    }

    public function test_users_can_authenticate_with_valid_credentials(): void
    {
        $user = $this->teamMember(['password' => 'correct-horse-1']);

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'correct-horse-1')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_email_is_matched_case_insensitively(): void
    {
        $user = $this->teamMember(['email' => 'person@example.test', 'password' => 'correct-horse-1']);

        Livewire::test(Login::class)
            ->set('email', 'PERSON@EXAMPLE.TEST')
            ->set('password', 'correct-horse-1')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_users_cannot_authenticate_with_an_invalid_password(): void
    {
        $user = $this->teamMember(['password' => 'correct-horse-1']);

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_deactivated_user_cannot_authenticate_even_with_the_right_password(): void
    {
        $user = User::factory()->team()->deactivated()->create(['password' => 'correct-horse-1']);

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'correct-horse-1')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_active_session_is_terminated_once_the_account_is_deactivated(): void
    {
        $user = $this->teamMember();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->forceFill(['deactivated_at' => now()])->save();

        $this->get(route('dashboard'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_login_is_rate_limited_after_repeated_failures(): void
    {
        RateLimiter::clear('');

        $user = $this->teamMember(['password' => 'correct-horse-1']);

        $component = Livewire::test(Login::class)->set('email', $user->email)->set('password', 'nope');

        foreach (range(1, 5) as $ignored) {
            $component->call('login')->assertHasErrors('email');
        }

        $component->set('password', 'correct-horse-1')->call('login')->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_users_can_log_out(): void
    {
        $user = $this->teamMember();

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('boards.index'))->assertRedirect(route('login'));
        $this->get(route('users.index'))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_are_redirected_away_from_the_login_screen(): void
    {
        $this->actingAs($this->teamMember())
            ->get(route('login'))
            ->assertRedirect(route('dashboard'));
    }
}
