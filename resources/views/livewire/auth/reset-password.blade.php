<div>
    <div class="mb-6">
        <h1 class="text-lg font-semibold text-slate-900">Choose a new password</h1>
        <p class="mt-1 text-sm text-slate-500">This link can only be used once.</p>
    </div>

    <form wire:submit="resetPassword" class="space-y-5">
        <x-ui.field label="Email address" for="email" :error="$errors->first('email')" required>
            <x-ui.input id="email" type="email" autocomplete="username" required wire:model="email" :invalid="$errors->has('email')" />
        </x-ui.field>

        <x-ui.field label="New password" for="password" :error="$errors->first('password')" hint="At least 12 characters, with letters and numbers." required>
            <x-ui.input id="password" type="password" autocomplete="new-password" required autofocus wire:model="password" :invalid="$errors->has('password')" />
        </x-ui.field>

        <x-ui.field label="Confirm new password" for="password_confirmation" required>
            <x-ui.input id="password_confirmation" type="password" autocomplete="new-password" required wire:model="password_confirmation" />
        </x-ui.field>

        <x-ui.button type="submit" class="w-full">Reset password</x-ui.button>
    </form>
</div>
