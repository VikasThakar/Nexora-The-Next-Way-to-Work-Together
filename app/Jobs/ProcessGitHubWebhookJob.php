<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\GitHub\WebhookProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes one verified GitHub delivery.
 *
 * The signature was checked in the request, before this was queued, so the
 * payload here is trusted to have come from GitHub. It is still not trusted to
 * be well-shaped: every field the processor reads goes through `Arr::get` with
 * a default, because "GitHub sent it" and "GitHub sent what this code expects"
 * are different claims, and the second one ages badly as their API grows.
 *
 * The delivery row is updated whatever happens, which is the point of having
 * it: a link that never appeared can be traced to a delivery that arrived and
 * was ignored, or arrived and failed, rather than to silence.
 */
class ProcessGitHubWebhookJob implements ShouldQueue
{
    use Queueable;

    /**
     * The payload travels with the job rather than being re-read.
     *
     * It is already decoded and already verified; storing it on the queue for
     * the seconds between dispatch and execution is cheaper than persisting it
     * to a column we deliberately chose not to keep (see the migration).
     *
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $deliveryId,
        public readonly string $event,
        public readonly array $payload,
    ) {}

    public function tries(): int
    {
        return max(1, (int) config('github.queue.tries', 3));
    }

    public function retryUntil(): \DateTimeInterface
    {
        // A delivery that has not been processed within ten minutes is stale:
        // GitHub will have sent newer state for the same object by then.
        return now()->addMinutes(10);
    }

    public function handle(WebhookProcessor $processor): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        // Pruned, or the row was rolled back. Nothing to record against, and
        // reprocessing would write links with no audit trail.
        if (! $delivery instanceof WebhookDelivery) {
            return;
        }

        try {
            $written = $processor->process($this->event, $this->payload);

            $delivery->markProcessed($written);
        } catch (Throwable $exception) {
            // Recorded in our own words, not the exception's: an exception
            // chain from the query layer can quote a connection string.
            $delivery->markFailed('The delivery could not be processed. See the application log.');

            Log::error('GitHub webhook processing failed.', [
                'delivery' => $delivery->delivery_id,
                'event' => $this->event,
                'exception' => $exception->getMessage(),
            ]);

            // Rethrown so the queue retries: a failure here is usually a lock
            // timeout or a brief database blip, both of which succeed next time.
            throw $exception;
        }
    }

    /**
     * Every attempt is exhausted.
     *
     * Marked failed rather than left as `received`, so the audit view
     * distinguishes "still queued" from "gave up".
     */
    public function failed(?Throwable $exception): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        $delivery?->markFailed('Processing failed after every retry.');
    }
}
