<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Board;
use App\Services\BoardAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared behaviour for every model that lives inside a board.
 *
 * This is the contract every board-scoped model adopts so that board scoping
 * and customer visibility can never be re-implemented, and therefore never be
 * got wrong, per model.
 *
 * A model using this trait is expected to have a `board_id` foreign key, and
 * may declare a content-visibility flag in one of two conventions:
 *
 *   - positive: `customer_visible` — customers only ever see rows where it is
 *     true. Declare it by overriding customerVisibleColumn(). Preferred for new
 *     tables because the column defaults to false and therefore fails closed.
 *   - negative: `is_internal` — customers never see rows where it is true.
 *     Detected automatically from $fillable.
 *
 * A model with neither is visible to anyone who can reach its board.
 *
 * Usage:
 *
 *     Ticket::query()->visibleTo($request->user())->get();
 */
trait BelongsToBoard
{
    /** @return BelongsTo<Board, $this> */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    /**
     * Restrict a query to rows the given user is allowed to read.
     *
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, ?Authenticatable $user): void
    {
        $access = app(BoardAccess::class);

        $access->constrain($query, $user, $this->boardForeignKey());

        if (($column = $this->customerVisibleColumn()) !== null) {
            $access->restrictToCustomerVisible($query, $user, $column, $this->customerVisibleValues());

            return;
        }

        if ($this->hasInternalFlag()) {
            $access->hideInternal($query, $user, $this->internalFlagColumn());
        }
    }

    /**
     * Restrict a query to a single board, without any visibility check.
     *
     * Always compose this with visibleTo(); on its own it is not an
     * authorization boundary.
     *
     * @param  Builder<static>  $query
     */
    public function scopeForBoard(Builder $query, Board|int $board): void
    {
        $query->where(
            $this->getTable().'.'.$this->boardForeignKey(),
            $board instanceof Board ? $board->getKey() : $board
        );
    }

    public function boardForeignKey(): string
    {
        return 'board_id';
    }

    /**
     * Override to opt into the positive visibility convention.
     *
     * Returning a column name makes customers see only rows where that column
     * is true. Returning null falls back to the `is_internal` convention.
     */
    public function customerVisibleColumn(): ?string
    {
        return null;
    }

    /**
     * The values of customerVisibleColumn() a customer is allowed to see.
     *
     * Defaults to `true`, which covers a boolean flag. Override when the column
     * is not a boolean — comments store a stream name and only some streams are
     * customer-facing:
     *
     *     public function customerVisibleColumn(): ?string { return 'stream'; }
     *     public function customerVisibleValues(): array   { return CommentStream::customerFacingValues(); }
     *
     * @return array<int, mixed>
     */
    public function customerVisibleValues(): array
    {
        return [true];
    }

    public function internalFlagColumn(): string
    {
        return 'is_internal';
    }

    public function hasInternalFlag(): bool
    {
        return in_array($this->internalFlagColumn(), $this->getFillable(), true)
            || array_key_exists($this->internalFlagColumn(), $this->getAttributes());
    }
}
