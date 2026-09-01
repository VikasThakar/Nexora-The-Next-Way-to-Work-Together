<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Re-authentication gate in front of privileged screens.
 *
 * Guards the administrator areas (user management, board membership) so that a
 * hijacked but idle session cannot be used to escalate privileges without the
 * password.
 */
#[Layout('layouts.guest')]
#[Title('Confirm password')]
class ConfirmPassword extends Component
{
    public string $password = '';

    public function confirm(): void
    {
        $this->validate([
            'password' => ['required', 'string'],
        ]);

        $confirmed = Auth::guard('web')->validate([
            'email' => Auth::user()->email,
            'password' => $this->password,
        ]);

        if (! $confirmed) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        session()->put('auth.password_confirmed_at', time());

        $this->reset('password');

        $this->redirectIntended(route('dashboard'), navigate: false);
    }

    public function render()
    {
        return view('livewire.auth.confirm-password');
    }
}
