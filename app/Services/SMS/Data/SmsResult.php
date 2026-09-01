<?php

declare(strict_types=1);

namespace App\Services\SMS\Data;

use App\Enums\SmsStatus;

/**
 * What a provider said about one message.
 *
 * `cost` is nullable and stays null unless the provider actually reported one.
 * Same rule as the AI cost tracking: an invented figure in a spend report is
 * worse than an honest blank, because a blank prompts a question and a wrong
 * number does not.
 */
final readonly class SmsResult
{
    private function __construct(
        public SmsStatus $status,
        public ?string $providerMessageId,
        public ?string $cost,
        public ?string $error,
    ) {}

    public static function sent(?string $messageId = null, ?string $cost = null): self
    {
        return new self(SmsStatus::Sent, $messageId, $cost, null);
    }

    /**
     * Written to the log instead of sent. Never reported as delivery.
     */
    public static function logged(): self
    {
        return new self(SmsStatus::Logged, null, null, null);
    }

    public static function failed(string $error): self
    {
        return new self(SmsStatus::Failed, null, null, $error);
    }
}
