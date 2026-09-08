<?php

declare(strict_types=1);

namespace App\Actions\Tickets;

use App\Enums\TicketPriority;
use App\Enums\TicketType;
use App\Models\Board;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketActivity;
use Illuminate\Support\Facades\DB;

/**
 * Edit a ticket and write what changed to its history.
 *
 * Field-level authorization happens here rather than only in the form. The
 * privileged fields — visibility, assignee, estimate — are silently dropped
 * when the actor is a customer, so a crafted request cannot set them even
 * though TicketPolicy::update lets a customer edit a ticket they raised.
 *
 * The diff is taken from Eloquent's own dirty tracking, so an "update" that
 * changes nothing records nothing and the timeline stays readable.
 */
class UpdateTicket
{
    /** Fields only staff may ever change. */
    private const PRIVILEGED = ['customer_visible', 'assignee_id', 'estimate'];

    /** Fields that are never edited through this action. */
    private const NOT_EDITABLE = ['board_id', 'number', 'created_by_id', 'board_column_id', 'position'];

    public function __construct(private readonly TicketActivity $activity) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Ticket $ticket, array $attributes, ?User $actor = null): Ticket
    {
        $ticket->loadMissing('board');

        $attributes = $this->sanitise($attributes, $actor);

        return DB::transaction(function () use ($ticket, $attributes, $actor): Ticket {
            if (array_key_exists('title', $attributes)) {
                $ticket->title = trim((string) $attributes['title']);
            }

            if (array_key_exists('description_md', $attributes)) {
                $ticket->description_md = $this->nullIfBlank($attributes['description_md']);
            }

            if (array_key_exists('priority', $attributes)) {
                $priority = $attributes['priority'];

                $ticket->priority = $priority instanceof TicketPriority
                    ? $priority
                    : (TicketPriority::tryFrom((string) $priority) ?? $ticket->priority);
            }

            if (array_key_exists('type', $attributes)) {
                $type = $attributes['type'];

                // An unrecognised value leaves the current type alone rather
                // than resetting it to the default, which would quietly
                // reclassify a bug as a task on a malformed request.
                $ticket->type = $type instanceof TicketType
                    ? $type
                    : (TicketType::tryFrom((string) $type) ?? $ticket->type);
            }

            if (array_key_exists('due_date', $attributes)) {
                $ticket->due_date = $this->nullIfBlank($attributes['due_date']);
            }

            if (array_key_exists('estimate', $attributes)) {
                $ticket->estimate = $this->nullIfBlank($attributes['estimate']);
            }

            if (array_key_exists('assignee_id', $attributes)) {
                $ticket->assignee_id = $this->resolveAssignee($ticket->board, $attributes['assignee_id']);
            }

            if (array_key_exists('customer_visible', $attributes)) {
                $ticket->customer_visible = (bool) $attributes['customer_visible'];
            }

            $changes = $this->diff($ticket);

            if ($changes === []) {
                return $ticket;
            }

            $ticket->save();

            $this->activity->recordChanges($ticket, $changes, $actor);

            return $ticket;
        });
    }

    /**
     * Drop fields this actor is not allowed to write.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function sanitise(array $attributes, ?User $actor): array
    {
        $attributes = array_diff_key($attributes, array_flip(self::NOT_EDITABLE));

        if ($actor !== null && ! $actor->isStaff()) {
            $attributes = array_diff_key($attributes, array_flip(self::PRIVILEGED));
        }

        return $attributes;
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function diff(Ticket $ticket): array
    {
        $changes = [];

        foreach ($ticket->getDirty() as $field => $new) {
            $old = $ticket->getOriginal($field);

            $changes[$field] = [
                $this->scalar($old),
                $this->scalar($ticket->getAttribute($field)),
            ];
        }

        return $changes;
    }

    /**
     * Payloads are JSON, so enums and dates are flattened to something that
     * still means the same thing when it is read back years later.
     */
    private function scalar(mixed $value): mixed
    {
        if ($value instanceof TicketPriority || $value instanceof TicketType) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        return (string) $value;
    }

    private function resolveAssignee(Board $board, mixed $assigneeId): ?int
    {
        if ($assigneeId === null || $assigneeId === '') {
            return null;
        }

        return $board->assignableMembers()->whereKey((int) $assigneeId)->exists()
            ? (int) $assigneeId
            : null;
    }

    private function nullIfBlank(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return trim((string) $value) === '' ? null : $value;
    }
}
