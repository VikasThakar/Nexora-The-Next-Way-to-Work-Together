<?php

declare(strict_types=1);

namespace App\Services\AI\Exceptions;

use RuntimeException;

/**
 * Apply mode could not produce a change.
 *
 * Every message here is written for the internal note a member of staff will
 * read, and names the environment variable or the binary to fix rather than the
 * class that raised it.
 *
 * `notConfigured()` is the honest default of this deployment: apply mode's
 * architecture is complete, but the step that edits files needs a coding runtime
 * that is not wired up. Saying so is the correct behaviour — the alternative
 * would be opening a pull request that contains nothing.
 */
class CodeGenerationException extends RuntimeException
{
    public static function notConfigured(string $detail): self
    {
        return new self($detail);
    }

    public static function failed(string $runtime, string $output): self
    {
        return new self(
            $runtime.' did not complete successfully'
            .($output === '' ? '.' : ":\n".mb_substr($output, 0, 2000))
        );
    }

    public static function timedOut(string $runtime): self
    {
        return new self(
            $runtime.' did not finish within AI_CODE_TIMEOUT. Nothing was committed or pushed.'
        );
    }

    public static function validationFailed(string $command, string $output): self
    {
        return new self(
            'Validation command `'.$command.'` failed, so no branch was pushed and no pull '
            ."request was opened. Output:\n".mb_substr($output, 0, 2000)
        );
    }

    public static function noChanges(): self
    {
        return new self(
            'The coding runtime finished but changed no files. Nothing was committed, and no '
            .'pull request was opened.'
        );
    }
}
