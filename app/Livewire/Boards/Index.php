<?php

declare(strict_types=1);

namespace App\Livewire\Boards;

use App\Models\Board;
use App\Services\BoardAccess;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Boards')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: false)]
    public bool $showArchived = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Board::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedShowArchived(): void
    {
        $this->resetPage();
    }

    public function render(BoardAccess $access)
    {
        $user = auth()->user();

        $boards = $access->query($user)
            ->search($this->search)
            ->unless($this->showArchived, fn ($query) => $query->notArchived())
            ->withCount('members')
            ->orderBy('name')
            ->paginate(12);

        return view('livewire.boards.index', [
            'boards' => $boards,
            'canCreateBoards' => $user->can('create', Board::class),
        ]);
    }
}
