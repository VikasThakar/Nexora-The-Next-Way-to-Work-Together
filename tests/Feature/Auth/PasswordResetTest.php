<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\ResetPassword;
use App\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $this->get(route('password.request'))->assertOk();
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = $this->teamMember();

        Livewire::test(ForgotPassword::class)
            ->set('email', $user->email)
            ->call('sendResetLink')
            ->assertHasNoErrors();

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_requesting_a_link_for_an_unknown_address_does_not_reveal_that_it_is_unknown(): void
    {
        Notification::fake();

        Livewire::test(ForgotPassword::class)
            ->set('email', 'nobody@example.test')
            ->call('sendResetLink')
            ->assertHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_password_can_be_reset_with_a_valid_token(): void
    {
        Notification::fake();

        $user = $this->teamMember();

        Livewire::test(ForgotPassword::class)
            ->set('email', $user->email)
            ->call('sendResetLink');

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (object $notification) use ($user): bool {
            Livewire::test(ResetPassword::class, ['token' => $notification->token])
                ->set('email', $user->email)
                ->set('password', 'brand-new-password-9')
                ->set('password_confirmation', 'brand-new-password-9')
                ->call('resetPassword')
                ->assertHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });

        $this->assertTrue(Hash::check('brand-new-password-9', $user->fresh()->password));
    }

    public function test_password_cannot_be_reset_with_an_invalid_token(): void
    {
        $user = $this->teamMember();

        Livewire::test(ResetPassword::class, ['token' => 'not-a-real-token'])
            ->set('email', $user->email)
            ->set('password', 'brand-new-password-9')
            ->set('password_confirmation', 'brand-new-password-9')
            ->call('resetPassword')
            ->assertHasErrors('email');

        $this->assertFalse(Hash::check('brand-new-password-9', $user->fresh()->password));
    }
}
