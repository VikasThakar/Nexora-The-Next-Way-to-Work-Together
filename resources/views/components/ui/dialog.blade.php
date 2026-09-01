@php
    /**
     * The application's one modal. Rendered once per layout, never per screen.
     *
     * Everything it displays comes from the Alpine `dialog` store
     * (resources/js/dialog.js), so a page that wants a confirmation adds an
     * `x-confirm` attribute to a button rather than any markup of its own.
     * There is exactly one of these in the DOM regardless of how many places
     * can trigger it.
     *
     * The styling is not new. It reuses the existing card shell
     * (rounded-xl / border-slate-200 / bg-white), the existing icon treatment
     * from x-ui.empty-state (a size-12 tinted circle holding a 1.5-stroke
     * heroicon), the existing badge colour families, and x-ui.button itself for
     * the actions — so the button in a dialog is literally the same component
     * as the button that opened it, not a copy of its classes.
     *
     * The one deliberate departure is elevation: cards sit on the page with
     * shadow-xs, and a modal floats above it, so this uses shadow-xl — the same
     * shadow the board already uses for a card being dragged.
     */
    $tones = [
        'success' => 'bg-emerald-50 text-emerald-600',
        'error' => 'bg-rose-50 text-rose-600',
        'warning' => 'bg-amber-50 text-amber-600',
        'info' => 'bg-brand-50 text-brand-600',
        /*
         * A confirmation borrows the tone of what it is confirming, and there
         * are three because the product has three kinds of consequential
         * action, not two:
         *
         *   danger   destructive and irreversible — deleting a comment, a file,
         *            a label. Red icon, red confirm button.
         *   warning  reversible, but it changes who can see something. Making a
         *            ticket customer-visible is the clearest case: nothing is
         *            destroyed, but it cannot be un-seen. Amber icon, ordinary
         *            confirm button, because a red button here would cry wolf
         *            and make the genuinely destructive ones read as routine.
         *   brand    a plain "are you sure" with no particular weight.
         */
        'confirm-danger' => 'bg-rose-50 text-rose-600',
        'confirm-warning' => 'bg-amber-50 text-amber-600',
        'confirm-brand' => 'bg-brand-50 text-brand-600',
    ];
@endphp

<div
    x-data="{
        tones: @js($tones),

        /** Which tinted circle this dialog gets. */
        get tone() {
            const config = $store.dialog.config

            return config.type === 'confirm'
                ? (this.tones['confirm-' + config.tone] ?? this.tones['confirm-brand'])
                : (this.tones[config.type] ?? this.tones.info)
        },
    }"
    x-cloak
    x-show="$store.dialog.open"
    @keydown.escape.window="$store.dialog.dismissed()"
    class="fixed inset-0 z-50 overflow-y-auto"
    role="presentation"
>
    {{-- Backdrop. Same treatment as the mobile navigation overlay. --}}
    <div
        x-show="$store.dialog.open"
        x-transition:enter="ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 bg-slate-900/50"
        aria-hidden="true"
    ></div>

    <div class="flex min-h-full items-end justify-center p-4 sm:items-center">
        <div
            x-show="$store.dialog.open"
            x-transition:enter="ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-3 scale-95 sm:translate-y-0"
            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
            x-transition:leave="ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-y-0 scale-100"
            x-transition:leave-end="opacity-0 translate-y-2 scale-95"
            {{--
                A click on the backdrop dismisses; a click inside must not. The
                self modifier is what keeps a drag that ends outside the panel
                from being read as a dismissal.
            --}}
            @click.outside="$store.dialog.dismissed()"
            {{--
                Focus is trapped while open and returned to the trigger on close
                (the store handles the return, so it survives the element being
                re-rendered by Livewire underneath).
            --}}
            x-trap.noscroll.inert="$store.dialog.open"
            role="alertdialog"
            aria-modal="true"
            :aria-labelledby="$store.dialog.config.title ? 'dialog-title' : null"
            :aria-describedby="$store.dialog.config.body ? 'dialog-body' : null"
            class="relative w-full max-w-md overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl"
        >
            <div class="px-5 pt-5 pb-4 sm:flex sm:items-start sm:gap-4">
                {{-- Icon. One circle, swapped by type; same shape as x-ui.empty-state. --}}
                <div
                    class="mx-auto flex size-12 shrink-0 items-center justify-center rounded-full sm:mx-0 sm:size-10"
                    :class="tone"
                    aria-hidden="true"
                >
                    <svg class="size-6 sm:size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        {{-- check-circle --}}
                        <path x-show="$store.dialog.config.type === 'success'" stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        {{-- x-circle --}}
                        <path x-show="$store.dialog.config.type === 'error'" stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        {{-- exclamation-triangle: warnings, and any confirmation with weight --}}
                        <path x-show="$store.dialog.config.type === 'warning' || ($store.dialog.config.type === 'confirm' && ['danger', 'warning'].includes($store.dialog.config.tone))" stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                        {{-- information-circle --}}
                        <path x-show="$store.dialog.config.type === 'info'" stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                        {{-- question-mark-circle, for a confirmation carrying no particular weight --}}
                        <path x-show="$store.dialog.config.type === 'confirm' && !['danger', 'warning'].includes($store.dialog.config.tone)" stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" />
                    </svg>
                </div>

                <div class="mt-3 text-center sm:mt-0 sm:text-left">
                    <h2 id="dialog-title" class="text-base font-semibold text-slate-900" x-text="$store.dialog.config.title"></h2>

                    <p
                        id="dialog-body"
                        x-show="$store.dialog.config.body"
                        class="mt-1.5 text-sm text-slate-600"
                        x-text="$store.dialog.config.body"
                    ></p>
                </div>
            </div>

            {{--
                Actions on a tinted footer, the same device the card header uses
                to separate itself from its body. Reversed on mobile so the
                confirming action sits under the thumb.
            --}}
            <div class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3.5 sm:flex-row sm:justify-end">
                <x-ui.button
                    type="button"
                    variant="secondary"
                    x-show="$store.dialog.isConfirmation"
                    x-on:click="$store.dialog.dismissed()"
                    x-text="$store.dialog.config.cancelText"
                />

                {{--
                    Two buttons rather than one with computed classes, so the
                    variant styling stays entirely inside x-ui.button and is
                    never restated as a string here.
                --}}
                <x-ui.button
                    type="button"
                    variant="danger"
                    x-show="$store.dialog.isConfirmation && $store.dialog.config.tone === 'danger'"
                    x-on:click="$store.dialog.confirmed()"
                    x-text="$store.dialog.config.confirmText"
                />

                <x-ui.button
                    type="button"
                    variant="primary"
                    x-show="!$store.dialog.isConfirmation || $store.dialog.config.tone !== 'danger'"
                    x-on:click="$store.dialog.confirmed()"
                    x-text="$store.dialog.config.confirmText"
                />
            </div>
        </div>
    </div>
</div>
