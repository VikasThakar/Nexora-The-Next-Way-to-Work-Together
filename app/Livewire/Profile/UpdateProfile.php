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

        // Redirect rather than re-render: the banner and the name in the
        // sidebar and top bar are part of the layout, which a plain re-render
        // never redraws — and Livewire discards a flash that no redirect
        // carries, so without this the save looked like it did nothing.
        session()->flash('status', 'Profile updated.');

        $this->redirectRoute('profile.edit', navigate: true);
    }

    public function render()
    {
        return view('livewire.profile.update-profile');
    }
}
