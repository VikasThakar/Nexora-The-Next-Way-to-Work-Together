<?php

declare(strict_types=1);

namespace App\Services\Gritch;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Gritch's server vouching for one of its admins, for one minute, once.
 *
 * The only credential the integration endpoints accept. Gritch signs it; the
 * browser may carry it (into the iframe, by a POST form — never in a URL) but
 * cannot make or change one. The format is deliberately small enough to read
 * in full:
 *
 *   base64url(claims JSON) "." base64url(HMAC-SHA256(first part, secret))
 *
 *   iss  "gritch"          aud  "nexora"
 *   typ  "embed" | "token" | "revoke" — what it may be used for, and nothing
 *        else: an assertion minted for the iframe cannot fetch an AI token
 *   sub  the admin's SaleSys user id
 *   name their name, for the account Nexora shows
 *   iat / exp  issued / expires, unix seconds, at most two minutes apart
 *   jti  a random id; each assertion is redeemed exactly once
 *
 * The rules, each of which has been a real vulnerability somewhere:
 *
 *   Fail closed without a secret, or with one too short to be a secret.
 *
 *   Verify the bytes that were signed. The MAC covers the first part exactly
 *   as received; nothing is decoded and re-encoded before checking.
 *
 *   hash_equals, never ===. See App\Services\GitHub\WebhookSignature.
 *
 *   One use. The jti is claimed with Cache::add, which is atomic, so a replay —
 *   from a browser history, a proxy log or a second click — is refused even
 *   within the minute. The claim outlives the assertion's own expiry.
 *
 * Gritch's twin of this class is App\Services\Nexora\NexoraAssertion in
 * gritch-backend. Both test suites check the same fixed vector, so the two
 * cannot drift apart without a test failing on one side.
 */
final class GritchAssertion
{
    public const TYPE_EMBED = 'embed';

    public const TYPE_TOKEN = 'token';

    public const TYPE_REVOKE = 'revoke';

    /** Longest lifetime Gritch may give an assertion. */
    public const MAX_LIFETIME = 120;

    /** Clock difference tolerated between the two servers. */
    public const LEEWAY = 60;

    public const MIN_SECRET_LENGTH = 32;

    private const MAX_LENGTH = 4096;

    private function __construct(
        public readonly string $type,
        public readonly string $subject,
        public readonly string $name,
        public readonly string $id,
    ) {}

    /**
     * Verify and use up an assertion.
     *
     * @throws InvalidGritchAssertion with a reason meant for the log, never for
     *                                the caller (who gets one generic message)
     */
    public static function redeem(string $token, string $type, ?CarbonImmutable $now = null): self
    {
        $assertion = self::verify($token, $type, $now);

        // Kept for longer than any assertion can live, so an expired-but-
        // replayed one is refused for being expired and a live one for being
        // used — never accepted.
        $claimed = Cache::add(
            'gritch-assertion:'.hash('sha256', $assertion->id),
            true,
            now()->addSeconds(self::MAX_LIFETIME + 2 * self::LEEWAY + 60),
        );

        if (! $claimed) {
            throw new InvalidGritchAssertion('Assertion already used.');
        }

        return $assertion;
    }

    /**
     * Check signature and claims without using the assertion up.
     *
     * @throws InvalidGritchAssertion
     */
    public static function verify(string $token, string $type, ?CarbonImmutable $now = null): self
    {
        $secret = self::secret();

        if (strlen($secret) < self::MIN_SECRET_LENGTH) {
            throw new InvalidGritchAssertion('No integration secret configured.');
        }

        if ($token === '' || strlen($token) > self::MAX_LENGTH || substr_count($token, '.') !== 1) {
            throw new InvalidGritchAssertion('Malformed assertion.');
        }

        [$body, $signature] = explode('.', $token);

        if (! hash_equals(self::base64UrlEncode(hash_hmac('sha256', $body, $secret, true)), $signature)) {
            throw new InvalidGritchAssertion('Bad signature.');
        }

        $claims = json_decode((string) self::base64UrlDecode($body), true);

        if (! is_array($claims)) {
            throw new InvalidGritchAssertion('Claims are not a JSON object.');
        }

        $now = ($now ?? CarbonImmutable::now())->getTimestamp();
        $issuedAt = $claims['iat'] ?? null;
        $expiresAt = $claims['exp'] ?? null;

        return match (true) {
            ($claims['iss'] ?? null) !== 'gritch' => throw new InvalidGritchAssertion('Wrong issuer.'),
            ($claims['aud'] ?? null) !== 'nexora' => throw new InvalidGritchAssertion('Wrong audience.'),
            ($claims['typ'] ?? null) !== $type => throw new InvalidGritchAssertion('Wrong type.'),
            ! is_int($issuedAt) || ! is_int($expiresAt) => throw new InvalidGritchAssertion('Missing times.'),
            $expiresAt <= $issuedAt || $expiresAt - $issuedAt > self::MAX_LIFETIME => throw new InvalidGritchAssertion('Lifetime out of range.'),
            $issuedAt > $now + self::LEEWAY => throw new InvalidGritchAssertion('Issued in the future.'),
            $expiresAt < $now - self::LEEWAY => throw new InvalidGritchAssertion('Expired.'),
            ! self::validSubject($claims['sub'] ?? null) => throw new InvalidGritchAssertion('Bad subject.'),
            ! self::validId($claims['jti'] ?? null) => throw new InvalidGritchAssertion('Bad id.'),
            default => new self(
                type: $type,
                subject: (string) $claims['sub'],
                name: self::cleanName($claims['name'] ?? null),
                id: (string) $claims['jti'],
            ),
        };
    }

    /**
     * Make an assertion. Gritch's side does this in production; Nexora only
     * needs it for its test suite and the documentation's worked example.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function sign(array $claims, ?string $secret = null): string
    {
        $body = self::base64UrlEncode((string) json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $body.'.'.self::base64UrlEncode(hash_hmac('sha256', $body, $secret ?? self::secret(), true));
    }

    /**
     * SaleSys ids are letters, digits, dashes and underscores. Anything else is
     * refused rather than cleaned, because the id ends up in an email address
     * and a cache key.
     */
    private static function validSubject(mixed $subject): bool
    {
        return is_string($subject) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $subject) === 1;
    }

    private static function validId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{16,128}$/', $id) === 1;
    }

    /**
     * The name is display text only — it decides nothing — so it is trimmed to
     * printable characters and a sane length rather than refused.
     */
    private static function cleanName(mixed $name): string
    {
        $name = is_string($name) ? $name : '';
        $name = trim((string) preg_replace('/[\p{C}]+/u', ' ', $name));

        return mb_substr($name, 0, 120);
    }

    private static function secret(): string
    {
        return trim((string) config('gritch.secret'));
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $text): string|false
    {
        return base64_decode(strtr($text, '-_', '+/'), true);
    }
}
