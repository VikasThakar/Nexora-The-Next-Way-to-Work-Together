<?php

declare(strict_types=1);

namespace App\Services\AI\Audit;

use App\Models\AiChatMessage;
use App\Models\AiSession;
use App\Models\AiToolInvocation;
use App\Models\Board;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only writer to `ai_tool_invocations`.
 *
 * Two properties make this class worth having rather than inlining an insert
 * at each call site.
 *
 * It never breaks the thing it is recording
 * -----------------------------------------
 * Every method swallows its own failures and reports them to the log. An audit
 * row is important, but it is less important than the answer somebody is
 * waiting for: a full disk or a locked table must not turn a working assistant
 * into a broken one. The trade is stated here so that nobody has to guess which
 * way it goes, and the failure is loud in the log rather than silent.
 *
 * It decides what is safe to keep
 * -------------------------------
 * `sanitise()` is the whole answer to "do not store secrets". It is an
 * allow-list by shape rather than a deny-list by name: scalars survive,
 * bounded and truncated; anything else — an object, a nested array, a resource
 * — is replaced by a description of itself. A deny-list of key names
 * ("password", "token") is the version of this that fails the first time
 * somebody invents a new name for a secret.
 *
 * Nothing here stores a tool's *output*. See the migration for why: a result is
 * a copy of internal workspace content, and a copy is a second place for it to
 * leak from.
 */
class AiAuditLogger
{
    /** Characters of any one string argument that are kept. */
    private const MAX_VALUE_LENGTH = 300;

    /** Arguments kept from one call. */
    private const MAX_KEYS = 20;

    /** Characters of the human-readable message that are kept. */
    private const MAX_MESSAGE_LENGTH = 1000;

    /**
     * Write one row.
     *
     * @param  array<string, mixed>  $input  tool arguments; sanitised here
     */
    public function record(
        string $tool,
        string $category,
        string $outcome,
        bool $success,
        ?User $user = null,
        ?AiSession $session = null,
        ?Board $board = null,
        ?AiChatMessage $message = null,
        ?string $target = null,
        array $input = [],
        ?string $note = null,
        ?int $resultCharacters = null,
        ?int $durationMs = null,
    ): ?AiToolInvocation {
        try {
            $row = new AiToolInvocation;

            // Assigned rather than mass assigned, because an audit row that a
            // request could shape is an audit row worth nothing.
            $row->ai_session_id = $session?->getKey();
            $row->ai_chat_message_id = $message?->getKey();
            $row->user_id = $user?->getKey();
            $row->board_id = $board?->getKey() ?? $session?->board_id;
            $row->tool = mb_substr($tool, 0, 64);
            $row->category = mb_substr($category, 0, 24);
            $row->target = $target === null ? null : mb_substr($target, 0, 190);
            $row->input = $this->sanitise($input);
            $row->success = $success;
            $row->outcome = mb_substr($outcome, 0, 32);
            $row->message = $note === null ? null : mb_substr($note, 0, self::MAX_MESSAGE_LENGTH);
            $row->result_characters = $resultCharacters === null ? null : max(0, $resultCharacters);
            $row->duration_ms = $durationMs === null ? null : max(0, $durationMs);

            $row->save();

            return $row;
        } catch (Throwable $exception) {
            /*
             * Loud in the log, invisible to the person asking.
             *
             * Only the exception's own message: the row that failed to save
             * carries the sanitised arguments, and logging those again here
             * would put them in a second place with different retention.
             */
            Log::error('Could not write an AI audit row.', [
                'tool' => $tool,
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Arguments, reduced to what is safe to keep for ever.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<string, mixed>|null
     */
    public function sanitise(array $input): ?array
    {
        if ($input === []) {
            return null;
        }

        $safe = [];

        foreach ($input as $key => $value) {
            if (count($safe) >= self::MAX_KEYS) {
                $safe['…'] = 'more arguments not recorded';

                break;
            }

            // A key is a schema property name, so it is short and simple by
            // construction. Anything else is a sign the caller is not passing
            // tool arguments, and is dropped rather than trusted.
            if (! is_string($key) || preg_match('/^[a-z0-9_]{1,40}$/i', $key) !== 1) {
                continue;
            }

            $safe[$key] = $this->scalar($value);
        }

        return $safe === [] ? null : $safe;
    }

    /**
     * One argument value, as something that can be written to JSON safely.
     */
    private function scalar(mixed $value): mixed
    {
        if (is_bool($value) || is_int($value) || $value === null) {
            return $value;
        }

        if (is_float($value)) {
            // Non-finite floats are not valid JSON and would fail the insert.
            return is_finite($value) ? $value : null;
        }

        if (is_string($value)) {
            $value = trim($value);

            return mb_strlen($value) > self::MAX_VALUE_LENGTH
                ? mb_substr($value, 0, self::MAX_VALUE_LENGTH).'…'
                : $value;
        }

        if (is_array($value)) {
            // A list of scalars is a real tool argument shape ("labels":
            // ["bug","urgent"]); anything deeper is described, not stored.
            $flat = array_filter($value, static fn ($item): bool => is_scalar($item) || $item === null);

            if (count($flat) !== count($value)) {
                return '('.count($value).' nested values not recorded)';
            }

            return array_map(fn ($item): mixed => $this->scalar($item), array_slice(array_values($flat), 0, 10));
        }

        // An object, a closure, a resource, an uploaded file. Its type is the
        // useful fact; its contents are not something to keep.
        return '('.get_debug_type($value).' not recorded)';
    }
}
