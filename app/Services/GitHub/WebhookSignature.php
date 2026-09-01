<?php

declare(strict_types=1);

namespace App\Services\GitHub;

/**
 * Verifies that a webhook delivery really came from GitHub.
 *
 * This is the entire authentication story for the inbound endpoint. There is no
 * session, no token in the URL and no IP allow-list — the request is anonymous
 * and its only credential is an HMAC of the raw body under a shared secret.
 * Everything downstream, including writes into ticket history, is trusted
 * because this returned true.
 *
 * Four properties, each of which has been a real vulnerability somewhere:
 *
 *   Fail closed with no secret. An unconfigured endpoint rejects everything.
 *   The tempting alternative — "skip verification when no secret is set, so it
 *   works out of the box" — turns a deployment that has not finished being
 *   configured into an unauthenticated write endpoint, which is precisely the
 *   state a half-deployed system spends its first day in.
 *
 *   Verify the RAW body. Not the parsed input, not a re-encoded array. GitHub
 *   signs the exact bytes it sent, and `json_encode(json_decode($body))` is not
 *   those bytes — key order, unicode escaping and float formatting all differ.
 *   Re-serialising would either break every delivery or, worse, be "fixed"
 *   later by loosening the check.
 *
 *   Constant-time comparison. `hash_equals`, never `===`. A byte-by-byte
 *   comparison that returns early leaks how many leading bytes were right, and
 *   an attacker who can send a few thousand requests can walk a signature out
 *   of that timing difference one byte at a time.
 *
 *   SHA-256 only. GitHub still sends the legacy `X-Hub-Signature` (SHA-1)
 *   alongside the modern header for compatibility. Accepting it would mean the
 *   endpoint's real strength is SHA-1's, because an attacker picks which header
 *   to send.
 */
class WebhookSignature
{
    /** The header GitHub signs modern deliveries with. */
    public const HEADER = 'X-Hub-Signature-256';

    private const ALGORITHM = 'sha256';

    private const PREFIX = 'sha256=';

    /**
     * Is the integration configured to accept deliveries at all?
     */
    public function isConfigured(): bool
    {
        return $this->secret() !== '';
    }

    /**
     * Does this signature match this body?
     *
     * @param  string  $payload  the raw request body, exactly as received
     * @param  ?string  $signature  the value of the X-Hub-Signature-256 header
     */
    public function verify(string $payload, ?string $signature): bool
    {
        $secret = $this->secret();

        // No secret configured: refuse. See the class comment.
        if ($secret === '') {
            return false;
        }

        $signature = trim((string) $signature);

        if ($signature === '' || ! str_starts_with($signature, self::PREFIX)) {
            return false;
        }

        $expected = self::PREFIX.hash_hmac(self::ALGORITHM, $payload, $secret);

        // hash_equals compares in time independent of where the first
        // difference is. It also refuses non-strings, so the casts above are
        // load-bearing rather than decorative.
        return hash_equals($expected, $signature);
    }

    /**
     * The signature a given body *should* carry.
     *
     * Exists for the test suite, which has to sign its own fixtures — and
     * signing them with this method rather than with a hand-written
     * `hash_hmac` line means the tests would still fail if the algorithm or
     * prefix here changed, instead of quietly agreeing with themselves.
     */
    public function sign(string $payload, ?string $secret = null): string
    {
        return self::PREFIX.hash_hmac(self::ALGORITHM, $payload, $secret ?? $this->secret());
    }

    private function secret(): string
    {
        return trim((string) config('github.webhook.secret'));
    }
}
