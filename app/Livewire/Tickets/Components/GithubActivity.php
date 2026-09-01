<?php

declare(strict_types=1);

namespace App\Livewire\Tickets\Components;

use App\Livewire\Concerns\ListensForBoardUpdates;
use App\Models\Ticket;
use App\Services\GitHub\GithubLinkReader;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Branches, commits and pull requests that mention this ticket.
 *
 * Staff only, checked on mount *and* on every render — Livewire rehydrates a
 * component from the browser on each subsequent request, so authorizing once at
 * mount authorizes a decision the client can replay.
 *
 * The panel is read-only by design. There is no button here that closes a pull
 * request, merges one, or pushes anything: this application's relationship with
 * GitHub is that GitHub tells it things. The one place the application writes to
 * GitHub is apply mode, which opens a draft pull request and stops.
 *
 * There is nothing to poll. Links arrive by webhook, and a webhook is not
 * something the browser can anticipate, so the panel renders what exists when
 * the page is drawn and refreshes with the rest of the board when something
 * else on it changes.
 */
class GithubActivity extends Component
{
    use ListensForBoardUpdates;

    public Ticket $ticket;

    public function mount(Ticket $ticket): void
    {
        $this->ticket = $ticket;

        $this->authorizeAccess();
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return $this->boardUpdateListeners(isset($this->ticket) ? (int) $this->ticket->board_id : null);
    }

    /**
     * Two questions, both of which must pass: may this user open the ticket,
     * and may they observe internal content at all.
     *
     * 404 rather than 403 for the same reason everywhere else does it — a
     * customer must not learn from the response that their ticket has an
     * engineering trail attached to it.
     */
    private function authorizeAccess(): void
    {
        $this->authorize('view', $this->ticket);

        if (! Gate::allows('view-internal-content')) {
            throw new NotFoundHttpException;
        }
    }

    public function render(GithubLinkReader $reader)
    {
        $this->authorizeAccess();

        $links = $reader->forTicket($this->ticket, auth()->user());

        return view('livewire.tickets.components.github-activity', [
            'links' => $links,
            'configured' => filled(config('github.webhook.secret')),
        ]);
    }
}
