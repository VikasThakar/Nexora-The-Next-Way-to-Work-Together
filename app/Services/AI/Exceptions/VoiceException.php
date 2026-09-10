<?php

declare(strict_types=1);

namespace App\Services\AI\Exceptions;

use RuntimeException;

/**
 * Something went wrong holding a spoken conversation.
 *
 * Every message here is written to be shown to the person who was talking, so
 * each one says what happened and what to do about it. None of them carries a
 * vendor response body: a synthesis endpoint's error can echo the text it was
 * given, which is the answer to somebody's question, and an error message is a
 * worse place for that than the page it was already on.
 */
class VoiceException extends RuntimeException
{
    public static function notConfigured(string $reason): self
    {
        return new self($reason);
    }

    public static function unreachable(): self
    {
        return new self(
            'The voice service could not be reached. The conversation is still there — '
            .'type the question instead, or try speaking again in a moment.'
        );
    }

    public static function rejected(int $status): self
    {
        return new self(match (true) {
            $status === 401 || $status === 403 => 'The voice service refused the API key configured for '
                .'this workspace. An administrator can check it in the global AI settings.',
            $status === 429 => 'The voice service is rate limiting this workspace. Wait a moment and '
                .'try again, or type instead.',
            $status >= 500 => 'The voice service reported a fault at its end. Typing still works.',
            default => 'The voice service could not process that ('.$status.'). Typing still works.',
        });
    }

    public static function nothingHeard(): self
    {
        return new self(
            'Nothing could be made out in that recording. Check the microphone and try again, '
            .'a little closer and a little slower.'
        );
    }

    public static function recordingTooLarge(int $megabytes): self
    {
        return new self(
            'That recording is longer than a single turn allows ('.$megabytes.' MB). '
            .'Say it in a shorter turn, or type it.'
        );
    }

    public static function nothingToSay(): self
    {
        return new self('There is nothing in that answer to read aloud.');
    }
}
