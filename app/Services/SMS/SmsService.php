<?php

declare(strict_types=1);

namespace App\Services\SMS;

use App\Enums\NotificationEvent;
use App\Enums\SmsStatus;
use App\Jobs\SendSmsJob;
use App\Models\SmsMessage as SmsMessageRecord;
use App\Services\SMS\Data\SmsMessage;
use App\Services\SMS\Exceptions\SmsException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queues and records outbound text messages.
 *
 * Sits between the alerting logic and the provider, and owns the three things
 * that are not the provider's business:
 *
 * De-duplication. `claim()` inserts the row first and lets the unique index
 * decide. That ordering is the whole trick: a "have we sent this already?"
 * SELECT followed by an INSERT is a race that two queue workers lose
 * simultaneously, whereas an INSERT that violates a constraint is the database
 * settling it. A duplicate returns null and nothing is queued.
 *
 * Recording. Every message exists as a row before the provider is called, so a
 * worker that dies mid-send leaves evidence rather than silence.
 *
 * Honesty. A message the log driver wrote is recorded as `logged`, never
 * `sent`, and a provider that reported no cost stores null rather than zero.
 *
 * The provider itself is resolved from the container, so nothing here names
 * 46elks and a test can bind a fake with no network involved.
 */
class SmsService
{
    public function __construct(private readonly SmsProviderInterface $provider) {}

    /**
     * Queue one alert, unless it has already been sent.
     *
     * Returns the row when the message was newly claimed, and null when it was
     * a duplicate — so a caller can tell "sent" from "already sent" without a
     * second query.
     */
    public function queue(
        NotificationEvent $event,
        string $recipient,
        string $body,
        ?int $ticketId = null,
        ?int $boardId = null,
        ?string $ticketKey = null,
    ): ?SmsMessageRecord {
        $record = $this->claim($event, $recipient, $body, $ticketId, $boardId, $ticketKey);

        if (! $record instanceof SmsMessageRecord) {
            return null;
        }

        SendSmsJob::dispatch($record->getKey())
            ->onConnection(config('sms.queue.connection'))
            ->onQueue(config('sms.queue.name'))
            // The row was written inside the caller's transaction — a ticket
            // being created — so the worker must not pick it up before that
            // transaction commits, or it would find nothing.
            ->afterCommit();

        return $record;
    }

    /**
     * Send a message that has already been claimed. Called from the job.
     *
     * Throws for anything the queue should retry; records and returns for
     * anything permanent. See ElksSmsProvider for where that line is drawn.
     */
    public function send(SmsMessageRecord $record): void
    {
        // Already handled — a retry after the provider accepted but the worker
        // died before recording, or a duplicate dispatch. Sending again would
        // cost money and wake somebody twice.
        if ($record->status !== SmsStatus::Queued) {
            return;
        }

        $message = new SmsMessage(
            to: (string) $record->recipient,
            body: (string) $record->body,
            from: (string) config('sms.from', 'Aqueduct'),
        );

        try {
            $result = $this->provider->send($message);
        } catch (SmsException $exception) {
            // Our own vocabulary, safe to store: SmsException never quotes a
            // provider response body.
            $record->recordFailure($exception->getMessage());

            throw $exception;
        } catch (Throwable $exception) {
            $record->recordFailure('The message could not be sent. See the application log.');

            Log::error('SMS send failed unexpectedly.', [
                'sms_message_id' => $record->getKey(),
                'provider' => $this->provider->name(),
                'reason' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $record->recordResult($result, $this->provider->name());
    }

    /**
     * Has this board texted anybody recently?
     *
     * A second, coarser guard than the per-ticket unique index: forty critical
     * tickets filed by a broken script in one minute should ring the on-call
     * phone once, not forty times. Zero disables it.
     */
    public function boardIsCoolingDown(int $boardId): bool
    {
        $seconds = (int) config('sms.board_cooldown_seconds', 300);

        if ($seconds <= 0) {
            return false;
        }

        return SmsMessageRecord::query()
            ->recentForBoard($boardId, $seconds)
            ->exists();
    }

    public function providerName(): string
    {
        return $this->provider->name();
    }

    public function providerIsConfigured(): bool
    {
        return $this->provider->isConfigured();
    }

    // -----------------------------------------------------------------

    /**
     * Insert the row, or discover that it already exists.
     *
     * The insert *is* the claim. See the class comment for why this is not a
     * select-then-insert.
     */
    private function claim(
        NotificationEvent $event,
        string $recipient,
        string $body,
        ?int $ticketId,
        ?int $boardId,
        ?string $ticketKey,
    ): ?SmsMessageRecord {
        try {
            return SmsMessageRecord::query()->create([
                'ticket_id' => $ticketId,
                'board_id' => $boardId,
                'ticket_key' => $ticketKey,
                'event' => $event->value,
                'recipient' => $recipient,
                'body' => $this->truncate($body),
                'status' => SmsStatus::Queued->value,
            ]);
        } catch (QueryException) {
            // The unique index refused it: this number has already been alerted
            // about this ticket.
            return null;
        }
    }

    /**
     * An SMS is billed per 160-character segment, and a ticket title pasted
     * whole can be several. The ellipsis is added inside the limit rather than
     * appended past it.
     */
    private function truncate(string $body): string
    {
        $max = max(20, (int) config('sms.max_length', 300));

        return mb_strlen($body) <= $max
            ? $body
            : mb_substr($body, 0, $max - 1).'…';
    }
}
