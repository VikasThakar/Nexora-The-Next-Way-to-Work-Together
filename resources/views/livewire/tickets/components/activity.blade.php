@php
    /**
     * Renders one event payload as a sentence.
     *
     * Deliberately defensive: payloads are JSON written by earlier versions of
     * the application, so a missing or renamed key must degrade to a plain
     * label rather than throw years later.
     */
    $describe = function ($event): ?string {
        $payload = $event->payload ?? [];

        return match ($event->type) {
            \App\Enums\TicketEventType::TicketMoved => isset($payload['from_column'], $payload['to_column'])
                ? $payload['from_column'].' → '.$payload['to_column']
                    .(($payload['reason'] ?? null) === 'column_deleted' ? ' (column removed)' : '')
                : null,

            \App\Enums\TicketEventType::PriorityChanged => isset($payload['to'])
                ? (\App\Enums\TicketPriority::tryFrom((string) ($payload['from'] ?? ''))?->label() ?? '—')
                    .' → '.(\App\Enums\TicketPriority::tryFrom((string) $payload['to'])?->label() ?? '—')
                : null,

            \App\Enums\TicketEventType::VisibilityChanged => ($payload['to'] ?? false)
                ? 'now visible to the customer'
                : 'now internal only',

            \App\Enums\TicketEventType::LabelChanged => collect([
                    filled($payload['added'] ?? []) ? 'added '.implode(', ', array_filter((array) $payload['added'])) : null,
                    filled($payload['removed'] ?? []) ? 'removed '.implode(', ', array_filter((array) $payload['removed'])) : null,
                ])->filter()->implode('; ') ?: null,

            \App\Enums\TicketEventType::LinkChanged => trim(
                ($payload['action'] ?? '').' '.($payload['relation'] ?? '').' '.($payload['ticket'] ?? '')
            ) ?: null,

            \App\Enums\TicketEventType::AttachmentChanged => trim(
                ($payload['action'] ?? '').' '.($payload['filename'] ?? '')
            ) ?: null,

            \App\Enums\TicketEventType::TicketUpdated => filled($payload['changes'] ?? [])
                ? implode(', ', array_map(
                    fn ($field) => str_replace(['_md', '_'], ['', ' '], $field),
                    array_keys((array) $payload['changes'])
                ))
                : null,

            \App\Enums\TicketEventType::AssigneeChanged => ($payload['to'] ?? null) === null
                ? 'unassigned'
                : null,

            // AI events only ever reach staff: TicketEventType::isInternalOnly()
            // marks all four, and TicketEvent::readableBy() drops them for a
            // customer before this renderer is reached.
            \App\Enums\TicketEventType::AiRunQueued,
            \App\Enums\TicketEventType::AiRunCompleted => trim(
                ($payload['mode'] ?? '').' '.($payload['trigger'] ?? '')
            ) ?: null,

            \App\Enums\TicketEventType::AiRunFailed,
            \App\Enums\TicketEventType::AiRunSkipped => \Illuminate\Support\Str::limit(
                (string) ($payload['reason'] ?? ''), 120
            ) ?: null,

            default => null,
        };
    };
@endphp

<x-ui.card title="Activity" :description="$total.' '.\Illuminate\Support\Str::plural('event', $total)">
    @if ($events->isEmpty())
        <p class="text-sm text-slate-400">Nothing recorded yet.</p>
    @else
        <ol class="relative space-y-4 border-l border-slate-200 pl-5">
            @foreach ($events as $event)
                <li wire:key="event-{{ $event->id }}" class="relative">
                    <span class="absolute -left-[26px] top-1 flex size-3 items-center justify-center rounded-full bg-white ring-2 ring-slate-300"></span>

                    <div class="flex flex-wrap items-baseline gap-x-1.5 text-sm">
                        <span class="font-medium text-slate-900">{{ $event->actor?->name ?? 'System' }}</span>
                        <span class="text-slate-600">{{ $event->type->label() }}</span>

                        @php $detail = $describe($event); @endphp

                        @if ($detail)
                            <span class="text-slate-500">— {{ $detail }}</span>
                        @endif
                    </div>

                    <time class="text-xs text-slate-400" datetime="{{ $event->created_at?->toIso8601String() }}"
                          title="{{ $event->created_at?->toDayDateTimeString() }}">
                        {{ $event->created_at?->diffForHumans() }}
                    </time>
                </li>
            @endforeach
        </ol>

        @if ($hasMore)
            <div class="mt-4">
                <x-ui.button type="button" variant="secondary" size="sm" wire:click="showMore">
                    Show earlier activity
                </x-ui.button>
            </div>
        @endif
    @endif
</x-ui.card>
