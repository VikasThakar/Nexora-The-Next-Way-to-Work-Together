<?php

declare(strict_types=1);

namespace App\Livewire\Users;

use App\Actions\Users\UpdateUser;
use App\Enums\UserRole;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Users')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $role = '';

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRole(): void
    {
        $this->resetPage();
    }

    public function toggleActive(int $userId, UpdateUser $updateUser): void
    {
        $user = User::query()->findOrFail($userId);

        $this->authorize('update', $user);

        if (auth()->user()->is($user)) {
            session()->flash('error', 'You cannot deactivate your own account.');

            return;
        }

        $updateUser->handle($user, ['active' => ! $user->isActive()]);

        session()->flash('status', $user->isActive() ? 'User reactivated.' : 'User deactivated.');
    }

    public function render()
    {
        $role = UserRole::tryFrom($this->role);

        $users = User::query()
            ->search($this->search)
            ->when($role, fn ($query) => $query->role($role))
            ->withCount('boardMemberships')
            ->orderBy('name')
            ->paginate(15);

        return view('livewire.users.index', [
            'users' => $users,
            'roles' => UserRole::options(),
        ]);
    }
}
