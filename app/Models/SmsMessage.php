<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationEvent;
use App\Enums\SmsStatus;
use App\Services\SMS\Data\SmsResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record of one outbound alert.
 *
 * Not board-scoped in the `BelongsToBoard` sense, and deliberately so: this
 * table is operational evidence rather than board content, it is never rendered
 * to a customer, and the only screen that reads it is the board integrations
 * page, which is already staff-gated. Adding a `visibleTo()` scope here would
 * imply there is a version of it a customer may see, and there is not.
 *
 * Written only by App\Services\SMS\SmsService.
 */
class SmsMessage extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'ticket_id',
        'board_id',
        'ticket_key',
        'event',
        'recipient',
        'body',
        'status',
        'provider',
        'provider_message_id',
        'cost',
        'error',
        'sent_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SmsStatus::class,
            'event' => NotificationEvent::class,
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<Board, $this> */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    /**
     * Record what the provider said.
     *
     * `sent_at` is set only for a real acceptance, never for a logged message —
     * so "when was this actually sent" has no answer for messages that were
     * not, rather than a plausible-looking timestamp.
     */
    public function recordResult(SmsResult $result, string $provider): void
    {
        $this->forceFill([
            'status' => $result->status,
            'provider' => $provider,
            'provider_message_id' => $result->providerMessageId,
            'cost' => $result->cost,
            'error' => $result->error === null ? null : mb_substr($result->error, 0, 500),
            'sent_at' => $result->status->reachedProvider() ? now() : null,
        ])->save();
    }

    public function recordFailure(string $reason): void
    {
        $this->forceFill([
            'status' => SmsStatus::Failed,
            'error' => mb_substr($reason, 0, 500),
        ])->save();
    }

    /**
     * The number, partially hidden, for anything that renders it.
     *
     * The full value is in the column because the table's purpose is to answer
     * "was this number contacted"; the screen showing a delivery history does
     * not need to publish an engineer's mobile number to everyone who can open
     * the board settings.
     */
    public function maskedRecipient(): string
    {
        $number = (string) $this->recipient;

        return mb_strlen($number) <= 4
            ? '****'
            : mb_substr($number, 0, 3).str_repeat('*', max(0, mb_strlen($number) - 6)).mb_substr($number, -3);
    }

    /** @param  Builder<SmsMessage>  $query */
    public function scopeRecentForBoard(Builder $query, int $boardId, int $seconds): void
    {
        $query->where('board_id', $boardId)
            ->where('created_at', '>=', now()->subSeconds(max(1, $seconds)));
    }
}
