<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Models\AiToolInvocation;

/**
 * What a tool returned.
 *
 * Deliberately not an exception-based protocol. A tool that cannot find a
 * ticket, or is asked about a board the person cannot reach, has not failed —
 * it has answered, and the answer is no. That answer goes back to the model as
 * a tool result so it can say so in words, which is a far better conversation
 * than a request that dies with a stack trace.
 *
 * The distinction the constructors draw is between the four things that can
 * happen, because each one deserves different prose in the audit trail:
 *
 *   ok         the tool ran and returned material.
 *   notFound   the subject does not exist, or the asker may not see it. The two
 *              are one case on purpose, exactly as they are everywhere else in
 *              this product — a customer must not learn that an internal
 *              ticket exists by being told "not allowed" instead of "no such
 *              ticket".
 *   refused    the tool exists but is not available here: the wrong scope, the
 *              wrong capability mode, a customer asking for staff-only data.
 *   failed     something broke. This is the only one that is a fault.
 *
 * `text` is what the model sees. It is plain text rather than JSON because the
 * model reads it, and because a JSON blob invites the model to quote structure
 * back at the person. `target` and `note` are for the audit row; none of the
 * tool's material is stored there.
 */
final readonly class AiToolOutcome
{
    private function __construct(
        public bool $success,
        public string $outcome,
        public string $text,
        public ?string $target = null,
        public ?string $note = null,
    ) {}

    public static function ok(string $text, ?string $target = null, ?string $note = null): self
    {
        return new self(true, AiToolInvocation::OUTCOME_OK, $text, $target, $note);
    }

    /**
     * Not there, or not visible to this person. See the class comment.
     */
    public static function notFound(string $text, ?string $target = null): self
    {
        return new self(false, AiToolInvocation::OUTCOME_NOT_FOUND, $text, $target, $text);
    }

    public static function refused(string $text, ?string $target = null): self
    {
        return new self(false, AiToolInvocation::OUTCOME_REFUSED, $text, $target, $text);
    }

    /**
     * The arguments did not make sense. Reported back so the model can correct
     * itself rather than being told only that something went wrong.
     */
    public static function invalid(string $text): self
    {
        return new self(false, AiToolInvocation::OUTCOME_INVALID, $text, null, $text);
    }

    public static function failed(string $text, ?string $note = null): self
    {
        return new self(false, AiToolInvocation::OUTCOME_ERROR, $text, null, $note ?? $text);
    }

    public function characters(): int
    {
        return mb_strlen($this->text);
    }
}
