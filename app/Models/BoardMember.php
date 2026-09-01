<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BoardMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot model for board membership.
 *
 * Modelled explicitly rather than as an anonymous pivot because membership is
 * a first-class domain concept: it is audited, event-driven, and will grow
 * additional columns (per-board role, invitation state) in later phases.
 */
class BoardMember extends Pivot
{
    /** @use HasFactory<BoardMemberFactory> */
    use HasFactory;

    protected $table = 'board_members';

    public $incrementing = true;

    /** @var list<string> */
    protected $fillable = [
        'board_id',
        'user_id',
        'added_by_id',
    ];

    /** @return BelongsTo<Board, $this> */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_id');
    }
}
