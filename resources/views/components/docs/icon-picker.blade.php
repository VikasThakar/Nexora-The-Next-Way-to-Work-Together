@props([
    /** The icon currently on the page, or '' for none. */
    'current' => '',

    /*
     * Where the choice goes. Exactly one of these.
     *
     *   method    a Livewire method called with the emoji — used on a page
     *             that already exists, where picking an icon is a save.
     *   property  a Livewire property set without a round trip — used in the
     *             create form, where there is no page to write to yet.
     */
    'method' => null,
    'property' => null,

    'size' => 'md',
])

@php
    /*
     * A short, deliberately dull set.
     *
     * Documentation icons earn their place by being recognisable at 13px in a
     * list of thirty, which rules out most of the emoji block: anything with
     * fine detail reads as a smudge, and anything with a face reads as a mood.
     * These are objects and symbols, in the categories documentation actually
     * falls into.
     *
     * Not exhaustive on purpose. A grid of 1,800 emoji is a decision nobody
     * wants to make while writing; twenty-four is a decision you make in a
     * second. Anything outside the set can still be stored — the column takes
     * whatever grapheme is written to it.
     */
    $icons = [
        '📄', '📁', '📚', '📝', '📌', '🧭',
        '🚀', '⚙️', '🔧', '🔐', '🔌', '🧪',
        '📊', '📈', '🗂️', '🗃️', '🧩', '🪄',
        '💡', '⚠️', '✅', '🏷️', '🔍', '🕒',
    ];

    $trigger = $size === 'lg'
        ? 'size-11 text-3xl'
        : 'size-7 text-lg';
@endphp

{{--
    The page icon control.

    A button showing the current icon, and a popover of the set above. Kept as
    its own component because it appears twice — on the document header and in
    the create form — and those two write to different places, which is the
    only thing that differs between them.
--}}
<div x-data="{ open: false }" x-on:keydown.escape.stop="open = false" class="relative">
    <button
        type="button"
        x-on:click="open = ! open"
        x-bind:aria-expanded="open ? 'true' : 'false'"
        @class([
            'group/icon flex shrink-0 items-center justify-center rounded-md leading-none transition hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500',
            $trigger,
        ])
        title="Choose a page icon"
        aria-label="Choose a page icon"
    >
        @if (filled($current))
            <span aria-hidden="true">{{ $current }}</span>
        @else
            {{--
                A dashed square with a plus, not a smiling face.

                The placeholder has to read as "there is room for something
                here", and a face is a picture in its own right — it looks like
                the page's icon already is a smiley. Faint, because an empty
                slot should not compete with the title beside it.
            --}}
            <span class="flex size-[80%] items-center justify-center rounded border border-dashed border-slate-300 text-slate-400 transition group-hover/icon:border-slate-400 group-hover/icon:text-slate-600" aria-hidden="true">
                <svg class="size-[55%]" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
            </span>
        @endif
    </button>

    <div
        x-show="open"
        x-cloak
        x-on:click.outside="open = false"
        x-transition.opacity.duration.100ms
        class="absolute left-0 z-30 mt-1 w-56 rounded-lg border border-slate-200 bg-surface-raised p-2 shadow-lg"
    >
        <div class="grid grid-cols-6 gap-0.5">
            @foreach ($icons as $icon)
                {{--
                    Both branches go through Alpine and `@js()`, which emits a
                    JSON.parse of the value rather than interpolating it into an
                    attribute. That keeps the emoji out of Livewire's own
                    expression parser — a multi-codepoint grapheme inside a
                    `wire:click="setIcon('…')"` string is a needless thing to
                    rely on — and lets one handler both send the choice and
                    close the popover.
                --}}
                <button
                    type="button"
                    @if ($method)
                        x-on:click="$wire.call(@js($method), @js($icon)); open = false"
                    @else
                        x-on:click="$wire.set(@js($property), @js($icon), false); open = false"
                    @endif
                    @class([
                        'flex size-8 items-center justify-center rounded text-lg leading-none transition hover:bg-slate-100',
                        'bg-brand-50 ring-1 ring-brand-300' => $current === $icon,
                    ])
                    aria-label="Use {{ $icon }} as the page icon"
                >
                    <span aria-hidden="true">{{ $icon }}</span>
                </button>
            @endforeach
        </div>

        @if (filled($current))
            <button
                type="button"
                @if ($method)
                    x-on:click="$wire.call(@js($method), ''); open = false"
                @else
                    x-on:click="$wire.set(@js($property), '', false); open = false"
                @endif
                class="mt-1.5 w-full rounded border-t border-slate-100 px-2 pt-1.5 text-left text-xs text-slate-500 hover:text-slate-800"
            >
                Remove icon
            </button>
        @endif
    </div>
</div>
