<?php

declare(strict_types=1);

namespace App\Actions\Tickets;

use App\Enums\TicketEventType;
use App\Enums\TicketLinkType;
use App\Models\Ticket;
use App\Models\TicketLink;
use App\Models\User;
use App\Services\TicketActivity;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Link two tickets, possibly across boards.
 *
 * Authorization is the caller's job for the source ticket (TicketPolicy::
 * manageLinks) and this action's job for the target: linking is only allowed to
 * a ticket the actor can already see. Without that, a link would be a way to
 * confirm that AQD-40 exists on a board you have no access to — and the
 * resulting row would then be filtered out for you but visible to whoever can
 * read both ends.
 *
 * Links are stored once. Creating "A blocks B" and then "B blocked by A" is the
 * same edge, so the reverse direction is rejected as a duplicate rather than
 * stored twice.
 */
class LinkTickets
{
    public function __construct(private readonly TicketActivity $activity) {}

    public function handle(Ticket $source, Ticket $target, TicketLinkType $type, User $actor): TicketLink
    {
        if ($source->is($target)) {
            throw new RuntimeException('A ticket cannot be linked to itself.');
        }

        // The security check: the actor must already be able to read the far
        // end through the ordinary visibility scope.
        $reachable = Ticket::query()
            ->visibleTo($actor)
            ->whereKey($target->getKey())
            ->exists();

        if (! $reachable) {
            throw new RuntimeException('That ticket could not be found.');
        }

        $existing = TicketLink::query()
            ->where('type', $type->value)
            ->where(function ($query) use ($source, $target, $type): void {
                $query->where(function ($q) use ($source, $target): void {
                    $q->where('source_ticket_id', $source->getKey())
                        ->where('target_ticket_id', $target->getKey());
                });

                // "relates to" reads the same from both ends, so the mirrored
                // row is the same relationship and must not be duplicated.
                if ($type->isSymmetric()) {
                    $query->orWhere(function ($q) use ($source, $target): void {
                        $q->where('source_ticket_id', $target->getKey())
                            ->where('target_ticket_id', $source->getKey());
                    });
                }
            })
            ->first();

        if ($existing instanceof TicketLink) {
            return $existing;
        }

        return DB::transaction(function () use ($source, $target, $type, $actor): TicketLink {
            $link = TicketLink::query()->create([
                'source_ticket_id' => $source->getKey(),
                'target_ticket_id' => $target->getKey(),
                'type' => $type->value,
                'created_by_id' => $actor->getKey(),
            ]);

            $target->loadMissing('board');
            $source->loadMissing('board');

            // Recorded on both ends so either timeline explains the link.
            $this->activity->record($source, TicketEventType::LinkChanged, [
                'action' => 'added',
                'relation' => $type->outwardLabel(),
                'ticket' => $target->key(),
            ], $actor);

            $this->activity->record($target, TicketEventType::LinkChanged, [
                'action' => 'added',
                'relation' => $type->inwardLabel(),
                'ticket' => $source->key(),
            ], $actor);

            return $link;
        });
    }
}
