@props([
    /*
     * The person, when there is one.
     *
     * Optional, and that is not laziness. Three call sites have no User to
     * pass — the assistant's own turns in a conversation ('AI'), and an author
     * whose account has since been removed ('?') — so this component cannot
     * become user-typed without those losing their disc. A name is the thing
     * every caller has; a user is the thing some of them have.
     */
    'user' => null,

    'name' => '?',
    'size' => 'md',

    /*
     * An accessible name, for the one case where the avatar stands alone.
     *
     * Almost everywhere this component is used, the person's name is rendered
     * right next to it, so the disc is decoration and is hidden from assistive
     * technology — announcing "VR" before "Vikas Raman" is noise. The exception
     * is the board card, where the assignee disc is the only representation
     * there is. Passing a label there turns it from decoration into content.
     */
    'label' => null,
])

@php
    $display = $user?->name ?? $name;

    /*
     * Initials come from the model when there is one, because
     * App\Models\User::initials() falls back to the email — so somebody whose
     * name is blank still gets a letter rather than a question mark. The
     * inline split below is the same algorithm for callers passing a string.
     */
    $initials = $user
        ? $user->initials()
        : collect(preg_split('/\s+/', trim($display)) ?: [])
            ->filter()
            ->take(2)
            ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');

    $sizes = [
        'sm' => 'size-7 text-[11px]',
        'md' => 'size-9 text-xs',
        'lg' => 'size-12 text-sm',
    ];
    $sizeClass = $sizes[$size] ?? $sizes['md'];

    /*
     * Deterministic hue per name so the same person keeps the same colour.
     *
     * Tokens rather than palette classes, and that is the one exception to the
     * remap in resources/css/app.css: the accent 600 shades are lifted for
     * dark mode, because that is what `text-brand-600` resolves to in link
     * text. A disc carrying white initials needs the opposite of a lift, so
     * these six are pinned and identical in both appearances.
     */
    $palette = [
        'bg-avatar-brand',
        'bg-avatar-emerald',
        'bg-avatar-violet',
        'bg-avatar-amber',
        'bg-avatar-rose',
        'bg-avatar-cyan',
    ];
    $colour = $palette[crc32($display) % count($palette)];

    $photo = $user?->avatarUrl();
@endphp

@if ($photo)
    {{--
        Same geometry as the initials disc, so swapping between them never
        moves anything on the page. object-cover because the stored file is
        already square — this only matters if a future size is not.
    --}}
    <img
        src="{{ $photo }}"
        alt="{{ $label ?? '' }}"
        @if ($label === null) aria-hidden="true" @endif
        loading="lazy"
        decoding="async"
        {{ $attributes->class([
            'inline-block shrink-0 rounded-full object-cover select-none',
            $sizeClass,
        ]) }}
    >
@else
    <span {{ $attributes->class([
        'inline-flex shrink-0 items-center justify-center rounded-full font-semibold text-white select-none',
        $sizeClass,
        $colour,
    ]) }} @if ($label === null) aria-hidden="true" @endif>
        @if ($label !== null)
            <span class="sr-only">{{ $label }}</span>
        @endif
        <span aria-hidden="true">{{ $initials !== '' ? $initials : '?' }}</span>
    </span>
@endif
