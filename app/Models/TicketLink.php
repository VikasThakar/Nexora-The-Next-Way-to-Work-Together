<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TicketLinkType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A relationship between two tickets, possibly on different boards.
 *
 * Security note: this is the one place a ticket points at a row the viewer may
 * not be allowed to see. Nothing may render `$link->target` directly — reads go
 * through App\Services\TicketLinkReader, which re-queries both ends through the
 * ordinary visibility scope and reports unreachable links as a bare count.
 */
class TicketLink extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'source_ticket_id',
        'target_ticket_id',
        'type',
        'created_by_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => TicketLinkType::class,
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'source_ticket_id');
    }

    /** @return BelongsTo<Ticket, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'target_ticket_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
