@props([
    'ticket',
    'board',
    'canSeeInternal' => false,
    'draggable' => false,
])

@php
    $url = route('tickets.show', ['board' => $board, 'number' => $ticket->number]);
    $subtaskTotal = $ticket->subtasks_count ?? 0;
    $subtaskDone = $ticket->completed_subtasks_count ?? 0;
    $overdue = $ticket->due_date !== null && $ticket->due_date->isPast();
@endphp

{{--
    The card is a link, and the whole card is also a drag target.

    A drag that ends on the card would otherwise fire a click and navigate away
    from the board. The small Alpine guard below tracks whether the pointer
    travelled more than a few pixels between press and release, and cancels the
    click if it did. Written inline rather than as a registered Alpine component
    so it cannot race Livewire's Alpine bootstrap.
--}}
<a
    href="{{ $url }}"
    wire:navigate
    x-data="{ startX: 0, startY: 0, dragged: false }"
    @pointerdown="startX = $event.clientX; startY = $event.clientY; dragged = false"
    @pointermove="if (Math.abs($event.clientX - startX) > 5 || Math.abs($event.clientY - startY) > 5) dragged = true"
    @click="if (dragged) { $event.preventDefault(); dragged = false }"
    {{ $attributes->class([
        'group block rounded-lg border border-slate-200 bg-surface p-3 shadow-xs transition',
        'hover:border-brand-300 hover:shadow-md',
        'cursor-grab active:cursor-grabbing' => $draggable,
    ]) }}
>
    <div class="flex items-start gap-2">
        <span class="mt-1 size-2 shrink-0 rounded-full {{ $ticket->priority->dotClass() }}"
              title="{{ $ticket->priority->label() }} priority"></span>

        <p class="min-w-0 flex-1 text-sm leading-snug font-medium text-slate-900 group-hover:text-brand-700">
            {{ $ticket->title }}
        </p>
    </div>

    @if ($ticket->labels->isNotEmpty())
        <div class="mt-2 flex flex-wrap gap-1">
            @foreach ($ticket->labels as $label)
                <x-ui.label-chip :label="$label" size="sm" />
            @endforeach
        </div>
    @endif

    <div class="mt-3 flex items-center gap-2 text-xs text-slate-500">
        <span class="font-mono font-medium text-slate-400">{{ $ticket->key() }}</span>

        {{--
            The type, shown only when it is a Bug.

            Task is the default and Feature is unremarkable, so labelling all
            three would put a chip on every card and communicate nothing. A bug
            is the one that changes how somebody reads the board at a glance.
        --}}
        @if ($ticket->type === \App\Enums\TicketType::Bug)
            <span class="inline-flex items-center rounded px-1 py-px text-[10px] font-semibold text-rose-700 ring-1 ring-rose-200 ring-inset"
                  title="Bug">
                Bug
            </span>
        @endif

        @if ($canSeeInternal && ! $ticket->customer_visible)
            {{--
                Only staff ever render this, and only staff can see the ticket.
                Kept as a bare chip rather than x-ui.internal-badge: a Kanban
                card is dense, and the full pill with its lock crowds the row.
                The colour and wording match the badge.
            --}}
            <span class="inline-flex items-center gap-1 rounded px-1 py-px text-[10px] font-medium text-slate-500 ring-1 ring-slate-300 ring-inset"
                  title="Internal only. Not visible to the customer.">
                Internal
            </span>
        @endif

        @if ($subtaskTotal > 0)
            <span class="inline-flex items-center gap-1" title="Subtasks completed">
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
                {{ $subtaskDone }}/{{ $subtaskTotal }}
            </span>
        @endif

        @if (($ticket->attachments_count ?? 0) > 0)
            <span class="inline-flex items-center gap-1" title="Attachments">
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13" />
                </svg>
                {{ $ticket->attachments_count }}
            </span>
        @endif

        @if ($ticket->due_date)
            <span @class([
                'inline-flex items-center gap-1',
                'font-medium text-rose-600' => $overdue,
            ]) title="Due {{ $ticket->due_date->toFormattedDateString() }}">
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                </svg>
                {{ $ticket->due_date->format('j M') }}
            </span>
        @endif

        <span class="ml-auto">
            @if ($ticket->assignee)
                <x-ui.avatar :name="$ticket->assignee->name" size="sm" :title="'Assigned to '.$ticket->assignee->name" />
            @else
                <span class="inline-flex size-7 items-center justify-center rounded-full border border-dashed border-slate-300 text-slate-300"
                      title="Unassigned">
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                    </svg>
                </span>
            @endif
        </span>
    </div>
</a>
