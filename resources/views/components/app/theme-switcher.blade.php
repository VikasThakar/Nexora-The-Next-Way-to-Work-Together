@php
    /**
     * The appearance control, at the foot of the sidebar.
     *
     * A segmented group of three rather than a switch, because there are three
     * answers and a switch can only carry two. "System" is the one people
     * forget they want until their laptop turns dark at six o'clock and the one
     * application still glowing is this one.
     *
     * Driven entirely by the Alpine store in resources/js/theme.js — no
     * Livewire component, and that is deliberate. The sidebar is a plain Blade
     * component included on every page; a Livewire component inside it would be
     * mounted and hydrated on every navigation to render three buttons. More
     * importantly the appearance has to change on the click itself: a server
     * round trip between pressing "Dark" and the page going dark is the
     * difference between a control that feels native and one that feels remote.
     * The store writes the class, then tells the server in the background.
     *
     * `role="radiogroup"` rather than three buttons or a `<select>`: this is one
     * setting with three mutually exclusive values, which is what a radio group
     * means. That also gives arrow-key navigation for free in every screen
     * reader, and it is why each option carries `aria-checked` rather than
     * `aria-pressed` — pressed would describe three independent toggles.
     */
    $options = \App\Enums\ThemePreference::ordered();
@endphp

<div class="border-t border-sidebar-border px-3 py-3">
    <div
        x-data
        role="radiogroup"
        aria-label="Appearance"
        class="flex items-center gap-0.5 rounded-lg bg-sidebar-hover/60 p-0.5"
    >
        @foreach ($options as $option)
            <button
                type="button"
                role="radio"
                x-bind:aria-checked="$store.theme.is(@js($option->value)) ? 'true' : 'false'"
                {{-- Only the selected option is in the tab order, so Tab moves
                     past the group rather than through it; the arrow keys move
                     within it, which is what a radio group is expected to do. --}}
                x-bind:tabindex="$store.theme.is(@js($option->value)) ? 0 : -1"
                x-on:click="$store.theme.set(@js($option->value))"
                x-on:keydown.right.prevent="$el.nextElementSibling?.focus(); $el.nextElementSibling?.click()"
                x-on:keydown.left.prevent="$el.previousElementSibling?.focus(); $el.previousElementSibling?.click()"
                x-bind:class="$store.theme.is(@js($option->value))
                    ? 'bg-sidebar-ink/10 text-sidebar-ink shadow-xs'
                    : 'text-sidebar-ink-dim hover:text-sidebar-ink'"
                class="flex flex-1 items-center justify-center gap-1.5 rounded-md px-2 py-1.5 text-xs font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400"
                title="{{ $option->description() }}"
            >
                {{--
                    Icons drawn inline in the same 24-box, 1.6-weight outline
                    style as every other icon in the sidebar. No icon package:
                    the product does not have one, and three paths is not a
                    reason to add a dependency.
                --}}
                <svg class="size-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
                    @switch($option)
                        @case(\App\Enums\ThemePreference::Light)
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                            @break

                        @case(\App\Enums\ThemePreference::Dark)
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
                            @break

                        @default
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25A2.25 2.25 0 0 1 5.25 3h13.5A2.25 2.25 0 0 1 21 5.25Z" />
                    @endswitch
                </svg>

                <span>{{ $option->label() }}</span>

                {{--
                    What the visible label leaves out. Three one-word buttons in
                    a row are ambiguous read aloud, and "System" says nothing at
                    all about what it does.
                --}}
                <span class="sr-only">{{ $option->description() }}</span>
            </button>
        @endforeach
    </div>
</div>
