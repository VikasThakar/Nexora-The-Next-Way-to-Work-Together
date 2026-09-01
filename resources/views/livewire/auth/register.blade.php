<div>
    <div class="mb-6">
        <h1 class="text-lg font-semibold text-slate-900">Create an account</h1>
        <p class="mt-1 text-sm text-slate-500">
            A new account has no board access until an administrator invites it to a board.
        </p>
    </div>

    <form wire:submit="register" class="space-y-5">
        <x-ui.field label="Name" for="name" :error="$errors->first('name')" required>
            <x-ui.input id="name" autocomplete="name" required autofocus wire:model="name" :invalid="$errors->has('name')" />
        </x-ui.field>

        <x-ui.field label="Email address" for="email" :error="$errors->first('email')" required>
            <x-ui.input id="email" type="email" autocomplete="username" required wire:model.blur="email" :invalid="$errors->has('email')" />
        </x-ui.field>

        <x-ui.field label="Password" for="password" :error="$errors->first('password')" hint="At least 12 characters, with letters and numbers." required>
            <x-ui.input id="password" type="password" autocomplete="new-password" required wire:model="password" :invalid="$errors->has('password')" />
        </x-ui.field>

        <x-ui.field label="Confirm password" for="password_confirmation" required>
            <x-ui.input id="password_confirmation" type="password" autocomplete="new-password" required wire:model="password_confirmation" />
        </x-ui.field>

        <x-ui.button type="submit" class="w-full">Create account</x-ui.button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-500">
        Already have an account?
        <a href="{{ route('login') }}" wire:navigate class="font-medium text-brand-600 hover:text-brand-700">Sign in</a>
    </p>
</div>
