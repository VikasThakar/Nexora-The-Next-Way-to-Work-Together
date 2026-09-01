<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

class UpdatePassword extends Component
{
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function save(): void
    {
        $validated = $this->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = auth()->user();

        $user->forceFill(['password' => $validated['password']])->save();

        // Changing the hash is what evicts other sessions: AuthenticateSession
        // compares the hash stored in each session against the current one.
        // logoutOtherDevices additionally refreshes this browser's remember-me
        // cookie so the person changing the password is not signed out too.
        // It expects the *current* password, which is now the new one.
        Auth::logoutOtherDevices($validated['password']);

        $this->reset('current_password', 'password', 'password_confirmation');

        session()->flash('status', 'Password updated.');
    }

    public function render()
    {
        return view('livewire.profile.update-password');
    }
}
