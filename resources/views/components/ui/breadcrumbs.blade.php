@props([
    // list<array{label: string, href: ?string, mono?: bool}>, root first.
    // Built by App\Support\Breadcrumbs; see that class for the shape.
    'trail' => [],
])

{{--
    The full path, not the last two levels.

    Overflow is handled by scrolling rather than by collapsing items: the
    requirement is that every level stays reachable, and a trail that hides its
    middle on a narrow screen fails that. The current page is named again in the
    <h1> immediately below this, so a trail scrolled off to the left costs the
    reader nothing.
--}}
@if (! empty($trail))
    <nav aria-label="Breadcrumb" {{ $attributes->class('mb-1 -mx-1 overflow-x-auto scrollbar-none') }}>
        <ol class="flex items-center gap-1 px-1 text-xs whitespace-nowrap text-slate-500">
            @foreach ($trail as $index => $crumb)
                @php
                    $isCurrent = $index === array_key_last($trail);
                    $mono = (bool) ($crumb['mono'] ?? false);
                @endphp

                <li class="flex shrink-0 items-center gap-1">
                    @if ($index > 0)
                        <span class="text-slate-300" aria-hidden="true">/</span>
                    @endif

                    @if (! empty($crumb['href']) && ! $isCurrent)
                        <a
                            href="{{ $crumb['href'] }}"
                            wire:navigate
                            @class([
                                'rounded transition hover:text-slate-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500',
                                'font-mono' => $mono,
                            ])
                        >{{ $crumb['label'] }}</a>
                    @else
                        <span
                            @if ($isCurrent) aria-current="page" @endif
                            @class([
                                'font-medium text-slate-700' => $isCurrent,
                                'font-mono' => $mono,
                            ])
                        >{{ $crumb['label'] }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif
