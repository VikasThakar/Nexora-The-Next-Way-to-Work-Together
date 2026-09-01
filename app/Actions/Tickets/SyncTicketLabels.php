<?php

declare(strict_types=1);

namespace App\Actions\Tickets;

use App\Enums\TicketEventType;
use App\Models\Label;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketActivity;
use Illuminate\Support\Facades\DB;

/**
 * Set the labels on a ticket.
 *
 * Label ids arrive from a form, so they are intersected with the labels that
 * actually belong to this ticket's board. A tampered id naming a label on
 * another board is dropped rather than attached, which also keeps the board
 * vocabulary from leaking across customers.
 */
class SyncTicketLabels
{
    public function __construct(private readonly TicketActivity $activity) {}

    /**
     * @param  array<int, int|string>  $labelIds
     */
    public function handle(Ticket $ticket, array $labelIds, ?User $actor = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $labelIds, $actor): Ticket {
            $owned = Label::query()
                ->where('board_id', $ticket->board_id)
                ->whereIn('id', array_map('intval', $labelIds))
                ->pluck('name', 'id');

            $before = $ticket->labels()->pluck('name', 'labels.id');

            $result = $ticket->labels()->sync($owned->keys()->all());

            $attached = array_values($result['attached'] ?? []);
            $detached = array_values($result['detached'] ?? []);

            if ($attached === [] && $detached === []) {
                return $ticket;
            }

            $this->activity->record($ticket, TicketEventType::LabelChanged, [
                'added' => array_values(array_map(
                    fn ($id): ?string => $owned[$id] ?? null,
                    $attached
                )),
                'removed' => array_values(array_map(
                    fn ($id): ?string => $before[$id] ?? null,
                    $detached
                )),
            ], $actor);

            return $ticket->load('labels');
        });
    }
}
