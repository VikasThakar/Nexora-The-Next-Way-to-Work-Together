<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LabelColor;
use App\Models\Concerns\BelongsToBoard;
use Database\Factories\LabelFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A board-specific label.
 *
 * Like columns, labels are part of the board vocabulary rather than content:
 * everyone who can reach the board sees the same label set. Whether a customer
 * sees a particular labelled ticket is decided on the ticket, not here.
 */
class Label extends Model
{
    /** @use HasFactory<LabelFactory> */
    use BelongsToBoard;

    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'color',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'color' => LabelColor::class,
        ];
    }

    /** @return BelongsToMany<Ticket, $this> */
    public function tickets(): BelongsToMany
    {
        return $this->belongsToMany(Ticket::class, 'ticket_label')->withTimestamps();
    }

    public function chipClasses(): string
    {
        return $this->color->chipClasses();
    }

    /** @param  Builder<Label>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('name');
    }
}
