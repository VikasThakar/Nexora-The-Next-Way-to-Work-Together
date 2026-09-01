<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Board;
use App\Services\BoardAccess;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Role-aware landing page.
 *
 * There is no branching on role here for the board list: BoardAccess::query()
 * already returns every board for an administrator and only explicit
 * memberships for everyone else. Role only affects the copy and the actions
 * offered, never the data.
 */
#[Layout('layouts.app')]
#[Title('Dashboard')]
class Index extends Component
{
    /** @return Collection<int, Board> */
    public function boards(BoardAccess $access): Collection
    {
        return $access->query(auth()->user())
            ->notArchived()
            ->withCount('members')
            ->orderBy('name')
            ->get();
    }

    public function render(BoardAccess $access)
    {
        $user = auth()->user();

        return view('livewire.dashboard.index', [
            'boards' => $this->boards($access),
            'canCreateBoards' => $user->can('create', Board::class),
        ]);
    }
}
