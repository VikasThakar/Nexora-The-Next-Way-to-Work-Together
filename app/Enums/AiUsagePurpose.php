<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a metered call to a provider was for.
 *
 * `ai_usage_records` holds one row per provider exchange, and the two things
 * that spend tokens in this product arrive by very different routes — a person
 * typing in the assistant panel, and a queue worker analysing a ticket. Keeping
 * the purpose on the row means the usage screens can separate "what the team
 * asked" from "what automation cost" without joining back to work out which
 * table the row came from.
 */
enum AiUsagePurpose: string
{
    case Chat = 'chat';
    case TicketRun = 'ticket_run';

    public function label(): string
    {
        return match ($this) {
            self::Chat => 'Assistant',
            self::TicketRun => 'Ticket run',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
