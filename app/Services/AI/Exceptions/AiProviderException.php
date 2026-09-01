<?php

declare(strict_types=1);

namespace App\Services\AI\Exceptions;

use RuntimeException;

/**
 * The provider could not be reached, refused the credential, rate limited, or
 * answered with something that could not be interpreted.
 *
 * The message on this exception is written to be read by a member of staff in
 * an internal note, so it says what happened and what to do about it — and it
 * never contains a credential. The named constructors below are the only way
 * the message is built, which is what keeps that true.
 */
class AiProviderException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self(
            'No AI provider is configured for this deployment. Set ANTHROPIC_API_KEY '
            .'(and AI_ENABLED=true) on the queue worker service.'
        );
    }

    public static function unauthorized(): self
    {
        return new self(
            'The AI provider rejected the configured credential. Check ANTHROPIC_API_KEY '
            .'on the queue worker service.'
        );
    }

    public static function rateLimited(): self
    {
        return new self('The AI provider is rate limiting this workspace. The run will be retried.');
    }

    public static function overloaded(): self
    {
        return new self('The AI provider is temporarily overloaded. The run will be retried.');
    }

    public static function timedOut(): self
    {
        return new self('The AI provider did not answer within the configured timeout.');
    }

    /**
     * A status the adapter has no specific advice for.
     *
     * The provider's own message is included because it is usually the useful
     * part ("model not found", "max_tokens too large"), and it is provider prose
     * rather than anything derived from a secret.
     */
    public static function status(int $status, string $message): self
    {
        return new self(sprintf('The AI provider returned HTTP %d: %s', $status, trim($message)));
    }

    public static function transport(string $message): self
    {
        return new self('Could not reach the AI provider: '.trim($message));
    }

    public static function emptyResponse(): self
    {
        return new self('The AI provider returned no usable text.');
    }

    public static function refused(?string $category): self
    {
        return new self(
            'The AI provider declined to answer this request'
            .($category !== null ? ' ('.$category.')' : '').'.'
        );
    }
}
