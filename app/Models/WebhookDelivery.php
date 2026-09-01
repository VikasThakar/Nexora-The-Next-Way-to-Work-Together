<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One inbound webhook delivery.
 *
 * The row is written by the controller *before* the payload is queued, so a
 * delivery that crashes the worker still leaves evidence that it arrived. See
 * the migration for why de-duplication is a unique index rather than a cache
 * entry, and why the payload itself is not stored.
 */
class WebhookDelivery extends Model
{
    public const SOURCE_GITHUB = 'github';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSED = 'processed';

    /** Understood, verified, and correctly nothing to do. Not a failure. */
    public const STATUS_IGNORED = 'ignored';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    protected $fillable = [
        'source',
        'delivery_id',
        'event',
        'status',
        'links_written',
        'error',
        'processed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'links_written' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    public function markProcessed(int $linksWritten = 0): void
    {
        $this->forceFill([
            'status' => $linksWritten > 0 ? self::STATUS_PROCESSED : self::STATUS_IGNORED,
            'links_written' => $linksWritten,
            'error' => null,
            'processed_at' => now(),
        ])->save();
    }

    /**
     * Record a failure.
     *
     * The reason is truncated rather than stored whole: it is written by the
     * processor from its own vocabulary, but truncation is a cheap second
     * guarantee that a long exception chain can never fill the column with
     * something nobody meant to persist.
     */
    public function markFailed(string $reason): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error' => mb_substr($reason, 0, 500),
            'processed_at' => now(),
        ])->save();
    }

    /** @param  Builder<WebhookDelivery>  $query */
    public function scopeFromGithub(Builder $query): void
    {
        $query->where('source', self::SOURCE_GITHUB);
    }
}
