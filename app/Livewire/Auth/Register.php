<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Actions\Users\CreateUser;
use App\Enums\UserRole;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Self-service registration.
 *
 * Disabled by default: this is an internal workspace, so accounts are created
 * by an administrator. When ALLOW_PUBLIC_REGISTRATION is on, a self-registered
 * account gets the least privileged role and — crucially — no board
 * memberships, so it can see nothing until an administrator invites it.
 */
#[Layout('layouts.guest')]
#[Title('Create an account')]
class Register extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        abort_unless(config('workspace.registration.public'), 404);
    }

    public function updatedEmail(): void
    {
        $this->email = mb_strtolower(trim($this->email));
    }

    public function register(CreateUser $createUser): void
    {
        abort_unless(config('workspace.registration.public'), 404);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'lowercase', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $role = UserRole::from(config('workspace.registration.default_role'));

        $user = $createUser->handle($validated, $role);

        event(new Registered($user));

        Auth::login($user);
        Session::regenerate();

        $this->redirect(route('dashboard'), navigate: false);
    }

    public function render()
    {
        return view('livewire.auth.register');
    }
}
