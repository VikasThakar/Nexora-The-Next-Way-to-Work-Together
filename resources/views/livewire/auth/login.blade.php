<div>
    <div class="mb-6">
        <h1 class="text-lg font-semibold text-slate-900">Sign in</h1>
        <p class="mt-1 text-sm text-slate-500">Use the account your workspace administrator created for you.</p>
    </div>

    <form wire:submit="login" class="space-y-5">
        <x-ui.field label="Email address" for="email" :error="$errors->first('email')" required>
            <x-ui.input
                id="email"
                type="email"
                autocomplete="username"
                required
                autofocus
                wire:model="email"
                :invalid="$errors->has('email')"
            />
        </x-ui.field>

        <x-ui.field label="Password" for="password" :error="$errors->first('password')" required>
            <x-ui.input
                id="password"
                type="password"
                autocomplete="current-password"
                required
                wire:model="password"
                :invalid="$errors->has('password')"
            />
        </x-ui.field>

        <div class="flex items-center justify-between">
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input
                    type="checkbox"
                    wire:model="remember"
                    class="size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                >
                Remember me
            </label>

            <a href="{{ route('password.request') }}" wire:navigate class="text-sm font-medium text-brand-600 hover:text-brand-700">
                Forgot password?
            </a>
        </div>

        <x-ui.button type="submit" class="w-full" wire:loading.attr="disabled" wire:target="login">
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in…</span>
        </x-ui.button>
    </form>

    @if (config('workspace.registration.public'))
        <p class="mt-6 text-center text-sm text-slate-500">
            Need an account?
            <a href="{{ route('register') }}" wire:navigate class="font-medium text-brand-600 hover:text-brand-700">Register</a>
        </p>
    @endif
</div>
