<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an uploaded file is in the pipeline.
 *
 * Four of the five states are visible on the attachment card, because the
 * person who just dropped a 40-page PDF into the composer needs to know
 * whether the assistant can read it yet. The card polls while a row is in a
 * non-terminal state and stops when it reaches one.
 *
 * Failed and Unsupported are separate on purpose, and the difference is whose
 * problem it is:
 *
 *   Failed        something went wrong on our side — a PDF we could not parse,
 *                 a transcription that errored. Retrying may work.
 *   Unsupported   the file is what it says it is and we cannot read that kind.
 *                 Retrying will not help, and the card says so instead of
 *                 offering a retry that cannot succeed.
 *
 * Nothing here is a security decision. A Ready row is readable *content*, not
 * a readable *file*: authorization still runs through AttachmentPolicy on
 * every download and through AiSessionPolicy on every use.
 */
enum AiAttachmentStatus: string
{
    case Pending = 'pending';

    case Processing = 'processing';

    case Ready = 'ready';

    case Failed = 'failed';

    case Unsupported = 'unsupported';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::Processing => 'Processing…',
            self::Ready => 'Ready',
            self::Failed => 'Failed',
            self::Unsupported => 'Cannot be read',
        };
    }

    /**
     * Has the pipeline finished with this row?
     *
     * What the card's polling is keyed off. A row that is not terminal will
     * change on its own; a row that is terminal will not, so the browser stops
     * asking.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Pending, self::Processing => false,
            default => true,
        };
    }

    public function isReady(): bool
    {
        return $this === self::Ready;
    }

    /**
     * Did this end badly enough to be worth colouring?
     */
    public function isProblem(): bool
    {
        return $this === self::Failed || $this === self::Unsupported;
    }

    /**
     * The x-ui.badge variant, so the states are coloured the same everywhere.
     *
     * Amber for a problem rather than rose: an attachment we could not read is
     * a warning, not an error in the product, and amber is this design
     * system's warning colour — see the note about what amber means in Nexora.
     */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Ready => 'emerald',
            self::Failed, self::Unsupported => 'amber',
            default => 'slate',
        };
    }
}
