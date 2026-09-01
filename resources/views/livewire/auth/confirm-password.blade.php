<div>
    <div class="mb-6">
        <h1 class="text-lg font-semibold text-slate-900">Confirm your password</h1>
        <p class="mt-1 text-sm text-slate-500">
            This is a secure area. Please confirm your password before continuing.
        </p>
    </div>

    <form wire:submit="confirm" class="space-y-5">
        <x-ui.field label="Password" for="password" :error="$errors->first('password')" required>
            <x-ui.input id="password" type="password" autocomplete="current-password" required autofocus wire:model="password" :invalid="$errors->has('password')" />
        </x-ui.field>

        <x-ui.button type="submit" class="w-full">Confirm</x-ui.button>
    </form>
</div>
