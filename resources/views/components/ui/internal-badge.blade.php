@props([
    'label' => 'Internal',
])

{{--
    "A customer cannot see this."

    One component for every place that mark appears — the ticket header, a doc
    page, a comment in the internal stream, a notification row and the Kanban
    card — so the signal is identical everywhere and changing it is one edit
    rather than five. The lock carries the meaning without relying on colour,
    which the previous amber-only treatment did not.
--}}
<x-ui.badge variant="internal" {{ $attributes }}>
    <svg class="size-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
    </svg>
    {{ $slot->isEmpty() ? $label : $slot }}
</x-ui.badge>
