<?php

declare(strict_types=1);

namespace App\Services\SMS\Data;

/**
 * One text message, ready to send.
 *
 * A value object rather than three loose strings, so a provider implementation
 * receives something already normalised: the recipient is E.164, the body is
 * within the configured length, and the sender has been resolved.
 */
final readonly class SmsMessage
{
    public function __construct(
        public string $to,
        public string $body,
        public string $from,
    ) {}

    /**
     * How many 160-character segments this will be billed as.
     *
     * Approximate on purpose — the real figure depends on whether the body is
     * GSM-7 or UCS-2, which depends on whether a customer's ticket title
     * contains an emoji. Close enough to reason about cost, and honest about
     * being an estimate.
     */
    public function segments(): int
    {
        return max(1, (int) ceil(mb_strlen($this->body) / 160));
    }
}
