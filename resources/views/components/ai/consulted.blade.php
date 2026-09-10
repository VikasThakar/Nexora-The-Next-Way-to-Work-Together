@props(['message'])

@php
    /**
     * What the assistant looked up to answer this turn.
     *
     * The provenance line, and the reason it exists is trust: an assistant that
     * says "AQD-42 is blocked on the VAT migration" is worth much more when the
     * reader can see that it actually opened AQD-42 rather than inferring it
     * from a title. It is also the fastest way to spot the failure that matters
     * — an answer built from nothing.
     *
     * Read from the turn's own metadata, written by
     * App\Services\AI\WorkspaceChatService from the loop's record. Only the tool
     * name and the subject are there; the material is not, because the material
     * is a copy of internal content and the answer above already carries
     * whatever part of it mattered.
     *
     * Nothing here is a link. A key like AQD-42 in the answer itself is already
     * linked per viewer by ContentRenderer, which is the layer that knows
     * whether this reader may open it; repeating that decision here would be a
     * second place to get it wrong.
     */
    $tools = (array) (($message->metadata['tools'] ?? [])['invocations'] ?? []);

    // Deduplicated on the pair, because two lookups of the same subject read as
    // a bug in this line rather than as the two rounds it actually was.
    $seen = [];
    $entries = [];

    foreach ($tools as $tool) {
        if (! is_array($tool)) {
            continue;
        }

        $name = (string) ($tool['tool'] ?? '');
        $target = $tool['target'] ?? null;

        if ($name === '') {
            continue;
        }

        $key = $name.'|'.(string) $target;

        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;

        $entries[] = [
            'label' => str_replace('_', ' ', $name),
            'target' => is_scalar($target) ? (string) $target : null,
            'ok' => (bool) ($tool['success'] ?? false),
        ];
    }
@endphp

@if ($entries !== [])
    <div class="mt-2 flex flex-wrap items-center gap-1.5">
        <span class="text-[11px] text-slate-400">Checked</span>

        @foreach (array_slice($entries, 0, 8) as $entry)
            {{-- A lookup that came back empty is shown as such rather than
                 hidden. "Searched documentation — nothing" is the most useful
                 line on the whole answer when the answer is "I don't know". --}}
            <span
                @class([
                    'inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] ring-1 ring-inset',
                    'bg-slate-50 text-slate-600 ring-slate-200' => $entry['ok'],
                    'bg-slate-50 text-slate-400 ring-slate-200' => ! $entry['ok'],
                ])
                @if (! $entry['ok']) title="This lookup returned nothing." @endif
            >
                {{ $entry['label'] }}@if ($entry['target'])<span class="font-medium">{{ $entry['target'] }}</span>@endif
            </span>
        @endforeach

        @if (count($entries) > 8)
            <span class="text-[11px] text-slate-400">and {{ count($entries) - 8 }} more</span>
        @endif
    </div>
@endif
