<?php

declare(strict_types=1);

namespace App\Livewire\Boards;

use App\Actions\Boards\CreateBoard;
use App\Actions\Boards\DeleteBoard;
use App\Actions\Boards\UpdateBoard;
use App\Models\Board;
use App\Models\User;
use App\Support\BoardIdentifiers;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Create or edit a board. Administrators only, enforced by BoardPolicy.
 */
#[Layout('layouts.app')]
class ManageBoard extends Component
{
    public ?Board $board = null;

    public string $name = '';

    public string $slug = '';

    public string $ticket_prefix = '';

    public string $description = '';

    public bool $archived = false;

    /** @var array<int, int> */
    public array $memberIds = [];

    public bool $confirmingDelete = false;

    public string $deleteConfirmation = '';

    public function mount(?Board $board = null): void
    {
        if ($board?->exists) {
            $this->authorize('update', $board);

            $this->board = $board;
            $this->name = $board->name;
            $this->slug = $board->slug;
            $this->ticket_prefix = $board->ticket_prefix;
            $this->description = (string) $board->description;
            $this->archived = $board->isArchived();
            $this->memberIds = $board->members()->pluck('users.id')->all();

            return;
        }

        $this->authorize('create', Board::class);
    }

    /**
     * Suggest a slug and prefix while the name is being typed, but never
     * overwrite a value the administrator has edited by hand.
     */
    public function updatedName(BoardIdentifiers $identifiers): void
    {
        if ($this->board !== null) {
            return;
        }

        if (trim($this->name) === '') {
            return;
        }

        $this->slug = $identifiers->slug($this->name);
        $this->ticket_prefix = $identifiers->ticketPrefix($this->name);
    }

    /**
     * Board deletion is guarded by typing the board name.
     *
     * It destroys every ticket, its history and its attachments, so a stray
     * click must not be enough — the confirmation has to be deliberate.
     */
    public function confirmDelete(): void
    {
        $this->authorize('delete', $this->board);

        $this->confirmingDelete = true;
        $this->deleteConfirmation = '';
    }

    public function cancelDelete(): void
    {
        $this->confirmingDelete = false;
        $this->deleteConfirmation = '';
        $this->resetValidation('deleteConfirmation');
    }

    public function destroyBoard(DeleteBoard $deleteBoard)
    {
        $this->authorize('delete', $this->board);

        if (trim($this->deleteConfirmation) !== $this->board->name) {
            $this->addError('deleteConfirmation', 'Type the board name exactly to confirm.');

            return null;
        }

        $name = $this->board->name;

        $deleteBoard->handle($this->board);

        session()->flash('status', $name.' and everything on it was deleted.');

        return $this->redirect(route('boards.index'), navigate: true);
    }

    public function updatedTicketPrefix(): void
    {
        $this->ticket_prefix = mb_strtoupper(trim($this->ticket_prefix));
    }

    public function updatedSlug(): void
    {
        $this->slug = mb_strtolower(trim($this->slug));
    }

    public function save(CreateBoard $createBoard, UpdateBoard $updateBoard): void
    {
        $validated = $this->validate($this->rules());

        if ($this->board === null) {
            $board = $createBoard->handle(
                [
                    'name' => $validated['name'],
                    'slug' => $validated['slug'],
                    'ticket_prefix' => $validated['ticket_prefix'],
                    'description' => $validated['description'] ?: null,
                ],
                auth()->user(),
                // Checkbox values arrive from the browser as strings. The
                // `integer` rule accepts "3" but does not convert it, so the
                // cast has to happen here or a string reaches an action that
                // asks for an int — which, under strict_types, is a TypeError
                // rather than a silent coercion.
                array_map('intval', $validated['memberIds'] ?? []),
            );

            session()->flash('status', 'Board created.');
        } else {
            $this->authorize('update', $this->board);

            $board = $updateBoard->handle($this->board, [
                'name' => $validated['name'],
                'slug' => $validated['slug'],
                'ticket_prefix' => $validated['ticket_prefix'],
                'description' => $validated['description'] ?: null,
                'archived' => $this->archived,
            ]);

            session()->flash('status', 'Board updated.');
        }

        $this->redirect(route('boards.show', $board), navigate: true);
    }

    /** @return array<string, array<int, mixed>> */
    protected function rules(): array
    {
        $ignore = $this->board?->getKey();

        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'required', 'string', 'max:80', 'alpha_dash:ascii', 'lowercase',
                Rule::unique('boards', 'slug')->ignore($ignore),
            ],
            'ticket_prefix' => [
                'required', 'string',
                'min:'.config('workspace.ticket_prefix.min_length'),
                'max:'.config('workspace.ticket_prefix.max_length'),
                'regex:'.config('workspace.ticket_prefix.pattern'),
                Rule::unique('boards', 'ticket_prefix')->ignore($ignore),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'memberIds' => ['array'],
            // Deactivated accounts are excluded here as well as in
            // App\Livewire\Boards\Members, so a board cannot be created with a
            // member who could not be added to it a moment later.
            'memberIds.*' => ['integer', Rule::exists('users', 'id')->whereNull('deactivated_at')],
        ];
    }

    /** @return Collection<int, User> */
    public function assignableUsers(): Collection
    {
        return User::query()->active()->orderBy('name')->get(['id', 'name', 'email', 'role']);
    }

    public function render()
    {
        return view('livewire.boards.manage-board', [
            'assignableUsers' => $this->board === null ? $this->assignableUsers() : collect(),
            'canDelete' => $this->board !== null && auth()->user()->can('delete', $this->board),
            'ticketCount' => $this->board?->tickets()->count() ?? 0,
        ])->title($this->board === null ? 'New board' : 'Edit '.$this->board->name);
    }
}
