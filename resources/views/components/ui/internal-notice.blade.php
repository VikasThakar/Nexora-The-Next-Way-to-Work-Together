@props([
    'title' => 'Internal only.',
])

{{--
    The explanation that goes with x-ui.internal-badge, for the places that need
    a sentence rather than a chip: above the internal comment composer and at
    the head of the workspace AI transcript.

    Slate rather than amber, and a left rail rather than a filled panel, because
    this is a standing fact about the surface the reader is on — not a warning
    about something they are about to do. Warnings in this product stay amber
    (see x-ui.dialog) and rose is still reserved for destructive.
--}}
<div {{ $attributes->class('flex items-start gap-2 rounded-lg border border-slate-200 border-l-2 border-l-slate-400 bg-slate-50 px-3 py-2 text-xs text-slate-600') }}>
    <svg class="mt-px size-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
    </svg>

    <span class="min-w-0">
        <span class="font-semibold text-slate-700">{{ $title }}</span>
        {{ $slot }}
    </span>
</div>
