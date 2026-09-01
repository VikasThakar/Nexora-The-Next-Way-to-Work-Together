@php
    $user = auth()->user();
@endphp

<div>
    <x-ui.page-header
        title="Profile"
        description="Your account details and password."
    />

    <div class="grid max-w-4xl gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Details">
                <form wire:submit="save" class="space-y-5">
                    <x-ui.field label="Name" for="name" :error="$errors->first('name')" required>
                        <x-ui.input id="name" wire:model="name" :invalid="$errors->has('name')" />
                    </x-ui.field>

                    <x-ui.field label="Email address" for="email" :error="$errors->first('email')" required>
                        <x-ui.input id="email" type="email" wire:model.blur="email" :invalid="$errors->has('email')" />
                    </x-ui.field>

                    <div class="flex items-center gap-3">
                        <x-ui.button type="submit">Save</x-ui.button>
                    </div>
                </form>
            </x-ui.card>

            <livewire:profile.update-password />
        </div>

        <div class="space-y-6">
            <x-ui.card title="Access">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Role</dt>
                        <dd class="text-slate-900">{{ $user->role->label() }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Internal content</dt>
                        <dd class="text-slate-900">{{ $user->canSeeInternalContent() ? 'Visible' : 'Hidden' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Member since</dt>
                        <dd class="text-slate-900">{{ $user->created_at->toFormattedDateString() }}</dd>
                    </div>
                </dl>

                <p class="mt-4 text-xs text-slate-500">
                    Your role and board access are managed by a workspace administrator.
                </p>
            </x-ui.card>
        </div>
    </div>
</div>
