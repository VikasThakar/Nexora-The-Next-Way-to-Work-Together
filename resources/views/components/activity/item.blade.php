@props([
    'activity',
    'timezone' => 'UTC',
])

@php
    /**
     * One row of the workspace activity feed.
     *
     * This template makes no access decision. Every row reaching it has already
     * passed App\Models\Activity::readableBy() (board membership, the internal
     * boundary, and the administrator-only rule for workspace-level rows), and
     * everything it renders comes from the stored description and properties
     * rather than from a live lookup — which is what lets a row about a deleted
     * ticket still read correctly.
     *
     * The actor's name is the one exception, and deliberately so: it is read
     * from the user record, so somebody who changes their name is not left with
     * a history written under a name they no longer use. See
     * App\Services\ActivityLogger for why descriptions are stored without it.
     */
    $type = $activity->type();

    $tones = [
        'brand' => 'bg-brand-50 text-brand-600 ring-brand-100',
        'emerald' => 'bg-emerald-50 text-emerald-600 ring-emerald-100',
        'amber' => 'bg-amber-50 text-amber-600 ring-amber-100',
        'rose' => 'bg-rose-50 text-rose-600 ring-rose-100',
        'slate' => 'bg-slate-100 text-slate-500 ring-slate-200',
    ];

    $tone = $type?->tone() ?? 'slate';
    $marker = $tones[$tone] ?? $tones['slate'];

    $actorName = $activity->actor?->name ?? 'System';

    $from = $activity->property('from');
    $to = $activity->property('to');
    $showTransition = filled($from) && filled($to);

    // Ticket context, stored at write time so it survives the ticket.
    $ticketKey = $activity->property('ticket.key');
    $ticketNumber = $activity->property('ticket.number');
    $ticketBoardSlug = $activity->property('ticket.board_slug');

    /*
     * Linked only when there is something still there to open. A row recording
     * a deletion names the ticket but must not offer a link to it, and a row
     * written before the board slug was stored has nothing to build a URL from.
     */
    $ticketUrl = $ticketNumber && $ticketBoardSlug && $type !== \App\Enums\ActivityType::TicketDeleted
        ? route('tickets.show', ['board' => $ticketBoardSlug, 'number' => $ticketNumber])
        : null;
@endphp

<li {{ $attributes->class('flex gap-3 px-5 py-4 transition hover:bg-slate-50') }}>
    {{-- Type marker. Decorative: the type is also named in the badge. --}}
    <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full ring-1 ring-inset {{ $marker }}">
        <x-activity.icon :name="$type?->icon() ?? 'pencil'" />
    </span>

    <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
            <p class="min-w-0 text-sm text-slate-600">
                <x-ui.avatar :name="$actorName" size="sm" class="mr-1.5 -mt-0.5 align-middle" />
                <span class="font-medium text-slate-900">{{ $actorName }}</span>
                {{ $activity->description }}
            </p>

            <time
                class="shrink-0 text-xs whitespace-nowrap text-slate-400"
                datetime="{{ $activity->created_at?->toIso8601String() }}"
                title="{{ \App\Support\ActivityTime::exact($activity->created_at, $timezone) }}"
            >
                {{ \App\Support\ActivityTime::relative($activity->created_at, $timezone) }}
            </time>
        </div>

        {{-- Previous → new, where the change has two ends worth showing. --}}
        @if ($showTransition)
            <p class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs">
                <span class="rounded-md bg-slate-100 px-1.5 py-0.5 font-medium text-slate-600">{{ $from }}</span>
                <span class="text-slate-400" aria-hidden="true">&rarr;</span>
                <span class="rounded-md bg-slate-100 px-1.5 py-0.5 font-medium text-slate-900">{{ $to }}</span>
            </p>
        @endif

        <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
            @if ($type)
                <x-ui.badge :variant="$tone">{{ $type->label() }}</x-ui.badge>
            @endif

            @if ($ticketKey)
                @if ($ticketUrl)
                    <a href="{{ $ticketUrl }}" wire:navigate class="font-mono font-medium text-brand-700 hover:underline">
                        {{ $ticketKey }}
                    </a>
                @else
                    <span class="font-mono text-slate-400 line-through">{{ $ticketKey }}</span>
                @endif
            @endif

            @if ($activity->board)
                <span class="text-slate-300" aria-hidden="true">&middot;</span>
                <a href="{{ route('boards.show', $activity->board) }}" wire:navigate class="truncate hover:text-slate-700 hover:underline">
                    {{ $activity->board->name }}
                </a>
            @else
                <span class="text-slate-300" aria-hidden="true">&middot;</span>
                <span class="text-slate-400">Workspace</span>
            @endif
        </div>
    </div>
</li>
