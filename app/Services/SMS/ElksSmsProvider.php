<?php

declare(strict_types=1);

namespace App\Services\SMS;

use App\Services\SMS\Data\SmsMessage;
use App\Services\SMS\Data\SmsResult;
use App\Services\SMS\Exceptions\SmsException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * 46elks.
 *
 * https://46elks.com/docs/send-sms — HTTP basic auth with the API username and
 * password from the dashboard, and a form-encoded body of `from`, `to` and
 * `message`. A successful send returns JSON with an `id` and usually a `cost`
 * in the account's currency, expressed in tenths of a cent.
 *
 * The only class in the application that reads the SMS credentials.
 *
 * Two things it is careful about:
 *
 *   Failure text is rebuilt from the HTTP status, never quoted from the
 *   response. A 401 body from an SMS API commonly echoes the username back, and
 *   an exception message ends up in a log file, in a database column and
 *   eventually in a screenshot in a ticket.
 *
 *   The distinction between "retry this" and "do not". A 5xx, a 429 or a
 *   connection failure raises, so the queue backs off and tries again; a 400 —
 *   a malformed number, a sender ID the destination network refuses — is
 *   returned as a failed result, because it will be just as malformed in three
 *   minutes.
 *
 * NOT VERIFIED AGAINST THE LIVE API. This implementation is written from the
 * published documentation; the request shape, the auth scheme and the response
 * fields have not been exercised against a real 46elks account, because doing
 * so requires paid credentials and sends real messages to real phones. The
 * first real send is the thing that confirms it.
 */
class ElksSmsProvider implements SmsProviderInterface
{
    public function send(SmsMessage $message): SmsResult
    {
        if (! $this->isConfigured()) {
            throw SmsException::missingCredentials();
        }

        $payload = array_filter([
            'from' => $message->from,
            'to' => $message->to,
            'message' => $message->body,
            'whendelivered' => $this->deliveryReportUrl(),
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        try {
            $response = Http::withBasicAuth($this->username(), $this->password())
                ->timeout((int) config('sms.46elks.timeout', 15))
                ->asForm()
                ->post((string) config('sms.46elks.endpoint'), $payload);
        } catch (ConnectionException) {
            // Network-level. Worth retrying, so it raises.
            throw SmsException::unreachable();
        }

        if ($response->successful()) {
            $body = $response->json();

            return SmsResult::sent(
                messageId: is_array($body) ? $this->stringOrNull($body['id'] ?? null) : null,
                cost: is_array($body) ? $this->stringOrNull($body['cost'] ?? null) : null,
            );
        }

        $status = $response->status();

        // Permanent: a bad number or a refused sender ID is not going to become
        // valid on the second attempt.
        if ($status === 400) {
            return SmsResult::failed(SmsException::providerRejected($status)->getMessage());
        }

        // Everything else — auth, rate limiting, provider outage — raises so
        // the queue retries. An auth failure that resolves on its own is
        // unlikely, but the retries are bounded and the alternative is dropping
        // an alert during a credential rotation.
        throw SmsException::providerRejected($status);
    }

    public function isConfigured(): bool
    {
        return $this->username() !== '' && $this->password() !== '';
    }

    public function name(): string
    {
        return '46elks';
    }

    private function username(): string
    {
        return trim((string) config('sms.46elks.username'));
    }

    private function password(): string
    {
        return trim((string) config('sms.46elks.password'));
    }

    /**
     * Where 46elks should report delivery, if anywhere.
     *
     * Unset by default: this application exposes no receiving endpoint, and
     * pointing the provider at a URL that 404s produces retries at their end
     * rather than useful information at ours.
     */
    private function deliveryReportUrl(): ?string
    {
        $url = trim((string) config('sms.46elks.delivery_report_url'));

        return $url === '' ? null : $url;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
