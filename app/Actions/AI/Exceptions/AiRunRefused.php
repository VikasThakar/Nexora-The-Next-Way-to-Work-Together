<?php

declare(strict_types=1);

namespace App\Actions\AI\Exceptions;

use RuntimeException;

/**
 * A run was not created, and this is why.
 *
 * A refusal is not a fault: the cap doing its job, an unconfigured provider and
 * a board with no repositories are all normal states. So this carries a reason
 * code as well as prose — the code is what a caller branches on and what gets
 * recorded on the ticket timeline, the prose is what a member of staff reads.
 *
 * Automatic triggers catch this and record it quietly. A manual trigger lets it
 * reach the screen, because somebody pressed a button and is waiting for an
 * answer.
 */
class AiRunRefused extends RuntimeException
{
    public const REASON_DISABLED = 'ai_disabled';

    public const REASON_NOT_CONFIGURED = 'provider_not_configured';

    public const REASON_CAP_REACHED = 'daily_cap_reached';

    public const REASON_NO_REPOSITORY = 'no_repository';

    public const REASON_ALREADY_RUNNING = 'already_running';

    public const REASON_MODE_OFF = 'mode_off';

    private function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function disabled(): self
    {
        return new self(
            self::REASON_DISABLED,
            'AI features are switched off for this deployment (AI_ENABLED).'
        );
    }

    public static function notConfigured(): self
    {
        return new self(
            self::REASON_NOT_CONFIGURED,
            'No AI provider credential is configured, so no run could be started. '
            .'Set ANTHROPIC_API_KEY on the web and worker services.'
        );
    }

    public static function capReached(string $message): self
    {
        return new self(self::REASON_CAP_REACHED, $message);
    }

    /**
     * Apply mode with nothing to apply a change to.
     *
     * Suggest mode never lands here: it can reason about a ticket with no
     * repository at all and says so in its own answer.
     */
    public static function noRepository(): self
    {
        return new self(
            self::REASON_NO_REPOSITORY,
            'Apply mode needs one identifiable repository, and this board has none that could be '
            .'chosen without guessing. Attach a repository, or mark one as primary.'
        );
    }

    public static function alreadyRunning(): self
    {
        return new self(
            self::REASON_ALREADY_RUNNING,
            'An AI run is already queued or running for this ticket.'
        );
    }

    public static function modeOff(): self
    {
        return new self(self::REASON_MODE_OFF, 'Automatic AI runs are switched off for this board.');
    }
}
