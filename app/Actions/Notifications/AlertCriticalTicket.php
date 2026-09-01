<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Enums\NotificationEvent;
use App\Enums\TicketPriority;
use App\Models\Board;
use App\Models\Ticket;
use App\Services\SMS\SmsService;
use App\Support\BoardSmsSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Texts the on-call numbers when a critical ticket is raised.
 *
 * The single trigger for SMS in this application. Deliberately narrow: an SMS
 * reaches somebody who is not at a computer and possibly asleep, and the fastest
 * way to make an alerting channel useless is to send anything else down it.
 *
 * Like TriggerAutomaticAiRun, this never throws. It is called from the ticket
 * observer, inside the transaction that creates the ticket, and nothing about
 * an alert is important enough to roll back the thing it is alerting about — a
 * critical ticket that exists and did not page anybody is far better than a
 * critical ticket that failed to be filed.
 *
 * Everything it does synchronously is one settings read and one insert per
 * number; the provider call happens in a worker.
 */
class AlertCriticalTicket
{
    public function __construct(private readonly SmsService $sms) {}

    public function handle(Ticket $ticket): void
    {
        try {
            $this->alert($ticket);
        } catch (Throwable $exception) {
            Log::error('A critical-ticket SMS alert could not be queued.', [
                'ticket_id' => $ticket->getKey(),
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    private function alert(Ticket $ticket): void
    {
        if ($ticket->priority !== TicketPriority::Critical) {
            return;
        }

        $board = Board::query()->find($ticket->board_id);

        if (! $board instanceof Board) {
            return;
        }

        $settings = BoardSmsSettings::forBoard($board);

        if (! $settings->isActive()) {
            return;
        }

        // The coarse guard against a burst. Checked before the per-number
        // claims so a script filing forty critical tickets sends one alert, not
        // forty deduplicated-by-ticket alerts.
        if ($this->sms->boardIsCoolingDown((int) $board->getKey())) {
            Log::info('A critical-ticket SMS was suppressed by the board cooldown.', [
                'board_id' => $board->getKey(),
                'ticket_id' => $ticket->getKey(),
            ]);

            return;
        }

        $ticket->setRelation('board', $board);

        $body = $this->body($ticket, $board);

        foreach ($settings->numbersToAlert() as $number) {
            $this->sms->queue(
                event: NotificationEvent::CriticalTicketRaised,
                recipient: $number,
                body: $body,
                ticketId: (int) $ticket->getKey(),
                boardId: (int) $board->getKey(),
                ticketKey: $ticket->key(),
            );
        }
    }

    /**
     * The message.
     *
     * Short, and carrying only what somebody needs to decide whether to get up:
     * that it is critical, which board, which ticket, and the title. No
     * description — a customer wrote it, it can be pages long, and it costs a
     * segment per 160 characters.
     *
     * A URL is deliberately absent. Link shorteners in SMS are a phishing
     * pattern, a full workspace URL eats two segments on its own, and anybody
     * who needs the ticket has the key.
     */
    private function body(Ticket $ticket, Board $board): string
    {
        return sprintf(
            '[%s] CRITICAL %s: %s',
            $board->ticket_prefix,
            $ticket->key(),
            Str::limit($ticket->title, 120),
        );
    }
}
