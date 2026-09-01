<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TicketSubtaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A checklist item on a ticket.
 *
 * Has no visibility flag: it inherits the parent ticket entirely. Anyone who
 * can read the ticket can read the whole checklist, and anyone who cannot read
 * the ticket can never reach these rows.
 */
class TicketSubtask extends Model
{
    /** @use HasFactory<TicketSubtaskFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'title',
        'completed',
        'position',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'completed' => 'boolean',
            'completed_at' => 'datetime',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_id');
    }
}
