<x-ui.card title="Picture">
    <div class="flex flex-wrap items-center gap-5">
        {{--
            Shown at `lg`, the one size the component has always declared and
            nothing used until now. The label makes it content rather than
            decoration: on this screen the picture is the subject, not an
            ornament beside a name that is already on the page.
        --}}
        <x-ui.avatar :user="$user" size="lg" :label="$user->hasAvatar() ? 'Your profile picture' : 'Your initials'" />

        <div class="min-w-0 flex-1 space-y-3">
            <div class="flex flex-wrap items-center gap-2">
                {{--
                    A real <input type="file"> behind a styled label, rather
                    than a button that clicks it from JavaScript. A label is
                    already keyboard reachable and already announces what it
                    controls, so this needs no script at all.
                --}}
                <label
                    for="profile-photo"
                    class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-slate-300 bg-surface px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50 focus-within:ring-2 focus-within:ring-brand-500/40 dark:hover:bg-slate-800"
                >
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
                    </svg>

                    {{ $user->hasAvatar() ? 'Change picture' : 'Upload a picture' }}

                    <input
                        id="profile-photo"
                        type="file"
                        wire:model="photo"
                        accept="{{ collect(config('attachments.avatars.allowed_mimes'))->map(fn ($e) => '.'.$e)->implode(',') }}"
                        class="sr-only"
                    >
                </label>

                @if ($user->hasAvatar())
                    <x-ui.button
                        type="button"
                        variant="ghost"
                        size="sm"
                        wire:click="remove"
                        :confirm="[
                            'title' => 'Remove your picture?',
                            'body' => 'Your initials will be shown instead. You can upload another at any time.',
                            'confirmText' => 'Remove',
                        ]"
                    >
                        Remove
                    </x-ui.button>
                @endif

                <span wire:loading wire:target="photo" class="text-xs text-slate-500">Uploading&hellip;</span>
            </div>

            @error('photo')
                <p class="text-xs text-rose-600" role="alert">{{ $message }}</p>
            @enderror

            <p class="text-xs text-slate-500">
                JPEG, PNG or WebP, up to {{ $maxSizeMb }} MB. Cropped to a square and scaled down;
                location data in the file is removed.
            </p>
        </div>
    </div>
</x-ui.card>
