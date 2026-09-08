<?php

declare(strict_types=1);

namespace App\Actions\Tickets;

use App\Enums\TicketEventType;
use App\Enums\TicketPriority;
use App\Enums\TicketType;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketActivity;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Create a ticket.
 *
 * This is where the customer rules live, in the action rather than the form,
 * so they hold no matter which screen or future API calls it:
 *
 *   - a ticket raised by a customer is always customer-visible. A customer can
 *     never create something they then cannot see, and can never create
 *     internal work.
 *   - it always lands in the board's first column, because choosing a workflow
 *     position is the delivery team's job.
 *   - it is never assigned to anyone. Assignment is a team decision.
 *
 * Staff get the full set of fields, with customer_visible defaulting to false:
 * work is internal unless somebody deliberately says otherwise.
 */
class CreateTicket
{
    public function __construct(private readonly TicketActivity $activity) {}

    /**
     * @param  array{
     *     title: string,
     *     type?: string|TicketType|null,
     *     description_md?: ?string,
     *     priority?: string|TicketPriority|null,
     *     board_column_id?: int|string|null,
     *     assignee_id?: int|string|null,
     *     estimate?: float|string|null,
     *     due_date?: string|null,
     *     customer_visible?: bool,
     *     label_ids?: array<int, int|string>,
     * }  $attributes
     */
    public function handle(Board $board, array $attributes, User $author): Ticket
    {
        $isCustomer = $author->isCustomer();

        return DB::transaction(function () use ($board, $attributes, $author, $isCustomer): Ticket {
            $column = $isCustomer
                ? $this->firstColumn($board)
                : $this->resolveColumn($board, $attributes['board_column_id'] ?? null);

            $ticket = new Ticket([
                'board_column_id' => $column->getKey(),
                'title' => trim($attributes['title']),
                'type' => $this->resolveType($attributes['type'] ?? null),
                'description_md' => $this->nullIfBlank($attributes['description_md'] ?? null),
                'priority' => $this->resolvePriority($attributes['priority'] ?? null),
                'assignee_id' => $isCustomer
                    ? null
                    : $this->resolveAssignee($board, $attributes['assignee_id'] ?? null),
                'estimate' => $isCustomer ? null : $this->nullIfBlank($attributes['estimate'] ?? null),
                'due_date' => $this->nullIfBlank($attributes['due_date'] ?? null),
                'position' => $this->nextPosition($column),
            ]);

            // Identity and visibility are set here, never mass assigned.
            $ticket->board_id = $board->getKey();
            $ticket->number = $this->allocateNumber($board);
            $ticket->created_by_id = $author->getKey();
            $ticket->customer_visible = $isCustomer
                ? true
                : (bool) ($attributes['customer_visible'] ?? false);

            $ticket->save();

            if (! $isCustomer && ! empty($attributes['label_ids'])) {
                $ticket->labels()->sync($this->ownedLabelIds($board, $attributes['label_ids']));
            }

            $this->activity->record($ticket, TicketEventType::TicketCreated, [
                'title' => $ticket->title,
                'column' => $column->name,
                // The first column a ticket lands in is the first interval the
                // flow metrics measure, so creation is recorded as a transit
                // into it and not merely as "a ticket appeared".
                'to_column_id' => $column->getKey(),
                'type' => $ticket->type->value,
                'customer_visible' => $ticket->customer_visible,
                'raised_by_customer' => $isCustomer,
            ], $author);

            return $ticket;
        });
    }

    /**
     * Take the next number for this board under a row lock.
     *
     * Two people creating a ticket at the same moment must not be handed the
     * same number. The lock makes the read-and-increment atomic; the unique
     * index on (board_id, number) is the backstop if it ever is not.
     *
     * Numbers are never reused, so deleting AQD-3 does not cause the next
     * ticket to become AQD-3 again and quietly inherit its history in links
     * and conversations.
     */
    private function allocateNumber(Board $board): int
    {
        $current = (int) DB::table('boards')
            ->where('id', $board->getKey())
            ->lockForUpdate()
            ->value('next_ticket_number');

        $number = max($current, 1);

        DB::table('boards')
            ->where('id', $board->getKey())
            ->update(['next_ticket_number' => $number + 1]);

        return $number;
    }

    private function firstColumn(Board $board): BoardColumn
    {
        $column = $board->columns()->ordered()->first();

        if (! $column instanceof BoardColumn) {
            throw new RuntimeException('This board has no columns, so a ticket cannot be created on it.');
        }

        return $column;
    }

    /**
     * Resolve a requested column, refusing anything that is not on this board.
     *
     * The id comes from a form, so a tampered value must not be able to file a
     * ticket into another customer's board.
     */
    private function resolveColumn(Board $board, int|string|null $columnId): BoardColumn
    {
        if ($columnId === null || $columnId === '') {
            return $this->firstColumn($board);
        }

        $column = $board->columns()->whereKey((int) $columnId)->first();

        return $column instanceof BoardColumn ? $column : $this->firstColumn($board);
    }

    /**
     * Only active staff already on the board may be assigned work.
     */
    private function resolveAssignee(Board $board, int|string|null $assigneeId): ?int
    {
        if ($assigneeId === null || $assigneeId === '') {
            return null;
        }

        return $board->assignableMembers()->whereKey((int) $assigneeId)->exists()
            ? (int) $assigneeId
            : null;
    }

    /**
     * Offered to everyone, customers included.
     *
     * Unlike assignee or estimate this is not a delivery-team decision: whoever
     * raises a ticket knows better than anybody whether they are reporting a
     * defect or asking for something new, and the team can reclassify it during
     * triage. An unrecognised value falls back to the default rather than
     * failing, exactly as priority does.
     */
    private function resolveType(string|TicketType|null $type): TicketType
    {
        if ($type instanceof TicketType) {
            return $type;
        }

        return TicketType::tryFrom((string) $type) ?? TicketType::default();
    }

    private function resolvePriority(string|TicketPriority|null $priority): TicketPriority
    {
        if ($priority instanceof TicketPriority) {
            return $priority;
        }

        return TicketPriority::tryFrom((string) $priority) ?? TicketPriority::default();
    }

    /**
     * @param  array<int, int|string>  $labelIds
     * @return array<int, int>
     */
    private function ownedLabelIds(Board $board, array $labelIds): array
    {
        return $board->labels()
            ->whereIn('id', array_map('intval', $labelIds))
            ->pluck('id')
            ->all();
    }

    private function nextPosition(BoardColumn $column): int
    {
        return $column->tickets()->exists()
            ? (int) $column->tickets()->max('position') + 1
            : 0;
    }

    private function nullIfBlank(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return trim((string) $value) === '' ? null : $value;
    }
}
