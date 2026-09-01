<x-ui.card title="Password" description="Changing your password signs you out of every other session.">
    <form wire:submit="save" class="space-y-5">
        <x-ui.field label="Current password" for="current_password" :error="$errors->first('current_password')" required>
            <x-ui.input
                id="current_password"
                type="password"
                autocomplete="current-password"
                wire:model="current_password"
                :invalid="$errors->has('current_password')"
            />
        </x-ui.field>

        <x-ui.field
            label="New password"
            for="new_password"
            :error="$errors->first('password')"
            hint="At least 12 characters, with letters and numbers."
            required
        >
            <x-ui.input
                id="new_password"
                type="password"
                autocomplete="new-password"
                wire:model="password"
                :invalid="$errors->has('password')"
            />
        </x-ui.field>

        <x-ui.field label="Confirm new password" for="new_password_confirmation" required>
            <x-ui.input
                id="new_password_confirmation"
                type="password"
                autocomplete="new-password"
                wire:model="password_confirmation"
            />
        </x-ui.field>

        <x-ui.button type="submit">Update password</x-ui.button>
    </form>
</x-ui.card>
