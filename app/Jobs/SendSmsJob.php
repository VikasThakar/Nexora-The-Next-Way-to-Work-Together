<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SmsMessage;
use App\Services\SMS\SmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one already-claimed SMS.
 *
 * Queued for two reasons that pull in the same direction. An SMS provider is a
 * third party over HTTP, and creating a ticket must not wait for one; and an
 * alert that fails on the first attempt because the provider had a bad minute
 * should be retried, which a synchronous call inside a database transaction
 * cannot be.
 *
 * The row id travels, not the model. A serialised model would carry a snapshot
 * of the status column taken before the job was queued, and the status is
 * exactly the field the send has to read fresh — it is what stops a retry
 * sending a message the provider already accepted.
 */
class SendSmsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $smsMessageId) {}

    public function tries(): int
    {
        return max(1, (int) config('sms.queue.tries', 3));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return array_map(
            static fn ($seconds): int => max(1, (int) $seconds),
            (array) config('sms.backoff', [15, 60, 180]),
        );
    }

    public function retryUntil(): \DateTimeInterface
    {
        // An alert that has not gone out within half an hour has missed its
        // purpose. Better to stop and leave a failed row than to text somebody
        // at 4am about a ticket filed at 3.
        return now()->addMinutes(30);
    }

    public function handle(SmsService $sms): void
    {
        $record = SmsMessage::query()->find($this->smsMessageId);

        // Pruned, or the surrounding transaction rolled back. Nothing to send
        // and nothing to record against.
        if (! $record instanceof SmsMessage) {
            return;
        }

        $sms->send($record);
    }

    /**
     * Every attempt is exhausted.
     *
     * SmsService has already written the reason to the row; this exists so an
     * alert that never went out is visible in the log too, because nobody reads
     * a delivery table until they already suspect something.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('An SMS alert was not delivered after every retry.', [
            'sms_message_id' => $this->smsMessageId,
        ]);
    }
}
