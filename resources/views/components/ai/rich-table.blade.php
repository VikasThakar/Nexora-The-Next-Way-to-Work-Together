@props([
    // App\Support\RichResponse\TableSpec
    'table',
    // The stored turn, for the export link. Null suppresses export — a table
    // in a streaming answer has no message to address yet.
    'message' => null,
    'index' => 0,
])

{{--
    A table from an assistant answer, as a real table.

    Everything here is a string from App\Support\RichResponse\TableSpec, which
    coerced every cell before it got this far, and every one is echoed with
    Blade's escaping. There is no path from a model's output to markup.

    Horizontal scrolling is on the wrapper rather than the page: a table of
    fourteen columns must scroll inside its own box and never make the whole
    transcript scroll sideways. `whitespace-nowrap` on cells is what makes that
    scroll meaningful instead of producing forty rows of wrapped text.

    Copy puts TSV on the clipboard, because the destination is a spreadsheet and
    a spreadsheet splits a paste on tabs. Export re-derives the CSV server-side
    from the stored answer, so the file is the table — see
    AiBlockExportController for why it is addressed by index rather than posted.
--}}
<div class="my-3 overflow-hidden rounded-xl border border-slate-200 bg-surface">
    @if ($table->title)
        <div class="border-b border-slate-200 px-4 py-2.5">
            <p class="text-sm font-semibold text-slate-800">{{ $table->title }}</p>
        </div>
    @endif

    <div class="overflow-x-auto">
        <table class="w-full min-w-full border-collapse text-sm">
            <thead>
                <tr class="border-b border-slate-200 bg-slate-50">
                    @foreach ($table->columns as $column)
                        <th
                            scope="col"
                            class="whitespace-nowrap px-3 py-2 text-left text-xs font-semibold tracking-wide text-slate-600 uppercase"
                        >
                            {{ $column }}
                        </th>
                    @endforeach
                </tr>
            </thead>

            <tbody>
                @foreach ($table->rows as $row)
                    <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50/70">
                        @foreach ($row as $cell)
                            <td class="whitespace-nowrap px-3 py-2 text-slate-700">{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="flex flex-wrap items-center gap-3 border-t border-slate-200 bg-slate-50/70 px-4 py-2 text-xs">
        <span class="text-slate-500">
            {{ number_format($table->rowCount()) }} {{ Str::plural('row', $table->rowCount()) }} ·
            {{ $table->columnCount() }} {{ Str::plural('column', $table->columnCount()) }}
        </span>

        @if ($table->caption)
            <span class="text-slate-500">{{ $table->caption }}</span>
        @endif

        <span class="ml-auto flex items-center gap-3">
            {{--
                Copy, from a data attribute rather than by scraping the DOM.

                Reading the rendered cells back out would re-introduce every
                whitespace and truncation decision the CSS made; the attribute
                carries exactly what the spec holds. `navigator.clipboard` is
                unavailable on an insecure origin, so the fallback path keeps
                copy working in local development over plain HTTP.
            --}}
            <button
                type="button"
                x-data="{ copied: false }"
                data-table="{{ $table->toClipboardText() }}"
                x-on:click="
                    const text = $el.dataset.table;
                    const done = () => { copied = true; setTimeout(() => copied = false, 1600); };
                    if (navigator.clipboard?.writeText) {
                        navigator.clipboard.writeText(text).then(done).catch(() => {});
                    } else {
                        const area = document.createElement('textarea');
                        area.value = text;
                        area.setAttribute('readonly', '');
                        area.style.position = 'fixed';
                        area.style.opacity = '0';
                        document.body.appendChild(area);
                        area.select();
                        try { document.execCommand('copy'); done(); } catch (e) {}
                        area.remove();
                    }
                "
                class="font-medium text-slate-500 transition hover:text-slate-800"
            >
                <span x-show="! copied">Copy</span>
                <span x-show="copied" x-cloak class="text-emerald-600">Copied</span>
            </button>

            @if ($message?->exists)
                <a
                    href="{{ route('ai.blocks.export', ['message' => $message->getKey(), 'block' => $index]) }}"
                    class="font-medium text-brand-700 transition hover:text-brand-800"
                >
                    Export CSV
                </a>
            @endif
        </span>
    </div>
</div>
