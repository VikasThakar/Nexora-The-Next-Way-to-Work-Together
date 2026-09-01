<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Actions\AI\Exceptions\AiRunRefused;
use App\Enums\AiRunTrigger;
use App\Models\AiRun;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "A customer raised a ticket — should the model look at it?"
 *
 * Called from TicketObserver, so it applies to every path that creates a
 * ticket: the form today, a console command or an API tomorrow. The rules:
 *
 *   - only tickets raised by a customer. Staff have a button; they do not need
 *     their own tickets triaged behind their back, and a run started by a run's
 *     own follow-up ticket is how a loop starts.
 *   - only when the board's automation is on AND its mode is not off. Both
 *     switches have to agree; see BoardAiSettings::automaticMode().
 *
 * Never throws. Ticket creation must not fail because AI could not start — a
 * customer whose request vanished because a cap was reached or a credential was
 * missing has been failed by the product. Every refusal is either recorded on
 * the timeline (by CreateAiRun, for the cap) or logged, and the ticket is
 * created either way.
 */
class TriggerAutomaticAiRun
{
    public function __construct(private readonly CreateAiRun $createAiRun) {}

    public function handle(Ticket $ticket): ?AiRun
    {
        $author = $this->author($ticket);

        // Not a customer ticket: nothing automatic happens.
        if (! $author instanceof User || ! $author->isCustomer()) {
            return null;
        }

        $ticket->loadMissing('board');

        $mode = $ticket->board->aiSettings()->automaticMode();

        if ($mode === null) {
            return null;
        }

        try {
            return $this->createAiRun->handle($ticket, $mode, AiRunTrigger::Automatic, $author);
        } catch (AiRunRefused $refused) {
            // Expected outcomes: the cap bit, the provider is unconfigured,
            // apply mode has no repository. The cap case is already on the
            // timeline; this line is for the deployment-level ones, which are
            // an operator's problem rather than the team's.
            Log::info('Automatic AI run not started.', [
                'ticket_id' => $ticket->getKey(),
                'board_id' => $ticket->board_id,
                'reason' => $refused->reason,
            ]);

            return null;
        } catch (Throwable $exception) {
            // Anything unexpected. Logged, swallowed, ticket kept.
            Log::error('Automatic AI run could not be queued.', [
                'ticket_id' => $ticket->getKey(),
                'board_id' => $ticket->board_id,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The ticket's author, loaded without relying on an eager load.
     *
     * Not $ticket->creator: strict mode forbids implicit lazy loading, and this
     * runs from an observer that must work whatever the caller loaded.
     */
    private function author(Ticket $ticket): ?User
    {
        if ($ticket->created_by_id === null) {
            return null;
        }

        return User::query()->find($ticket->created_by_id);
    }
}
