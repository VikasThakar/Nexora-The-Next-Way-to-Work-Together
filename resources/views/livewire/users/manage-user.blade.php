<div>
    <x-ui.page-header
        :title="$user ? 'Edit user' : 'New user'"
        :description="$user
            ? 'Change this account. Board access is granted from each board page.'
            : 'Create an account. It will have no board access until you add it to a board.'"
    >
        <x-slot:breadcrumb>
            <a href="{{ route('users.index') }}" wire:navigate class="hover:text-slate-700">Users</a>
        </x-slot:breadcrumb>
    </x-ui.page-header>

    <form wire:submit="save" class="grid max-w-4xl gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Account">
                <div class="space-y-5">
                    <x-ui.field label="Name" for="name" :error="$errors->first('name')" required>
                        <x-ui.input id="name" wire:model="name" autofocus :invalid="$errors->has('name')" />
                    </x-ui.field>

                    <x-ui.field label="Email address" for="email" :error="$errors->first('email')" required>
                        <x-ui.input id="email" type="email" wire:model.blur="email" :invalid="$errors->has('email')" />
                    </x-ui.field>

                    <x-ui.field
                        label="Role"
                        for="role"
                        :error="$errors->first('role')"
                        :hint="$isSelf ? 'You cannot change your own role.' : 'Customers never see internal content, on any board.'"
                        required
                    >
                        <x-ui.select id="role" wire:model="role" :disabled="$isSelf" :invalid="$errors->has('role')">
                            @foreach ($roles as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                </div>
            </x-ui.card>

            <x-ui.card
                title="Password"
                :description="$user ? 'Leave blank to keep the current password.' : 'The user can change this after signing in.'"
            >
                <div class="space-y-5">
                    <x-ui.field
                        label="Password"
                        for="password"
                        :error="$errors->first('password')"
                        hint="At least 12 characters, with letters and numbers."
                        :required="! $user"
                    >
                        <div class="flex gap-2">
                            <x-ui.input
                                id="password"
                                type="text"
                                autocomplete="new-password"
                                class="font-mono"
                                wire:model="password"
                                :invalid="$errors->has('password')"
                            />
                            <x-ui.button type="button" variant="secondary" wire:click="generatePassword">Generate</x-ui.button>
                        </div>
                    </x-ui.field>

                    <x-ui.field label="Confirm password" for="password_confirmation" :required="! $user">
                        <x-ui.input
                            id="password_confirmation"
                            type="text"
                            autocomplete="new-password"
                            class="font-mono"
                            wire:model="password_confirmation"
                        />
                    </x-ui.field>
                </div>
            </x-ui.card>
        </div>

        <div class="space-y-6">
            @if ($user)
                <x-ui.card title="Status">
                    <label class="flex items-start gap-3">
                        <input
                            type="checkbox"
                            wire:model="active"
                            @disabled($isSelf)
                            class="mt-0.5 size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                        >
                        <span class="text-sm">
                            <span class="font-medium text-slate-900">Account is active</span>
                            <span class="mt-0.5 block text-slate-500">
                                Deactivating ends every session for this account immediately.
                            </span>
                        </span>
                    </label>
                </x-ui.card>
            @endif

            <div class="flex items-center gap-3">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    {{ $user ? 'Save changes' : 'Create user' }}
                </x-ui.button>

                <x-ui.button variant="secondary" :href="route('users.index')">Cancel</x-ui.button>
            </div>
        </div>
    </form>
</div>
