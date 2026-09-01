<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ProcessGitHubWebhookJob;
use App\Models\WebhookDelivery;
use App\Services\GitHub\WebhookSignature;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The inbound GitHub webhook.
 *
 * The only unauthenticated write endpoint in the application, which is why it
 * does as little as it possibly can. In order:
 *
 *   1. verify the signature against the raw body;
 *   2. record the delivery, which de-duplicates it;
 *   3. queue the work;
 *   4. answer 202.
 *
 * No parsing beyond reading two fields, no ticket lookup, no writing to ticket
 * history. That is partly about latency — GitHub disables an endpoint that
 * repeatedly takes more than ten seconds — and partly about blast radius: the
 * less this method does with unverified-shaped input, the less there is to get
 * wrong in the request that a stranger can make.
 *
 * On responses. Everything that is not a signature failure answers 2xx,
 * including events we ignore and payloads we cannot parse. GitHub retries a
 * non-2xx and shows a red cross in the repository settings, and neither helps
 * for a delivery that will never succeed. A bad signature is the one case that
 * genuinely is the caller's fault, and it gets a 401.
 */
class GitHubWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookSignature $signatures): JsonResponse
    {
        // getContent() is the raw body, exactly as sent. Anything re-encoded
        // from $request->json() would not verify — see WebhookSignature.
        $payload = $request->getContent();

        if (! $signatures->verify($payload, $request->header(WebhookSignature::HEADER))) {
            // Deliberately identical whether the secret is unset, the header is
            // missing or the digest is wrong. Distinguishing them would tell an
            // unauthenticated caller how far they got.
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $event = (string) $request->header('X-GitHub-Event', '');

        // GitHub always sends a delivery id; the fallback exists so the column
        // stays non-nullable and the unique index keeps its meaning even if a
        // proxy strips the header.
        $deliveryId = (string) $request->header('X-GitHub-Delivery', '') ?: (string) Str::uuid();

        // A ping is what GitHub sends when the webhook is first saved. Answering
        // it is how the repository settings page shows a green tick.
        if ($event === 'ping') {
            return response()->json(['message' => 'Webhook configured.'], 200);
        }

        $delivery = $this->record($deliveryId, $event);

        if ($delivery === null) {
            // Already seen. A redelivery or a manual replay; the original row
            // and its links stand.
            return response()->json(['message' => 'Already received.'], 200);
        }

        if (! in_array($event, (array) config('github.webhook.events', []), true)) {
            $delivery->markProcessed(0);

            return response()->json(['message' => 'Event ignored.'], 202);
        }

        // Decoded here only to fail fast on malformed JSON, and passed to the
        // job as an array so the worker does not decode it a second time.
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            $delivery->markFailed('The delivery body was not valid JSON.');

            return response()->json(['message' => 'Malformed payload.'], 202);
        }

        ProcessGitHubWebhookJob::dispatch($delivery->getKey(), $event, $decoded)
            ->onConnection(config('github.queue.connection'))
            ->onQueue(config('github.queue.name'));

        return response()->json(['message' => 'Accepted.'], 202);
    }

    /**
     * Claim this delivery, or discover that it has already been claimed.
     *
     * The insert is the claim: the unique index on (source, delivery_id) makes
     * it atomic across web containers, which a "select then insert" would not
     * be. A duplicate key is the expected outcome for a replay, not an error,
     * so it returns null rather than throwing.
     */
    private function record(string $deliveryId, string $event): ?WebhookDelivery
    {
        try {
            return WebhookDelivery::query()->create([
                'source' => WebhookDelivery::SOURCE_GITHUB,
                'delivery_id' => $deliveryId,
                'event' => $event === '' ? 'unknown' : mb_substr($event, 0, 60),
                'status' => WebhookDelivery::STATUS_RECEIVED,
            ]);
        } catch (QueryException) {
            return null;
        }
    }
}
