<?php

declare(strict_types=1);

namespace App\Livewire\Users;

use App\Actions\Users\CreateUser;
use App\Actions\Users\UpdateUser;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Administrator screen for creating and editing accounts.
 *
 * Role changes go through UserPolicy::updateRole rather than the general
 * update ability, so the privilege escalation path is gated separately from
 * ordinary profile edits.
 */
#[Layout('layouts.app')]
class ManageUser extends Component
{
    public ?User $user = null;

    public string $name = '';

    public string $email = '';

    public string $role = UserRole::Team->value;

    public string $password = '';

    public string $password_confirmation = '';

    public bool $active = true;

    public function mount(?User $user = null): void
    {
        if ($user?->exists) {
            $this->authorize('update', $user);

            $this->user = $user;
            $this->name = $user->name;
            $this->email = $user->email;
            $this->role = $user->role->value;
            $this->active = $user->isActive();

            return;
        }

        $this->authorize('create', User::class);
    }

    public function updatedEmail(): void
    {
        $this->email = mb_strtolower(trim($this->email));
    }

    public function generatePassword(): void
    {
        $this->password = Str::password(16);
        $this->password_confirmation = $this->password;
    }

    public function save(CreateUser $createUser, UpdateUser $updateUser): void
    {
        $validated = $this->validate($this->rules());

        $role = UserRole::from($validated['role']);

        if ($this->user === null) {
            $createUser->handle([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ], $role);

            session()->flash('status', 'User created.');
        } else {
            $this->authorize('update', $this->user);

            $attributes = [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'] ?: null,
                'active' => $this->active,
            ];

            // Changing a role is a separate ability, and never applies to
            // yourself: an administrator must not be able to lock the
            // workspace out of its last administrator by accident.
            if ($role !== $this->user->role) {
                $this->authorize('updateRole', $this->user);
                $attributes['role'] = $role;
            }

            if (auth()->user()->is($this->user)) {
                $attributes['active'] = true;
            }

            $updateUser->handle($this->user, $attributes);

            session()->flash('status', 'User updated.');
        }

        $this->redirect(route('users.index'), navigate: true);
    }

    /** @return array<string, array<int, mixed>> */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255', 'lowercase',
                Rule::unique('users', 'email')->ignore($this->user?->getKey()),
            ],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => [
                $this->user === null ? 'required' : 'nullable',
                'string', 'confirmed', Password::defaults(),
            ],
        ];
    }

    public function render()
    {
        return view('livewire.users.manage-user', [
            'roles' => UserRole::options(),
            'isSelf' => $this->user !== null && auth()->user()->is($this->user),
        ])->title($this->user === null ? 'New user' : 'Edit '.$this->user->name);
    }
}
