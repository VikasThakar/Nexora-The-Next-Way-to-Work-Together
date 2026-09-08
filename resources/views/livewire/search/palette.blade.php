{{--
    The command palette.

    Mounted once in the application shell and always present in the DOM, hidden
    by Alpine — so ⌘K puts a focused input on screen in one frame rather than
    after a round trip.

    Every row below came back from App\Services\Search\GlobalSearch, which runs
    each category through the visibility scope that already protects the screen
    the row belongs to. This template makes no access decision; there is nothing
    here it could decide with.
--}}
<div
    x-data="commandPalette"
    x-show="$store.palette.open"
    x-cloak
    x-on:keydown.escape.stop="$store.palette.hide()"
    x-on:keydown.down.prevent="move(1)"
    x-on:keydown.up.prevent="move(-1)"
    x-on:keydown.enter.prevent="choose()"
    class="fixed inset-0 z-50 flex items-start justify-center px-4 pt-16 sm:pt-24"
    role="dialog"
    aria-modal="true"
    aria-label="Search Nexora"
>
    <div
        x-on:click="$store.palette.hide()"
        class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm"
        aria-hidden="true"
    ></div>

    <div
        x-transition.origin.top
        class="relative flex max-h-[min(32rem,calc(100dvh-8rem))] w-full max-w-xl flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl"
    >
        {{-- ------------------------------------------------------------- --}}
        {{-- The box                                                        --}}
        {{-- ------------------------------------------------------------- --}}
        <div class="flex shrink-0 items-center gap-2 border-b border-slate-200 px-3">
            <svg class="size-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
            </svg>

            {{--
                role="combobox" with aria-controls and aria-activedescendant is
                what makes arrow-key navigation audible: without them a screen
                reader announces a text field and never mentions that the
                highlighted row changed.
            --}}
            <input
                x-ref="input"
                type="text"
                wire:model.live.debounce.250ms="term"
                class="min-w-0 flex-1 border-0 bg-transparent py-3 text-sm text-slate-900 placeholder:text-slate-400 focus:ring-0 focus:outline-none"
                placeholder="Search tickets, documentation, boards and people…"
                autocomplete="off"
                spellcheck="false"
                role="combobox"
                aria-expanded="true"
                aria-controls="palette-results"
                aria-autocomplete="list"
                x-bind:aria-activedescendant="hits()[active]?.id ?? null"
            >

            <div wire:loading.delay wire:target="term" class="shrink-0">
                <svg class="size-4 animate-spin text-slate-400" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z" />
                </svg>
            </div>

            @if (filled($term))
                <button
                    type="button"
                    wire:click="clear"
                    x-on:click="$refs.input.focus()"
                    class="shrink-0 rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                    aria-label="Clear the search"
                >
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            @endif
        </div>

        {{-- ------------------------------------------------------------- --}}
        {{-- Results                                                        --}}
        {{-- ------------------------------------------------------------- --}}
        {{--
            x-effect rather than a watcher: it re-runs whenever the expression's
            dependencies change, and reading the hit count is what makes a
            Livewire re-render reposition a cursor left past the end of a
            narrower list.
        --}}
        <div
            id="palette-results"
            class="min-h-0 flex-1 overflow-y-auto overscroll-contain py-1"
            role="listbox"
            aria-label="Search results"
            x-effect="hits().length; clamp()"
        >
            @php $index = 0; @endphp

            @forelse ($groups as $group)
                <div wire:key="group-{{ $group['key'] }}" role="group" aria-labelledby="palette-group-{{ $group['key'] }}">
                    <p
                        id="palette-group-{{ $group['key'] }}"
                        class="px-3 pt-2 pb-1 text-[11px] font-semibold tracking-wide text-slate-400 uppercase"
                    >
                        {{ $group['label'] }}
                    </p>

                    @foreach ($group['hits'] as $hit)
                        @php $position = $index++; @endphp

                        {{--
                            An anchor either way, with an href only when there
                            is somewhere to go. GlobalSearch decides which — a
                            person is an answer for a team member and a
                            destination only for an administrator — so this
                            template never has to know that rule.

                            An <a> with no href is not focusable and not
                            clickable, so the keyboard handler's click() on such
                            a row does nothing, which is the intended
                            behaviour rather than an accident.
                        --}}
                        <a
                            wire:key="hit-{{ $hit->id }}"
                            id="palette-hit-{{ $hit->id }}"
                            @if ($hit->url)
                                href="{{ $hit->url }}"
                                wire:navigate
                            @endif
                            data-hit
                            role="option"
                            x-bind:aria-selected="isActive({{ $position }})"
                            x-on:mouseenter="active = {{ $position }}"
                            x-bind:class="isActive({{ $position }}) ? 'bg-brand-50' : ''"
                            @class([
                                'flex items-center gap-3 px-3 py-2 text-sm',
                                'cursor-pointer' => $hit->url !== null,
                            ])
                        >
                            @if ($hit->key)
                                <span class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] font-medium text-slate-600">
                                    {{ $hit->key }}
                                </span>
                            @endif

                            <span class="min-w-0 flex-1 truncate text-slate-800">{{ $hit->label }}</span>

                            @if ($hit->hint)
                                <span class="shrink-0 truncate text-xs text-slate-400">{{ $hit->hint }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            @empty
                <p class="px-3 py-10 text-center text-sm text-slate-400">
                    @if ($tooShort)
                        Keep typing — {{ $minLength }} characters or more.
                    @elseif ($searching)
                        Nothing matches &ldquo;{{ $term }}&rdquo;.
                    @else
                        Search tickets by key or title, documentation, boards and people.
                    @endif
                </p>
            @endforelse
        </div>

        {{-- ------------------------------------------------------------- --}}
        {{-- Key hints                                                      --}}
        {{-- ------------------------------------------------------------- --}}
        <div class="hidden shrink-0 items-center gap-3 border-t border-slate-100 bg-slate-50 px-3 py-2 text-[11px] text-slate-500 sm:flex">
            <span class="flex items-center gap-1">
                <kbd class="rounded border border-slate-300 bg-white px-1 font-sans">&uarr;</kbd>
                <kbd class="rounded border border-slate-300 bg-white px-1 font-sans">&darr;</kbd>
                to move
            </span>
            <span class="flex items-center gap-1">
                <kbd class="rounded border border-slate-300 bg-white px-1 font-sans">Enter</kbd>
                to open
            </span>
            <span class="flex items-center gap-1">
                <kbd class="rounded border border-slate-300 bg-white px-1 font-sans">Esc</kbd>
                to close
            </span>
        </div>
    </div>
</div>
