<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use RuntimeException;

/**
 * A tool was called with arguments that do not fit its schema.
 *
 * The message is written for the model, not for a log: it is handed back as the
 * tool result so the model can correct the call and try again. That is why it
 * names the offending argument and what was expected — "limit must be a whole
 * number between 1 and 50" gets a working second attempt, where "invalid input"
 * gets an apology to the person asking.
 */
class AiToolInputException extends RuntimeException
{
    public static function notAnObject(): self
    {
        return new self('The arguments must be a JSON object of named values.');
    }

    public static function missing(string $property): self
    {
        return new self('The argument "'.$property.'" is required and was not supplied.');
    }

    public static function wrongType(string $property, string $expected): self
    {
        return new self('The argument "'.$property.'" must be '.$expected.'.');
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function notAllowed(string $property, array $allowed): self
    {
        return new self(
            'The argument "'.$property.'" must be one of: '.implode(', ', $allowed).'.'
        );
    }
}
