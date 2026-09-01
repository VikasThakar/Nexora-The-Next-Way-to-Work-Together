<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What happened to one outbound SMS.
 *
 * `Logged` exists as a state of its own, separate from `Sent`, and that
 * distinction is the point of this enum.
 *
 * The local driver writes messages to the application log so a developer can
 * see what would have gone out. If those were recorded as `Sent`, every count,
 * every report and every "did the on-call get paged?" question would be
 * answered with a number that includes messages nobody received. A development
 * convenience must not be able to launder itself into evidence of delivery.
 *
 * `Sent` means a provider accepted the message. It does not mean a handset
 * received it — no SMS API can promise that synchronously, and this application
 * does not currently consume delivery receipts. The distinction is documented
 * rather than modelled, because a `Delivered` state nothing ever sets would be
 * worse than its absence.
 */
enum SmsStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Logged = 'logged';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Sent => 'Accepted by provider',
            self::Logged => 'Logged only (not sent)',
            self::Failed => 'Failed',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Sent => 'emerald',
            self::Queued => 'amber',
            self::Logged => 'slate',
            self::Failed => 'rose',
        };
    }

    /** Did a real provider accept this message? */
    public function reachedProvider(): bool
    {
        return $this === self::Sent;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
