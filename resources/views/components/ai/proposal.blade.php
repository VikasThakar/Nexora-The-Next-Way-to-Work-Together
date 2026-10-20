@props([
    // App\Models\AiChatMessage
    'message',
    // The message currently being confirmed, or null.
    'confirming' => null,
    // For a destructive action being confirmed: the key that has to be typed.
    // Supplied by the host from $this->pendingDeletionKey(); null otherwise.
    'deletionKey' => null,
])

@php
    $action = $message->action();
@endphp

{{--
    A change the assistant has proposed, and its state.

    Shared by the full-page board chat and the global panel, so the preview and
    the confirmation read identically wherever somebody meets them. That matters
    more here than in most shared markup: this is the screen on which a person
    decides whether a write happens, and two versions of it would eventually
    disagree about what they were agreeing to.

    Nothing here executes anything. The buttons call the host component, which
    re-authorizes and hands the message to App\Actions\AI\ExecuteChatAction.
--}}
@if ($action)
    @php
        $type = $message->actionType();
        $state = $message->actionState();
        $input = $message->actionInput();
    @endphp

    @php
        $destructive = $type?->isDestructive() === true;

        // A batch of new tickets is one proposal with a list as its preview,
        // and its button says how many will be created.
        $batch = $type === \App\Enums\AiActionType::CreateTickets;
        $batchTickets = $batch ? array_values(array_filter((array) ($input['tickets'] ?? []), 'is_array')) : [];

        // A file imported into the docs, with its tickets: the page, then the
        // list. The body is the file's own text, converted on the server, so
        // the card says which file and how much is in it rather than showing
        // forty pages of it.
        $import = $type === \App\Enums\AiActionType::ImportDocument;
        $importPage = $import && is_array($input['page'] ?? null) ? $input['page'] : [];
        $importTickets = $import ? array_values(array_filter((array) ($input['tickets'] ?? []), 'is_array')) : [];

        // The file a page is made from, on an import or a plain page request.
        $sourceId = $import ? ($importPage['import_attachment_id'] ?? null) : ($input['import_attachment_id'] ?? null);
        $source = is_int($sourceId) || (is_string($sourceId) && ctype_digit($sourceId))
            ? \App\Models\AiAttachment::query()->visibleTo(auth()->user())->whereKey((int) $sourceId)->first()
            : null;
        $sourceTasks = $source?->markdown === null ? null : preg_match_all('/^\s*- \[ \]\s/m', (string) $source->markdown);

        $confirmLabel = match (true) {
            $batch => 'Create '.count($batchTickets).' '.\Illuminate\Support\Str::plural('ticket', count($batchTickets)),
            $import && $importTickets !== [] => 'Import page and create '.count($importTickets).' '.\Illuminate\Support\Str::plural('ticket', count($importTickets)),
            default => $type?->confirmLabel() ?? 'Confirm',
        };

        // What was left out of this proposal and why — said on the card.
        $notes = array_values(array_filter((array) ($action['notes'] ?? []), 'is_string'));
    @endphp

    <div @class([
        'mt-4 rounded-lg border p-3',
        // Rose for a pending deletion rather than brand: this is the one
        // proposal where the colour should say "stop and read", and amber means
        // warning rather than destruction in this design system.
        'border-rose-300 bg-rose-50' => $state === \App\Models\AiChatMessage::ACTION_PROPOSED && $destructive,
        'border-brand-200 bg-brand-50' => $state === \App\Models\AiChatMessage::ACTION_PROPOSED && ! $destructive,
        'border-emerald-200 bg-emerald-50' => $state === \App\Models\AiChatMessage::ACTION_CONFIRMED,
        'border-slate-200 bg-slate-50' => $state === \App\Models\AiChatMessage::ACTION_DISCARDED,
        'border-rose-200 bg-rose-50' => $state === \App\Models\AiChatMessage::ACTION_FAILED,
    ])>
        <p @class([
            'text-xs font-semibold',
            'text-rose-900' => $destructive && $state === \App\Models\AiChatMessage::ACTION_PROPOSED,
            'text-slate-800' => ! ($destructive && $state === \App\Models\AiChatMessage::ACTION_PROPOSED),
        ])>
            @if ($state === \App\Models\AiChatMessage::ACTION_CONFIRMED)
                {{ $type?->label() ?? 'Action' }}
            @elseif ($batch && $batchTickets !== [])
                Proposed: {{ $confirmLabel }}@if (filled($input['board'] ?? null)) on {{ $input['board'] }}@endif
            @elseif ($import)
                Proposed: import “{{ \Illuminate\Support\Str::limit((string) ($importPage['title'] ?? 'Untitled'), 120) }}” into the
                {{ filled($input['board'] ?? null) ? $input['board'].' docs' : 'docs' }}
                @if ($importTickets !== [])
                    and create {{ count($importTickets) }} {{ \Illuminate\Support\Str::plural('ticket', count($importTickets)) }}
                @endif
            @else
                Proposed: {{ $type?->label() ?? 'unknown action' }}
            @endif
        </p>

        @if ($batch && $batchTickets !== [])
            <x-ai.proposal-tickets :tickets="$batchTickets" />
        @endif

        @if ($import)
            {{-- The page: which file becomes it, and how much is in it. --}}
            <div class="mt-2 rounded-md border border-brand-100 bg-surface px-2.5 py-2 text-xs text-slate-700">
                <p>
                    <span class="font-medium text-slate-800">New page</span>
                    @if (filled($importPage['parent_slug'] ?? null))
                        inside {{ $importPage['parent_slug'] }}
                    @endif
                    &middot; internal until published
                </p>

                @if ($source)
                    <p class="mt-1">
                        Its body is the whole of
                        <span class="inline-flex items-center gap-1 rounded border border-slate-200 bg-surface-sunken px-1.5 py-0.5">
                            <span aria-hidden="true">{{ $source->kind->icon() }}</span>{{ $source->filename() }}
                        </span>
                        @if ($source->summary)
                            ({{ $source->summary }}@if ($sourceTasks), {{ $sourceTasks }} open {{ \Illuminate\Support\Str::plural('to-do', $sourceTasks) }}@endif)
                        @endif
                        &mdash; headings, lists and checkboxes kept, and the file attached.
                    </p>
                @elseif (filled($importPage['import_attachment_id'] ?? null))
                    <p class="mt-1 text-amber-800">The file it names is not on this conversation.</p>
                @endif

                @if (filled($importPage['body_md'] ?? null) && is_string($importPage['body_md']))
                    <details class="mt-1">
                        <summary class="cursor-pointer text-slate-500 hover:text-slate-700">{{ $source ? 'Introduction above it' : 'Body' }}</summary>
                        <p class="mt-1 whitespace-pre-wrap">{{ \Illuminate\Support\Str::limit($importPage['body_md'], 1200) }}</p>
                    </details>
                @endif
            </div>

            @if ($importTickets !== [])
                <x-ai.proposal-tickets :tickets="$importTickets" internal-by-default />
            @endif
        @endif

        {{-- The preview. Everything the model proposed, so what is
             being confirmed is visible before confirming it. A batch and an
             import have their own preview above instead. --}}
        @unless ($batch || $import)
        @php
            // The files a proposal names, looked up so the preview shows
            // pictures and names rather than numbers. Scoped to what this
            // person may see: an id that is not theirs simply does not
            // appear here, and is refused again by ExecuteChatAction.
            $proposedIds = collect((array) ($input['attachment_ids'] ?? []))
                ->filter(fn ($id) => is_int($id) || (is_string($id) && ctype_digit($id)))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            $proposedFiles = $proposedIds->isEmpty()
                ? collect()
                : \App\Models\AiAttachment::query()
                    ->visibleTo(auth()->user())
                    ->whereKey($proposedIds->all())
                    ->with('attachment')
                    ->get();
        @endphp

        <dl class="mt-2 space-y-1 text-xs">
            @if ($proposedIds->isNotEmpty())
                <div>
                    <dt class="font-medium text-slate-600">files</dt>
                    <dd class="mt-1 flex flex-wrap gap-1.5">
                        @foreach ($proposedFiles as $file)
                            @if ($file->attachment?->isImage())
                                <a href="{{ route('attachments.show', $file->attachment) }}" target="_blank" rel="noopener"
                                   class="block overflow-hidden rounded-md border border-slate-200 bg-slate-100"
                                   title="{{ $file->filename() }}">
                                    <img src="{{ route('attachments.show', $file->attachment) }}" alt="{{ $file->filename() }}"
                                         loading="lazy" class="block h-14 w-20 object-cover">
                                </a>
                            @else
                                <span class="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-surface px-2 py-1 text-slate-700">
                                    <span aria-hidden="true">{{ $file->kind->icon() }}</span>
                                    {{ $file->filename() }}
                                </span>
                            @endif
                        @endforeach

                        @if ($proposedFiles->count() < $proposedIds->count())
                            <span class="text-amber-800">
                                {{ $proposedIds->count() - $proposedFiles->count() }} named file(s) are not on this conversation.
                            </span>
                        @endif
                    </dd>
                </div>
            @endif

            {{-- A page made from a file says which file, not its id. --}}
            @if ($source)
                <div>
                    <dt class="font-medium text-slate-600">body</dt>
                    <dd class="text-slate-700">
                        the whole of {{ $source->filename() }}@if ($source->summary) ({{ $source->summary }})@endif, converted, with the file attached
                    </dd>
                </div>
            @endif

            @foreach ($input as $field => $value)
                @continue(in_array($field, ['attachment_ids', 'import_attachment_id'], true))
                @continue($source && $field === 'body_md')

                @php
                    // Arrays are flattened to a readable list rather than
                    // skipped: `labels` arrives as one, and a preview that
                    // silently omitted it would be a preview of a different
                    // change from the one about to be made.
                    $shown = is_array($value)
                        ? implode(', ', array_filter(array_map(
                            fn ($item) => is_scalar($item) ? (string) $item : null,
                            $value,
                        )))
                        : (is_bool($value) ? ($value ? 'yes' : 'no') : (is_scalar($value) ? (string) $value : null));
                @endphp

                @if (filled($shown))
                    <div>
                        <dt class="font-medium text-slate-600">{{ str_replace(['_md', '_'], ['', ' '], (string) $field) }}</dt>
                        <dd class="whitespace-pre-wrap text-slate-700">{{ \Illuminate\Support\Str::limit($shown, 1200) }}</dd>
                    </div>
                @endif
            @endforeach
        </dl>
        @endunless

        @foreach ($notes as $note)
            <p class="mt-2 text-xs text-amber-800">{{ $note }}</p>
        @endforeach

        @if ($state === \App\Models\AiChatMessage::ACTION_PROPOSED)
            @if ($confirming && $confirming->id === $message->id)
                @if ($destructive)
                    {{--
                        The destructive gate.

                        Typing the ticket's own key is the confirmation, because
                        the requirement is that no phrase may serve as one — and
                        a phrase is exactly what a model produces. The server
                        re-checks this when the button is pressed; the box is
                        the courtesy, not the boundary.
                    --}}
                    <p class="mt-3 text-xs font-medium text-rose-900">
                        This permanently deletes the ticket, with its comments and history.
                        It cannot be undone.
                    </p>

                    <label class="mt-2 block text-xs text-rose-900">
                        <span class="font-medium">
                            Type {{ $deletionKey ?? 'the ticket’s key' }} to confirm
                        </span>
                        <input
                            type="text"
                            wire:model="deleteConfirmation"
                            autocomplete="off"
                            spellcheck="false"
                            placeholder="{{ $deletionKey ?? 'e.g. NL-18' }}"
                            class="mt-1 w-40 rounded-md border border-rose-300 bg-white px-2 py-1 font-mono text-xs text-slate-900 placeholder:text-rose-300 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
                        />
                    </label>

                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <x-ui.button type="button" size="sm" variant="danger" wire:click="confirm">
                            {{ $type?->confirmLabel() ?? 'Confirm' }}
                        </x-ui.button>
                        <x-ui.button type="button" size="sm" variant="ghost" wire:click="cancelConfirming">
                            Keep it
                        </x-ui.button>
                    </div>
                @else
                    <p class="mt-3 text-xs font-medium text-brand-900">
                        @if ($batch)
                            Create all {{ count($batchTickets) }} now, as you? If any one of them cannot be
                            created, none are, and you will see which one and why.
                        @elseif ($import)
                            Import the page{{ $importTickets !== [] ? ' and create all '.count($importTickets).' tickets' : '' }}
                            now, as you? If any part cannot be done, nothing is, and you will see why.
                        @else
                            Confirm this change? It will be made now, as you, and recorded in the
                            ticket or page history.
                        @endif
                    </p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <x-ui.button type="button" size="sm" wire:click="confirm"
                                     wire:loading.attr="disabled" wire:target="confirm">
                            {{ $confirmLabel }}
                        </x-ui.button>
                        <x-ui.button type="button" size="sm" variant="ghost" wire:click="cancelConfirming">
                            Not yet
                        </x-ui.button>
                    </div>
                @endif
            @else
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <x-ui.button
                        type="button"
                        size="sm"
                        :variant="$destructive ? 'danger' : 'primary'"
                        wire:click="startConfirming({{ $message->id }})"
                    >
                        {{ $destructive ? 'Review this deletion' : 'Review and confirm' }}
                    </x-ui.button>
                    <x-ui.button
                        type="button"
                        size="sm"
                        variant="ghost"
                        wire:click="discard({{ $message->id }})"
                    >
                        Discard
                    </x-ui.button>
                </div>
            @endif
        @elseif ($state === \App\Models\AiChatMessage::ACTION_CONFIRMED)
            <p class="mt-2 text-xs text-emerald-800">
                {{ $action['result']['label'] ?? 'Done.' }}
                @if (! empty($action['result']['url']))
                    &middot; <a href="{{ $action['result']['url'] }}" wire:navigate class="underline">{{ $batch ? 'Open board' : ($import ? 'Open page' : 'Open') }}</a>
                @endif
            </p>

            {{-- Every ticket a batch created, each one link away. --}}
            @if (is_array($action['result']['items'] ?? null) && $action['result']['items'] !== [])
                <ul class="mt-1.5 flex flex-wrap gap-1.5">
                    @foreach ($action['result']['items'] as $item)
                        @continue(! is_array($item) || empty($item['url']))
                        <li>
                            <a href="{{ $item['url'] }}" wire:navigate title="{{ $item['title'] ?? '' }}"
                               class="inline-flex rounded border border-emerald-200 bg-surface px-1.5 py-0.5 font-mono text-[11px] text-emerald-900 hover:underline">
                                {{ $item['key'] ?? 'Open' }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        @elseif ($state === \App\Models\AiChatMessage::ACTION_DISCARDED)
            <p class="mt-2 text-xs text-slate-500">Discarded. Nothing was changed.</p>
        @elseif ($state === \App\Models\AiChatMessage::ACTION_FAILED)
            <p class="mt-2 text-xs text-rose-800">
                {{ $action['error'] ?? 'That change could not be made.' }}
            </p>
        @endif
    </div>
@endif
