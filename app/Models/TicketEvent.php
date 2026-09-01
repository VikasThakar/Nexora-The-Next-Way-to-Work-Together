<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TicketEventType;
use App\Models\Concerns\BelongsToBoard;
use App\Services\BoardAccess;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a ticket's history. Append-only.
 *
 * There is no updated_at because nothing ever updates a row here. Rewriting
 * history would defeat the purpose of keeping it.
 *
 * Carries `board_id` so the board membership rule can be applied with the same
 * helper as every other board-scoped table, and so future flow metrics can
 * aggregate per board without joining tickets.
 */
class TicketEvent extends Model
{
    use BelongsToBoard;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'ticket_id',
        'board_id',
        'type',
        'from_column_id',
        'to_column_id',
        'payload',
        'actor_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => TicketEventType::class,
            'from_column_id' => 'integer',
            'to_column_id' => 'integer',
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Events that record a ticket arriving somewhere on the board.
     *
     * Creation counts: a ticket entering its first column is the start of the
     * first interval, and the flow metrics would otherwise have no beginning
     * to measure from.
     *
     * @param  Builder<TicketEvent>  $query
     */
    public function scopeTransitions(Builder $query): void
    {
        $query->whereIn('ticket_events.type', [
            TicketEventType::TicketCreated->value,
            TicketEventType::TicketMoved->value,
        ])->whereNotNull('ticket_events.to_column_id');
    }

    /**
     * Events recorded within a period, by the timestamp they carry.
     *
     * @param  Builder<TicketEvent>  $query
     */
    public function scopeBetween(Builder $query, DateTimeInterface $from, DateTimeInterface $to): void
    {
        $query->whereBetween('ticket_events.created_at', [$from, $to]);
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Drop event types a customer must not see.
     *
     * Board scoping alone is not enough here: a customer who can read a ticket
     * would otherwise see that it was flipped from internal to visible, which
     * reveals that it was previously hidden from them.
     *
     * @param  Builder<TicketEvent>  $query
     */
    public function scopeReadableBy(Builder $query, ?object $user): void
    {
        $query->visibleTo($user);

        if (app(BoardAccess::class)->canSeeInternalContent($user)) {
            return;
        }

        $internalOnly = array_values(array_map(
            fn (TicketEventType $type): string => $type->value,
            array_filter(
                TicketEventType::cases(),
                fn (TicketEventType $type): bool => $type->isInternalOnly()
            )
        ));

        if ($internalOnly !== []) {
            $query->whereNotIn('ticket_events.type', $internalOnly);
        }
    }
}
