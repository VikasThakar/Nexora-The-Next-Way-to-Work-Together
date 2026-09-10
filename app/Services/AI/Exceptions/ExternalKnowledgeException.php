<?php

declare(strict_types=1);

namespace App\Services\AI\Exceptions;

use RuntimeException;

/**
 * An external lookup did not happen.
 *
 * Every message here ends up in front of a language model rather than in front
 * of a person, which changes what a good message is: it has to tell the model
 * what to do next, because a model told only that something failed will either
 * retry the same call or answer as though the lookup had succeeded. So each one
 * says what happened AND names the fallback — answer from what you already
 * know, and say the lookup did not happen.
 *
 * None of them carries a vendor response body. A search API's error can echo
 * the query, and the query is somebody's question.
 */
class ExternalKnowledgeException extends RuntimeException
{
    public static function notConfigured(string $reason): self
    {
        return new self($reason);
    }

    public static function unreachable(): self
    {
        return new self(
            'The external knowledge service could not be reached. Answer from what you already '
            .'know and say plainly that you could not look it up.'
        );
    }

    public static function rejected(int $status): self
    {
        return new self(match (true) {
            $status === 401 || $status === 403 => 'The external knowledge service refused this '
                .'workspace\'s credentials. Answer from what you already know and say the lookup '
                .'is not available; an administrator can check the configuration.',
            $status === 429 => 'The external knowledge service is rate limiting this workspace. '
                .'Answer from what you already know and say the lookup could not be made just now.',
            $status >= 500 => 'The external knowledge service reported a fault at its end. Answer '
                .'from what you already know and say the lookup did not happen.',
            default => 'The external knowledge service could not process that ('.$status.'). Answer '
                .'from what you already know and say the lookup did not happen.',
        });
    }
}
