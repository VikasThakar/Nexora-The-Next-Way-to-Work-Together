<?php

declare(strict_types=1);

namespace App\Services\AI\Exceptions;

use RuntimeException;

/**
 * The isolated working directory for a run could not be prepared safely.
 *
 * Every one of these is a refusal rather than a fault: the run stops before it
 * touches a filesystem it should not, and the reason lands in an internal note.
 */
class AiWorkspaceException extends RuntimeException
{
    public static function notWritable(string $path): self
    {
        return new self(
            'Could not create the isolated working directory for this run at '.$path
            .'. Check that the worker can write to storage/app.'
        );
    }

    /**
     * The configured base resolved somewhere outside storage/app — a mistyped
     * AI_WORKSPACE_PATH, most likely. Running an agentic coding tool there
     * would let it edit the application itself.
     */
    public static function escapedBase(string $path, string $base): self
    {
        return new self(
            'Refusing to use '.$path.' as a working directory: it resolves outside '.$base
            .'. Check AI_WORKSPACE_PATH.'
        );
    }

    public static function invalidIdentifier(string $uuid): self
    {
        return new self('Refusing to build a working directory from a malformed run identifier.');
    }
}
