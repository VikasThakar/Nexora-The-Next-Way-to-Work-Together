<?php

declare(strict_types=1);

namespace App\Services\SMS\Exceptions;

use RuntimeException;

/**
 * Something went wrong sending a text message.
 *
 * Named constructors only, and no constructor that takes arbitrary text from a
 * provider response. That is the same rule the AI exceptions follow and for the
 * same reason: an SMS provider's error body can echo the API username back on
 * an authentication failure, and an exception message ends up in a log, in a
 * database column, and eventually in a screenshot.
 */
class SmsException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self(
            'No SMS provider is configured. Set SMS_DRIVER (46elks), SMS_46ELKS_USERNAME, '
            .'SMS_46ELKS_PASSWORD and SMS_FROM, and set SMS_ENABLED=true.'
        );
    }

    public static function missingCredentials(): self
    {
        return new self(
            'The 46elks driver is selected but its credentials are missing. '
            .'Set SMS_46ELKS_USERNAME and SMS_46ELKS_PASSWORD on the queue worker.'
        );
    }

    /**
     * Built from the HTTP status alone. The response body is deliberately not
     * quoted — see the class comment.
     */
    public static function providerRejected(int $status): self
    {
        return new self(match (true) {
            $status === 401, $status === 403 => 'The SMS provider rejected the credentials.',
            $status === 400 => 'The SMS provider rejected the message. Check the sender ID and the recipient number.',
            $status === 429 => 'The SMS provider is rate limiting this account.',
            $status >= 500 => 'The SMS provider is unavailable.',
            default => 'The SMS provider returned an unexpected response ('.$status.').',
        });
    }

    public static function unreachable(): self
    {
        return new self('The SMS provider could not be reached.');
    }

    public static function invalidRecipient(): self
    {
        return new self('The recipient is not a valid international number.');
    }
}
