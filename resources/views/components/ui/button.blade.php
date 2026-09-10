@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'submit',

    /*
     * Ask before the click goes through.
     *
     * An array in the shape the dialog store expects — title, body,
     * confirmText, and an optional tone of danger|warning|brand:
     *
     *     <x-ui.button
     *         wire:click="removeMember({{ $member->id }})"
     *         :confirm="[
     *             'title' => 'Remove '.$member->name.' from this board?',
     *             'body' => 'They lose access immediately.',
     *             'confirmText' => 'Remove member',
     *         ]"
     *     >
     *
     * A prop rather than writing `x-confirm="@js(…)"` on the tag, because Blade
     * does NOT compile directives inside component attributes — the tag
     * compiler treats an unbound attribute as a literal string, so `@js(...)`
     * would reach the browser verbatim and the button would fire without
     * asking. A bound prop is evaluated as PHP, which is the whole difference.
     *
     * On plain HTML elements there is no tag compiler in the way, so those use
     * `x-confirm="@js([...])"` directly. Both produce the same `x-confirm`
     * attribute and are handled by the one directive in resources/js/dialog.js.
     */
    'confirm' => null,
])

@php
    $variants = [
        'primary' => 'bg-brand-solid text-white shadow-sm hover:bg-brand-solid-hover focus-visible:outline-brand-solid',
        'secondary' => 'bg-surface text-slate-700 ring-1 ring-inset ring-slate-300 shadow-xs hover:bg-slate-50 focus-visible:outline-slate-400',
        'ghost' => 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-slate-400',
        'danger' => 'bg-danger-solid text-white shadow-sm hover:bg-danger-solid-hover focus-visible:outline-danger-solid',
    ];

    /*
     * Tighter than they were, by roughly one step each.
     *
     * A button's padding is the single loudest density signal in an admin
     * interface — a toolbar of five of them at the old `md` size took a
     * third more width than its labels needed. The vertical padding came
     * down further than the horizontal, because a button that is short
     * reads as crisp while one that is narrow reads as cramped.
     */
    $sizes = [
        'sm' => 'px-2 py-1 text-xs gap-1.5',
        'md' => 'px-3 py-1.5 text-sm gap-1.5',
        'lg' => 'px-3.5 py-2 text-sm gap-2',
    ];

    $classes = collect([
        'inline-flex items-center justify-center rounded-md font-medium transition',
        'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2',
        'disabled:cursor-not-allowed disabled:opacity-60',
        $variants[$variant] ?? $variants['primary'],
        $sizes[$size] ?? $sizes['md'],
    ])->implode(' ');
@endphp

{{--
    Rendered outside the attribute bag on purpose: Js::from() returns an
    already-escaped Htmlable, and merging it into the bag would escape it a
    second time.
--}}
@if ($href)
    <a
        href="{{ $href }}"
        wire:navigate
        @if ($confirm) x-confirm="{{ \Illuminate\Support\Js::from($confirm) }}" @endif
        {{ $attributes->class($classes) }}
    >
        {{ $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        @if ($confirm) x-confirm="{{ \Illuminate\Support\Js::from($confirm) }}" @endif
        {{ $attributes->class($classes) }}
    >
        {{ $slot }}
    </button>
@endif
