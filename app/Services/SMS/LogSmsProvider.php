<?php

declare(strict_types=1);

namespace App\Services\SMS;

use App\Services\SMS\Data\SmsMessage;
use App\Services\SMS\Data\SmsResult;
use Illuminate\Support\Facades\Log;

/**
 * Writes the message to the application log instead of sending it.
 *
 * For local development, where the alerting path should be exercisable without
 * a provider account and without texting a real phone.
 *
 * It returns SmsResult::logged(), never sent(). The distinction is enforced all
 * the way to the database column, so nothing downstream can mistake a
 * development message for a delivered one — see App\Enums\SmsStatus.
 *
 * The recipient is partially masked even here. A development log is the least
 * protected place in the system, it is routinely pasted into issues and chat,
 * and a personal mobile number is personal data whether or not the message was
 * really sent.
 */
class LogSmsProvider implements SmsProviderInterface
{
    public function send(SmsMessage $message): SmsResult
    {
        Log::info('SMS (log driver — not sent)', [
            'to' => $this->mask($message->to),
            'from' => $message->from,
            'segments' => $message->segments(),
            'body' => $message->body,
        ]);

        return SmsResult::logged();
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'log';
    }

    private function mask(string $number): string
    {
        return mb_strlen($number) <= 4
            ? '****'
            : mb_substr($number, 0, 3).str_repeat('*', max(0, mb_strlen($number) - 6)).mb_substr($number, -3);
    }
}
