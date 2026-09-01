<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Queued password reset mail.
 *
 * Queued so a slow or unavailable SMTP provider cannot hold a web request
 * open. With QUEUE_CONNECTION=sync (local default) behaviour is unchanged.
 */
class ResetPassword extends BaseResetPassword implements ShouldQueue
{
    use InteractsWithQueue;

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
