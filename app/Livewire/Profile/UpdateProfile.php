<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Actions\Users\UpdateUser;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Self-service profile editing.
 *
 * Deliberately cannot touch `role` or activation: those belong to the
 * administrator screens and to UserPolicy::updateRole.
 */
#[Layout('layouts.app')]
#[Title('Profile')]
class UpdateProfile extends Component
{
    public string $name = '';

    public string $email = '';

    public function mount(): void
    {
        $user = auth()->user();

        $this->name = $user->name;
        $this->email = $user->email;
    }

    public function updatedEmail(): void
    {
        $this->email = mb_strtolower(trim($this->email));
    }

    public function save(UpdateUser $updateUser): void
    {
        $user = auth()->user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255', 'lowercase',
                Rule::unique('users', 'email')->ignore($user->getKey()),
            ],
        ]);

        $updateUser->handle($user, $validated);

        session()->flash('status', 'Profile updated.');
    }

    public function render()
    {
        return view('livewire.profile.update-profile');
    }
}
