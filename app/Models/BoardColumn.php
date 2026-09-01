<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoard;
use Database\Factories\BoardColumnFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Kanban column.
 *
 * Columns carry no visibility flag of their own: a column is part of the board
 * layout and everyone who can reach the board sees the same columns. What
 * differs per viewer is which tickets appear inside them.
 */
class BoardColumn extends Model
{
    /** @use HasFactory<BoardColumnFactory> */
    use BelongsToBoard;

    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'position',
        'is_done',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_done' => 'boolean',
        ];
    }

    /**
     * The columns every new board starts with.
     *
     * @return array<int, array{name: string, is_done: bool}>
     */
    public static function defaults(): array
    {
        return [
            ['name' => 'Backlog', 'is_done' => false],
            ['name' => 'To Do', 'is_done' => false],
            ['name' => 'In Progress', 'is_done' => false],
            ['name' => 'Review', 'is_done' => false],
            ['name' => 'Done', 'is_done' => true],
        ];
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** @param  Builder<BoardColumn>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }
}
