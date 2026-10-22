<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BoardColumn;
use App\Models\BoardTab;
use App\Models\Ticket;

/**
 * Where a ticket is, in words: "Backend API › Doing".
 *
 * The tab is named only on a board split into more than one tab, so a board
 * with a single tab reads exactly as boards did before tabs existed — every
 * label, export and AI answer for those boards stays the same.
 *
 * One place for the format, so the board, the ticket page, notifications,
 * statistics, the AI and MCP all say it the same way. Callers that show a
 * list must eager-load `board` and `column.tab` (strict mode turns a
 * forgotten eager load into an error rather than an N+1).
 */
class TicketPlace
{
    public const SEPARATOR = ' › ';

    public static function of(Ticket $ticket): string
    {
        $column = $ticket->column;

        if (! $column instanceof BoardColumn) {
            return '';
        }

        return self::column($column, $ticket->board->hasMultipleTabs());
    }

    public static function column(BoardColumn $column, bool $withTab): string
    {
        if (! $withTab) {
            return (string) $column->name;
        }

        $tab = $column->tab;

        return $tab instanceof BoardTab
            ? self::names((string) $tab->name, (string) $column->name)
            : (string) $column->name;
    }

    /**
     * The same label rebuilt from names stored in a history payload, where
     * the tab is present only if the board had several tabs at the time.
     */
    public static function names(?string $tab, ?string $column): string
    {
        $tab = trim((string) $tab);
        $column = trim((string) $column);

        return match (true) {
            $tab !== '' && $column !== '' => $tab.self::SEPARATOR.$column,
            $column !== '' => $column,
            default => $tab,
        };
    }
}
