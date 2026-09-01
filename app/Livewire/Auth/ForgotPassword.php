<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.guest')]
#[Title('Forgot password')]
class ForgotPassword extends Component
{
    #[Validate('required|string|email|max:255')]
    public string $email = '';

    public function sendResetLink(): void
    {
        $this->validate();

        // Password::sendResetLink is deliberately non-committal about whether
        // the address exists, and so is the message shown below: enumerating
        // valid accounts would leak the customer list.
        Password::sendResetLink(['email' => mb_strtolower($this->email)]);

        session()->flash('status', 'If that email address is registered, a password reset link is on its way.');

        $this->reset('email');
    }

    public function render()
    {
        return view('livewire.auth.forgot-password');
    }
}
