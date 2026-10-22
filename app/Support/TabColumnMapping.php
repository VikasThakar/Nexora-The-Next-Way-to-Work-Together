<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BoardColumn;
use App\Models\BoardTab;
use Illuminate\Support\Collection;

/**
 * Where a ticket goes when it moves to another tab without naming a column.
 *
 * One rule, used everywhere a ticket changes tab: deleting a tab (its tickets
 * move to the chosen tab), dragging a card onto a tab, the Tab select on the
 * ticket page, and an AI or MCP move that names only the tab. The preview in
 * the delete-tab panel uses it too, so what it promises is what happens.
 *
 *   1. the column with the same name (ignoring case) — "In Progress" stays
 *      "In Progress";
 *   2. otherwise, a ticket from a done column goes to the first done column,
 *      so finished work does not reopen;
 *   3. otherwise the first column of the tab.
 *
 * A name match wins over the done rule: a column called "Done" that is not
 * marked done still receives the "Done" tickets. That is the visible, least
 * surprising outcome, and the preview says so.
 */
class TabColumnMapping
{
    public const REASON_NAME = 'name';

    public const REASON_DONE = 'done';

    public const REASON_FIRST = 'first';

    /**
     * The column a ticket in $from lands in on $to, or null when $to has no
     * columns at all.
     */
    public static function counterpart(BoardColumn $from, BoardTab $to): ?BoardColumn
    {
        return self::match($from, self::columnsOf($to))['column'] ?? null;
    }

    /**
     * Every column of $from with the column its tickets land in on $to, and
     * why — for the delete panel's preview and for DeleteTab.
     *
     * @return array<int, array{column: BoardColumn, reason: string}> keyed by the $from column id;
     *                                                                empty when $to has no columns
     */
    public static function map(BoardTab $from, BoardTab $to): array
    {
        $targets = self::columnsOf($to);
        $map = [];

        foreach (self::columnsOf($from) as $column) {
            $match = self::match($column, $targets);

            if ($match !== null) {
                $map[(int) $column->getKey()] = $match;
            }
        }

        return $map;
    }

    /**
     * @param  Collection<int, BoardColumn>  $targets  in tab order
     * @return array{column: BoardColumn, reason: string}|null
     */
    private static function match(BoardColumn $from, Collection $targets): ?array
    {
        if ($targets->isEmpty()) {
            return null;
        }

        $name = mb_strtolower(trim((string) $from->name));

        $same = $targets->first(
            static fn (BoardColumn $column): bool => mb_strtolower(trim((string) $column->name)) === $name
        );

        if ($same instanceof BoardColumn) {
            return ['column' => $same, 'reason' => self::REASON_NAME];
        }

        if ($from->is_done) {
            $done = $targets->first(static fn (BoardColumn $column): bool => (bool) $column->is_done);

            if ($done instanceof BoardColumn) {
                return ['column' => $done, 'reason' => self::REASON_DONE];
            }
        }

        return ['column' => $targets->first(), 'reason' => self::REASON_FIRST];
    }

    /** @return Collection<int, BoardColumn> */
    private static function columnsOf(BoardTab $tab): Collection
    {
        return $tab->relationLoaded('columns') ? $tab->columns : $tab->columns()->get();
    }
}
