<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who wrote one turn of a workspace AI conversation.
 *
 * The stored transcript is replayed to the provider as conversation history, so
 * the role has to survive a round trip through the database intact. There are
 * deliberately only two: a system prompt is rebuilt from the board's live
 * settings on every request rather than stored, so changing a board's project
 * context takes effect immediately instead of being frozen into an old row.
 */
enum AiChatRole: string
{
    case User = 'user';
    case Assistant = 'assistant';

    public function label(): string
    {
        return match ($this) {
            self::User => 'You',
            self::Assistant => 'Assistant',
        };
    }

    public function isAssistant(): bool
    {
        return $this === self::Assistant;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
