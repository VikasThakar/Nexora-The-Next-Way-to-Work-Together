<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Queued password reset mail.
 *
 * Queued so a slow or unavailable SMTP provider cannot hold a web request
 * open. With QUEUE_CONNECTION=sync it is sent inline instead.
 *
 * `Queueable` is the trait a queued NOTIFICATION needs: it declares the
 * $connection, $queue and $delay properties NotificationSender reads while
 * dispatching. `InteractsWithQueue` — the trait for a queued JOB — declares
 * none of them, and using it here turned every reset request into a 500 the
 * moment the notification was queued. Notification::fake() never queues
 * anything, so only a test that lets the real sender run can see this;
 * tests/Feature/Auth/PasswordResetTest.php and
 * tests/Unit/QueuedNotificationsTest.php both do.
 */
class ResetPassword extends BaseResetPassword implements ShouldQueue
{
    use Queueable;

    /** @var int */
    public $tries = 3;

    /** @var int */
    public $backoff = 30;

    public function toMail($notifiable): MailMessage
    {
        $workspace = config('workspace.name');
        $minutes = config('auth.passwords.users.expire');

        return (new MailMessage)
            ->subject('Reset your '.$workspace.' password')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('We received a request to reset the password for your '.$workspace.' account.')
            ->action('Reset password', $this->resetUrl($notifiable))
            ->line('This link expires in '.$minutes.' minutes.')
            ->line('If you did not request a password reset, no further action is required.');
    }
}
