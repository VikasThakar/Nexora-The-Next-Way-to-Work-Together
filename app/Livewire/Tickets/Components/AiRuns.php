<?php

declare(strict_types=1);

namespace App\Livewire\Tickets\Components;

use App\Actions\AI\CancelAiRun;
use App\Actions\AI\CreateAiRun;
use App\Actions\AI\Exceptions\AiRunRefused;
use App\Enums\AiRunMode;
use App\Enums\AiRunTrigger;
use App\Livewire\Concerns\ListensForBoardUpdates;
use App\Models\AiRun;
use App\Models\Ticket;
use App\Services\AI\AiRunCap;
use App\Services\AI\AiRunReader;
use App\Support\BoardAiSettings;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * The AI panel on a ticket: run history, and the button that starts one.
 *
 * Staff only, and that is decided on the server on every render, not once on
 * mount. Livewire rehydrates a component's public properties from the browser on
 * every subsequent request, so a component that authorized only in mount() would
 * be authorizing a decision the client could replay. Every action here
 * re-authorizes its own ability.
 *
 * A customer never reaches this component at all — the ticket page does not
 * render it for them — but if one did, mount() denies as 404 and the reader
 * returns nothing. The hidden markup is a usability decision; the two checks
 * behind it are the security one.
 *
 * The panel polls only while something is in flight. A queued run has no realtime
 * event of its own (the board channel carries ticket and comment changes, and the
 * completed run announces itself through the note it posts), so a short poll is
 * how the status reaches the screen — and it stops as soon as nothing is active,
 * rather than every open ticket page polling for ever.
 */
class AiRuns extends Component
{
    use ListensForBoardUpdates;

    public Ticket $ticket;

    /** The mode chosen for a manual run. */
    public string $mode = '';

    public bool $confirmingApply = false;

    public function mount(Ticket $ticket): void
    {
        // Denied as 404 for a customer: the existence of the feature on their
        // ticket is itself internal.
        $this->authorize('viewAny', [AiRun::class, $ticket]);

        $this->ticket = $ticket;
        $this->mode = AiRunMode::Suggest->value;
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return $this->boardUpdateListeners(isset($this->ticket) ? (int) $this->ticket->board_id : null);
    }

    // -----------------------------------------------------------------
    // Starting a run
    // -----------------------------------------------------------------

    /**
     * Apply mode gets a confirmation step of its own.
     *
     * Not because the authorization is different, but because the consequence is:
     * suggest mode writes a note, apply mode opens a pull request in the team's
     * repository. A button that does the second should not look exactly like a
     * button that does the first.
     */
    public function startApply(): void
    {
        $this->authorize('create', [AiRun::class, $this->ticket, AiRunMode::Apply]);

        $this->confirmingApply = true;
    }

    public function cancelApply(): void
    {
        $this->confirmingApply = false;
    }

    public function run(CreateAiRun $createAiRun): void
    {
        $validated = $this->validate([
            'mode' => ['required', Rule::in(AiRunMode::runnableValues())],
        ], attributes: ['mode' => 'mode']);

        $mode = AiRunMode::from($validated['mode']);

        // Re-authorized here, per request, with the specific mode. The value
        // arrives from the browser, so the earlier render's decision is worth
        // nothing.
        $this->authorize('create', [AiRun::class, $this->ticket, $mode]);

        if ($mode->writesCode() && ! $this->confirmingApply) {
            $this->confirmingApply = true;

            return;
        }

        // The same refusals the panel renders, enforced on the server. The
        // disabled button is a courtesy; a crafted Livewire call reaches this
        // method directly, and "a run is already in flight for this ticket" is
        // a rule CreateAiRun does not own — it happily creates a second run,
        // because a second run is a legitimate thing for other callers to want.
        $refusal = $createAiRun->refusalFor($this->ticket, $mode, auth()->user());

        if ($refusal !== null) {
            session()->flash('error', $refusal->getMessage());
            $this->confirmingApply = false;

            return;
        }

        try {
            $createAiRun->handle($this->ticket, $mode, AiRunTrigger::Manual, auth()->user());
        } catch (AiRunRefused $refused) {
            // A refusal is not an error: the cap doing its job, or a deployment
            // that has no credential. Shown as prose rather than a stack trace.
            session()->flash('error', $refused->getMessage());
            $this->confirmingApply = false;

            return;
        }

        $this->confirmingApply = false;

        session()->flash('status', $mode === AiRunMode::Apply
            ? 'Apply run queued. It will open a draft pull request for review.'
            : 'Analysis queued. The result will be posted as an internal note.');
    }

    public function cancel(int $runId, CancelAiRun $cancelAiRun, AiRunReader $runs): void
    {
        $this->ticket->loadMissing('board');

        // Resolved within this board, through the scoped reader, so a swapped id
        // cannot reach a run on another board.
        $run = $runs->findOrFail($this->ticket->board, $runId, auth()->user());

        $this->authorize('cancel', $run);

        $cancelled = $cancelAiRun->handle($run, auth()->user());

        session()->flash(
            $cancelled ? 'status' : 'error',
            $cancelled
                ? 'Run cancelled.'
                : 'That run had already started, so it could not be cancelled.'
        );
    }

    // -----------------------------------------------------------------

    public function render(AiRunReader $runs, CreateAiRun $createAiRun, AiRunCap $cap)
    {
        // Re-authorized on every render: this is not the same request that ran
        // mount(), and the user's role or membership may have changed since.
        $this->authorize('viewAny', [AiRun::class, $this->ticket]);

        $this->ticket->loadMissing('board');

        $user = auth()->user();
        $history = $runs->forTicket($this->ticket, $user);
        $hasActive = $history->contains(fn (AiRun $run): bool => $run->status->isActive());

        $selected = AiRunMode::tryFrom($this->mode) ?? AiRunMode::Suggest;

        return view('livewire.tickets.components.ai-runs', [
            'runs' => $history,
            'hasActive' => $hasActive,
            'modes' => AiRunMode::runnable(),
            'refusal' => $createAiRun->refusalFor($this->ticket, $selected, $user),
            'providerConfigured' => BoardAiSettings::providerConfigured(),
            'settings' => $this->ticket->board->aiSettings(),
            'manualUsedToday' => $cap->usedToday($this->ticket->board, AiRunTrigger::Manual),
            'manualLimit' => $cap->manualLimit(),
            'autoUsedToday' => $cap->usedToday($this->ticket->board, AiRunTrigger::Automatic),
            'autoLimit' => $cap->automaticLimit($this->ticket->board),
            'canConfigure' => $user->can('manageAiSettings', $this->ticket->board),
        ]);
    }
}
