<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\ResetPassword;
use App\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
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

    /*
     * The two tests below deliberately do NOT fake notifications.
     * Notification::fake() replaces the sender, so the queueing path — where
     * the notification's $connection, $queue and $delay are read — never runs,
     * and a reset notification missing them returned a 500 in production while
     * every faked test passed.
     */

    public function test_requesting_a_link_queues_the_reset_notification(): void
    {
        Queue::fake();

        $user = $this->teamMember();

        Livewire::test(ForgotPassword::class)
            ->set('email', $user->email)
            ->call('sendResetLink')
            ->assertHasNoErrors()
            ->assertSee('If that email address is registered, a password reset link is on its way.');

        Queue::assertPushed(
            SendQueuedNotifications::class,
            fn (SendQueuedNotifications $job): bool => $job->notification instanceof ResetPasswordNotification,
        );
    }

    public function test_the_reset_mail_is_delivered_end_to_end(): void
    {
        // phpunit.xml runs the queue synchronously and mail through the array
        // transport, so this is the whole path a production request takes:
        // queue, worker, mailer.
        $user = $this->teamMember();

        Livewire::test(ForgotPassword::class)
            ->set('email', $user->email)
            ->call('sendResetLink')
            ->assertHasNoErrors();

        $messages = $this->app->make('mailer')->getSymfonyTransport()->messages();

        $this->assertCount(1, $messages);

        $email = $messages[0]->getOriginalMessage();

        $this->assertSame($user->email, $email->getTo()[0]->getAddress());
        $this->assertStringStartsWith('Reset your', $email->getSubject());
        $this->assertStringContainsString('/reset-password/', $email->getHtmlBody());
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
