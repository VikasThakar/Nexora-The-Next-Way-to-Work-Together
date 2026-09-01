<div>
    <div class="mb-6">
        <h1 class="text-lg font-semibold text-slate-900">Forgot your password?</h1>
        <p class="mt-1 text-sm text-slate-500">
            Enter your email address and we will send you a link to choose a new one.
        </p>
    </div>

    <form wire:submit="sendResetLink" class="space-y-5">
        <x-ui.field label="Email address" for="email" :error="$errors->first('email')" required>
            <x-ui.input id="email" type="email" autocomplete="username" required autofocus wire:model="email" :invalid="$errors->has('email')" />
        </x-ui.field>

        <x-ui.button type="submit" class="w-full">Email password reset link</x-ui.button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-500">
        <a href="{{ route('login') }}" wire:navigate class="font-medium text-brand-600 hover:text-brand-700">Back to sign in</a>
    </p>
</div>
