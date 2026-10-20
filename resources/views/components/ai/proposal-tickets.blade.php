@props([
    // The proposed tickets, each the fields propose_create_ticket takes.
    'tickets' => [],
    // Whether a ticket that does not say is internal (an import) or follows
    // the board's default (a batch). Decides where the Internal badge goes.
    'internalByDefault' => false,
])

{{--
    Every proposed ticket, in the order it will be numbered, with what was asked
    for it. Descriptions fold away so thirty tickets still read as a list.
    Shared by a batch of tickets and by a file imported with its tickets.
--}}
<ol class="mt-2 max-h-96 list-decimal space-y-2 overflow-y-auto pl-5 text-xs">
    @foreach ($tickets as $entry)
        @php
            $meta = array_filter([
                is_string($entry['type'] ?? null) ? $entry['type'] : null,
                is_string($entry['priority'] ?? null) ? $entry['priority'].' priority' : null,
                is_string($entry['column'] ?? null) ? 'in '.$entry['column'] : null,
                is_string($entry['assignee'] ?? null) ? 'for '.$entry['assignee'] : null,
                is_array($entry['labels'] ?? null) && $entry['labels'] !== []
                    ? implode(', ', array_filter($entry['labels'], 'is_string')) : null,
                is_string($entry['due_date'] ?? null) ? 'due '.$entry['due_date'] : null,
                is_numeric($entry['estimate'] ?? null) ? 'estimate '.(0 + $entry['estimate']) : null,
                is_array($entry['attachment_ids'] ?? null) && $entry['attachment_ids'] !== []
                    ? count($entry['attachment_ids']).' '.\Illuminate\Support\Str::plural('file', count($entry['attachment_ids'])) : null,
            ]);

            $internal = $internalByDefault
                ? ($entry['customer_visible'] ?? false) !== true
                : ($entry['customer_visible'] ?? true) === false;
        @endphp

        <li class="text-slate-700">
            <span class="font-medium text-slate-800">{{ \Illuminate\Support\Str::limit((string) ($entry['title'] ?? 'Untitled'), 200) }}</span>
            @if ($internal)
                <x-ui.internal-badge />
            @endif

            @if ($meta !== [])
                <span class="block text-slate-500">{{ implode(' · ', $meta) }}</span>
            @endif

            @if (filled($entry['description_md'] ?? null) && is_string($entry['description_md']))
                <details class="mt-0.5">
                    <summary class="cursor-pointer text-slate-500 hover:text-slate-700">Description</summary>
                    <p class="mt-1 whitespace-pre-wrap">{{ \Illuminate\Support\Str::limit($entry['description_md'], 1200) }}</p>
                </details>
            @endif
        </li>
    @endforeach
</ol>
