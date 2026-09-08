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

            // GitHub events carry the repository, which is the piece of
            // context the reference alone does not give. The reference itself
            // is rendered as a link below rather than folded in here.
            \App\Enums\TicketEventType::GithubBranchCreated,
            \App\Enums\TicketEventType::GithubCommitPushed,
            \App\Enums\TicketEventType::GithubPullRequestOpened,
            \App\Enums\TicketEventType::GithubPullRequestMerged,
            \App\Enums\TicketEventType::GithubPullRequestClosed => $payload['repository'] ?? null,

            \App\Enums\TicketEventType::GithubCheckCompleted => trim(
                (string) ($payload['conclusion'] ?? '')
            ) ?: null,

            default => null,
        };
    };

    /**
     * The name in front of the sentence.
     *
     * A GitHub event has no Nexora actor — the delivery names a login, and
     * App\Services\TicketActivity::recordWithoutActor is used precisely so that
     * a webhook is not attributed to whoever happened to be signed in. The
     * login is shown instead, and "System" is kept for the rows that genuinely
     * had no author at all.
     */
    $actorFor = function ($event): string {
        $login = ($event->payload ?? [])['author'] ?? null;

        return $event->actor?->name
            ?? (filled($login) ? '@'.$login : 'System');
    };

    /**
     * A GitHub object's reference and link, or null.
     *
     * The URL was checked when the event was written — see
     * App\Services\ActivityLogger::githubUrl() — but it is checked again here,
     * because these rows were written from a webhook payload and this is the
     * line that becomes an href on a page every staff member can open.
     */
    $reference = function ($event): ?array {
        $payload = $event->payload ?? [];
        $label = $payload['short_reference'] ?? $payload['reference'] ?? null;

        if (blank($label)) {
            return null;
        }

        $url = (string) ($payload['url'] ?? '');
        $safe = str_starts_with($url, 'https://') || str_starts_with($url, 'http://');

        return ['label' => (string) $label, 'url' => $safe ? $url : null];
    };
@endphp

{{--
    The ticket's timeline: what people did and what GitHub did, in one list.

    The GitHub half used to be a panel of its own further up the page. It is
    here now because the two are one story — a branch cut, a commit pushed, a
    pull request merged and a priority changed are all "what happened to this
    ticket", and reading them in two places meant reconstructing the order by
    eye. There is still exactly one activity system: these rows are ordinary
    `ticket_events`, written through App\Services\TicketActivity like every
    other event and mirrored into the workspace feed by the same funnel.

    `github_links` still holds the objects themselves — it is the idempotency
    key a redelivered webhook lands on, and the record of a pull request's
    current state.
--}}
<x-ui.card
    title="Activity"
    :description="$total.' '.\Illuminate\Support\Str::plural('event', $total)"
    :padded="false"
>
    @if ($events->isEmpty())
        <p class="px-5 py-5 text-sm text-slate-400">Nothing recorded yet.</p>
    @else
        {{--
            A fixed viewport with its own scrollbar, so the card cannot grow to
            the height of the ticket's whole history and push the rest of the
            page — the comments, the attachments, the links — below the fold.

            The header stays put because it is outside this element: x-ui.card
            renders it in its own <header>, and only the body scrolls.

            Roughly six events on a desktop and four on a phone; overscroll
            containment stops a flick inside the list from scrolling the page
            once it reaches the end.
        --}}
        <div
            class="max-h-72 overflow-y-auto overscroll-contain scroll-smooth px-5 py-5 sm:max-h-96"
            tabindex="0"
            role="region"
            aria-label="Ticket activity, oldest at the bottom"
        >
            <ol class="relative space-y-4 border-l border-slate-200 pl-5">
                @foreach ($events as $event)
                    @php
                        $detail = $describe($event);
                        $object = $reference($event);
                    @endphp

                    <li wire:key="event-{{ $event->id }}" class="relative">
                        <span class="absolute -left-[26px] top-1 flex size-3 items-center justify-center rounded-full bg-surface ring-2 ring-slate-300"></span>

                        <div class="flex flex-wrap items-baseline gap-x-1.5 text-sm">
                            <span class="font-medium text-slate-900">{{ $actorFor($event) }}</span>
                            <span class="text-slate-600">{{ $event->type->label() }}</span>

                            @if ($object)
                                @if ($object['url'])
                                    {{-- rel="noopener noreferrer" is not decoration: without
                                         it the opened tab gets a window.opener handle back. --}}
                                    <a
                                        href="{{ $object['url'] }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="font-mono text-xs font-medium text-brand-700 hover:underline"
                                    >{{ $object['label'] }}</a>
                                @else
                                    <span class="font-mono text-xs text-slate-500">{{ $object['label'] }}</span>
                                @endif
                            @endif

                            @if ($detail)
                                <span class="text-slate-500">— {{ $detail }}</span>
                            @endif
                        </div>

                        @if (filled($event->payload['title'] ?? null))
                            <p class="truncate text-xs text-slate-500">{{ $event->payload['title'] }}</p>
                        @endif

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
        </div>
    @endif
</x-ui.card>
