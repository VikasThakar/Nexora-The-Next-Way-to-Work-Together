<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Two-step confirmation for the changes an AI app may not make on its own say.
 *
 * laravel/mcp has no way for the server to ask the person a question (no
 * elicitation), so the confirmation is carried by the conversation instead:
 * the first call changes nothing and answers with a preview and a short code;
 * the model shows the person the preview, and only a second, identical call
 * carrying the code makes the change.
 *
 * What makes the code worth something:
 *
 *   it is bound to the person, the tool, the target and the exact arguments —
 *   a code issued to delete AQD-1 deletes nothing else, and a comment whose
 *   text changed after the preview needs a new one;
 *   it lasts ten minutes and works once (Cache::pull), so it cannot be kept
 *   and replayed;
 *   it is random, so it cannot be worked out from the ticket.
 *
 * What it cannot do is prove the person read the preview: a model could ask
 * and answer its own question. The other half of the gate is the client —
 * these tools are annotated as writes (and delete as destructive), so Claude
 * and ChatGPT ask the person before each call unless they chose to always
 * allow it. The two together are what the documentation describes.
 */
final class Confirmations
{
    private const TTL_SECONDS = 600;

    /** No 0/O, no 1/I/L: the code is read out and retyped by a model. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * A fresh code for this exact change.
     *
     * @param  array<string, mixed>  $input  the call's arguments, without the code
     */
    public function issue(User $user, string $tool, string $target, array $input): string
    {
        $code = '';

        for ($i = 0; $i < 6; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        Cache::put($this->key($user, $tool, $target, $input, $code), true, self::TTL_SECONDS);

        return $code;
    }

    /**
     * Does this code confirm this exact change? Using it spends it.
     *
     * @param  array<string, mixed>  $input  the call's arguments, without the code
     */
    public function redeem(User $user, string $tool, string $target, array $input, string $code): bool
    {
        $code = Str::upper(trim($code));

        if (preg_match('/^['.self::ALPHABET.']{6}$/', $code) !== 1) {
            return false;
        }

        return Cache::pull($this->key($user, $tool, $target, $input, $code)) === true;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function key(User $user, string $tool, string $target, array $input, string $code): string
    {
        return 'mcp-confirm:'.$user->getKey().':'.hash('sha256', implode("\0", [
            $tool,
            mb_strtolower($target),
            RepeatGuard::fingerprint($input),
            $code,
        ]));
    }
}
