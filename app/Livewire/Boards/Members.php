<?php

declare(strict_types=1);

namespace App\Livewire\Boards;

use App\Actions\Boards\AddBoardMember;
use App\Actions\Boards\RemoveBoardMember;
use App\Models\Board;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Board membership editor, mounted inside the board page for administrators.
 *
 * Every entry point re-authorizes: the component is only rendered when the
 * viewer may manage the board, but mount() and both actions check the policy
 * again, because a Livewire action is a fresh HTTP request that must not trust
 * what the previous render decided.
 */
class Members extends Component
{
    public Board $board;

    public string $userId = '';

    public function mount(Board $board): void
    {
        $this->authorize('manageMembers', $board);

        $this->board = $board;
    }

    public function addMember(AddBoardMember $addMember): void
    {
        $this->authorize('manageMembers', $this->board);

        $validated = $this->validate([
            'userId' => ['required', 'integer', Rule::exists('users', 'id')->whereNull('deactivated_at')],
        ]);

        $addMember->handle($this->board, (int) $validated['userId'], auth()->user());

        $this->reset('userId');

        $this->dispatch('board-members-updated');

        session()->flash('status', 'Member added.');
    }

    public function removeMember(int $userId, RemoveBoardMember $removeMember): void
    {
        $this->authorize('manageMembers', $this->board);

        $removeMember->handle($this->board, $userId, auth()->user());

        $this->dispatch('board-members-updated');

        session()->flash('status', 'Member removed.');
    }

    /** @return Collection<int, User> */
    public function members(): Collection
    {
        return $this->board->members()->orderBy('name')->get();
    }

    /** @return Collection<int, User> */
    public function candidates(): Collection
    {
        return User::query()
            ->active()
            ->whereDoesntHave('boardMemberships', fn ($query) => $query->where('board_id', $this->board->getKey()))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);
    }

    public function render()
    {
        return view('livewire.boards.members', [
            'members' => $this->members(),
            'candidates' => $this->candidates(),
        ]);
    }
}
