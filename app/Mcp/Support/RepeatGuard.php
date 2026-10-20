<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * One request, one ticket — however many times a client sends it.
 *
 * AI clients retry. A call that timed out on the client's side may well have
 * succeeded on ours, and the retry would raise the same ticket twice or post
 * the same comment twice. Asking models to send an idempotency key does not
 * work in practice, so the server recognises the repeat itself: the same
 * person, the same tool, the same arguments, within 90 seconds, gets the first
 * call's answer back, marked as a repeat, and nothing new is written.
 *
 * The check runs under a lock on that fingerprint, so two copies arriving at
 * the same moment cannot both get through. Only successful results are
 * remembered: a call that was refused or failed can simply be made again.
 *
 * Used for the tools that add something (tickets, comments, pages). Updates
 * are naturally safe to repeat — setting a field to the value it already has
 * changes nothing and records nothing.
 */
final class RepeatGuard
{
    private const WINDOW_SECONDS = 90;

    /**
     * @param  array<string, mixed>  $input
     * @param  Closure(): array{result: array<string, mixed>, remember: bool}  $callback
     * @return array{result: array<string, mixed>, repeated: bool}
     */
    public function once(User $user, string $tool, array $input, Closure $callback): array
    {
        $key = 'mcp-repeat:'.$user->getKey().':'.$tool.':'.self::fingerprint($input);

        $lock = Cache::lock($key.':lock', 30);

        // Somebody is making this exact change right now. Waiting a moment
        // for them is better than refusing: the answer is then the same one.
        try {
            $lock->block(10);
        } catch (LockTimeoutException) {
            throw new RuntimeException('That exact change is already being made. Nothing new was done — check the result in a moment.');
        }

        try {
            $previous = Cache::get($key);

            if (is_array($previous)) {
                return ['result' => $previous, 'repeated' => true];
            }

            $result = $callback();

            if ($result['remember']) {
                Cache::put($key, $result['result'], self::WINDOW_SECONDS);
            }

            return ['result' => $result['result'], 'repeated' => false];
        } finally {
            $lock->release();
        }
    }

    /**
     * A stable hash of a call's arguments: key order and surrounding
     * whitespace do not make two calls different.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fingerprint(array $input): string
    {
        return hash('sha256', (string) json_encode(self::normalise($input), JSON_UNESCAPED_UNICODE));
    }

    private static function normalise(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(self::normalise(...), $value);
        }

        return is_string($value) ? trim($value) : $value;
    }
}
