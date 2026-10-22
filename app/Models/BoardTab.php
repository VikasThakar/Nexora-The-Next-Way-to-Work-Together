<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoard;
use Database\Factories\BoardTabFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * One tab inside a board ("Admin dashboard", "Public website").
 *
 * A board is split into tabs, and each tab has its own ordered columns; the
 * board page shows one tab at a time. Tickets are not linked to a tab
 * directly — a ticket's tab is the tab of its column — so a ticket can never
 * sit in a column of another tab, and moving a ticket between tabs is simply
 * moving it to a column of the other tab.
 *
 * Like columns, tabs carry no visibility flag: the board's structure is not
 * secret, so everyone who can reach the board sees every tab's name. What
 * differs per viewer is which tickets appear inside.
 *
 * The leftmost tab is the board's default: where a ticket created without a
 * tab lands, and what the board opens on.
 */
class BoardTab extends Model
{
    /** @use HasFactory<BoardTabFactory> */
    use BelongsToBoard;

    use HasFactory;

    /** The tab every board starts with, and the one existing boards were given. */
    public const DEFAULT_NAME = 'Main';

    public const MAX_NAME_LENGTH = 60;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'position',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /** @return HasMany<BoardColumn, $this> */
    public function columns(): HasMany
    {
        return $this->hasMany(BoardColumn::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Every ticket in this tab, unfiltered.
     *
     * NOT an authorization boundary — read tickets through Ticket::visibleTo()
     * (TicketFinder), which applies membership and the customer rule in SQL.
     *
     * @return HasManyThrough<Ticket, BoardColumn, $this>
     */
    public function tickets(): HasManyThrough
    {
        return $this->hasManyThrough(Ticket::class, BoardColumn::class);
    }

    /**
     * Where a ticket lands in this tab when no column is chosen — where a
     * customer's request in this tab always starts.
     */
    public function firstColumn(): ?BoardColumn
    {
        if ($this->relationLoaded('columns')) {
            return $this->columns->first();
        }

        return $this->columns()->first();
    }

    /** @param  Builder<BoardTab>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }

    /**
     * Which of these boards are split into more than one tab — the boards
     * whose column labels name the tab ("Backend API › Doing"). One grouped
     * query for any number of boards.
     *
     * @param  array<int, int|string>  $boardIds
     * @return array<int, int>
     */
    public static function boardIdsWithSeveralTabs(array $boardIds): array
    {
        if ($boardIds === []) {
            return [];
        }

        return static::query()
            ->whereIn('board_id', $boardIds)
            ->groupBy('board_id')
            ->havingRaw('count(*) > 1')
            ->pluck('board_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
