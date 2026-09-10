@props([
    // App\Support\RichResponse\ChartSpec
    'chart',
    'message' => null,
    'index' => 0,
    // The type somebody switched to with Change View, or null for the
    // model's own choice.
    'view' => null,
])

@php
    /*
     * Change View, applied here.
     *
     * `withType()` re-runs the same validation `fromArray()` did and returns
     * the spec unchanged for a type this data cannot honestly be drawn as, so a
     * rewritten Livewire payload cannot produce a misleading picture — it just
     * does nothing. See App\Support\RichResponse\ChartSpec::withType().
     */
    $chart = is_string($view) && $view !== '' ? $chart->withType($view) : $chart;

    $renderer = app(\App\Support\RichResponse\ChartRenderer::class);

    $light = $renderer->render($chart, false);
    $dark = $renderer->render($chart, true);

    $table = $chart->toTable();
    $supported = $chart->supportedTypes();
    $refusal = $chart->circularRefusal();

    /*
     * The order the buttons appear in.
     *
     * Fixed, so the toolbar does not reshuffle when a dataset changes shape —
     * a control that moves under the cursor is worse than one that is
     * occasionally greyed out.
     *
     * Scatter is not offered as a switch (these are counts, and a scatter plot
     * of counts against category index says nothing), but it IS added when the
     * model chose it, so the current view always has a button showing it as
     * current rather than a toolbar with nothing selected.
     */
    $offered = ['bar', 'hbar', 'line', 'area', 'pie', 'donut'];

    if (! in_array($chart->type, $offered, true)) {
        $offered[] = $chart->type;
    }

    $offered = array_values(array_intersect($offered, \App\Support\RichResponse\ChartSpec::TYPES));

    $names = [
        'bar' => 'Bar', 'hbar' => 'Bars', 'line' => 'Line',
        'area' => 'Area', 'pie' => 'Pie', 'donut' => 'Donut', 'scatter' => 'Scatter',
    ];

    /*
     * A filename somebody can find again: what it shows, where it came from,
     * and when it was taken — "nl-tickets-by-status-2026-09-10.png".
     *
     * The board prefix is read only when the relation is already loaded. This
     * is a view, and a view that lazy-loads is a view that adds a query per
     * chart to a transcript (and throws outright under
     * preventLazyLoading, which this application turns on). A missing prefix
     * costs the filename a few characters; a query in a loop costs the page.
     */
    $prefix = $message?->relationLoaded('board')
        ? (string) ($message->getRelation('board')?->ticket_prefix ?? '')
        : '';

    $filename = \Illuminate\Support\Str::slug(
        trim($prefix.' '.($chart->title ?? 'chart').' '.now()->toDateString())
    ).'.png';

    $switchable = $message?->exists;
@endphp

{{--
    A chart from an assistant answer.

    The SVG is generated server-side by App\Support\RichResponse\ChartRenderer
    from a validated ChartSpec — numbers and labels, nothing else. That is the
    security claim of the whole feature: there is no charting library taking
    model-authored configuration, so there is no option in such a library that
    turns a model's string into a callback, and nothing model-authored reaches a
    script, an event handler or a URL.

    Echoed unescaped because this application generated every character of it,
    the same way it echoes ContentRenderer's output unescaped. Labels inside it
    were XML-escaped by the renderer.

    Why the chart is rendered twice
    -------------------------------
    Once for each appearance, with CSS showing one. The rest of the product
    needs no `dark:` variants at all, because app.css remaps the neutral scale —
    but that mechanism works on CSS custom properties, and the colours inside an
    SVG are `fill` attributes it cannot reach. Restyling them from the page's
    stylesheet would work on screen and break the export, because the PNG is
    made by serialising the SVG, at which point no page CSS applies. Two
    self-contained pictures is the version where what you export is what you
    see. A chart SVG is a few kilobytes, so the duplication is cheap.

    The export always takes the light one, whatever the viewer is using: a chart
    going into a document or a deck wants a white ground.

    Three exports, and they are different things
    -------------------------------------------
    "PNG" rasterises the light SVG in the browser via a canvas at 3× the
    viewBox, so the title, the axis labels, the legend and the tooltipped values
    are all preserved — it is the picture, redrawn larger, not a screenshot.
    Server-side rasterisation would mean an image library this deployment needs
    for nothing else.

    "Export CSV" is the numbers, re-derived server-side from the stored answer,
    so the file is exactly what the picture was drawn from.

    "Data" opens the same figures inline, because a chart is a picture:
    somebody using a screen reader, or anybody who wants the values rather than
    the shape, should not have to download a file to read them.
--}}
<div
    class="my-3 overflow-hidden rounded-xl border border-slate-200 bg-surface"
    x-data="{ showData: false }"
    wire:key="chart-{{ $message?->getKey() ?? 'x' }}-{{ $index }}-{{ $chart->type }}"
>
    <div class="px-3 pt-3">
        {{-- Light. Also the one the export rasterises. --}}
        <div x-ref="light" class="block dark:hidden">{!! $light !!}</div>
        {{-- Dark. Hidden from assistive technology: it is the same picture,
             and the light one already carries the aria-label. --}}
        <div class="hidden dark:block" aria-hidden="true">{!! $dark !!}</div>
    </div>

    {{-- Change View. A round trip, so the server re-renders from the same
         validated numbers rather than the browser redrawing them. --}}
    @if ($switchable && count($offered) > 1)
        <div class="flex flex-wrap items-center gap-1.5 border-t border-slate-200 px-4 py-2">
            <span class="mr-1 text-xs font-medium text-slate-500">View</span>

            @foreach ($offered as $type)
                @php
                    $active = $chart->type === $type;
                    $allowed = in_array($type, $supported, true);
                @endphp

                <button
                    type="button"
                    @if ($allowed)
                        wire:click="useChartView({{ $message->getKey() }}, {{ $index }}, '{{ $type }}')"
                    @else
                        disabled
                        title="{{ $refusal ?? 'This view does not suit these numbers.' }}"
                    @endif
                    @class([
                        'rounded-md px-2 py-1 text-xs font-medium transition',
                        'bg-brand-solid text-white' => $active,
                        'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! $active && $allowed,
                        'cursor-not-allowed text-slate-300' => ! $allowed,
                    ])
                    aria-pressed="{{ $active ? 'true' : 'false' }}"
                >
                    {{ $names[$type] ?? ucfirst($type) }}
                </button>
            @endforeach
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-3 border-t border-slate-200 bg-slate-50/70 px-4 py-2 text-xs">
        <span class="text-slate-500">
            {{ ucfirst($chart->type === 'hbar' ? 'horizontal bar' : $chart->type) }} chart ·
            {{ number_format(count($chart->labels)) }} {{ Str::plural('point', count($chart->labels)) }}
            @if ($chart->seriesCount() > 1)
                · {{ $chart->seriesCount() }} series
            @endif
        </span>

        <span class="ml-auto flex items-center gap-3">
            <button
                type="button"
                x-on:click="showData = ! showData"
                class="font-medium text-slate-500 transition hover:text-slate-800"
                x-bind:aria-expanded="showData ? 'true' : 'false'"
            >
                <span x-show="! showData">View data</span>
                <span x-show="showData" x-cloak>Hide data</span>
            </button>

            {{--
                SVG to PNG through a canvas.

                Serialised, encoded as a data URL, and drawn at 3× the viewBox
                so the result is usable in a document or on a projector. The
                canvas is sized from the SVG's own 720×360 viewBox rather than
                from its rendered box, which is what stops a chart in a narrow
                drawer exporting as a squashed one — the picture's proportions
                are a property of the chart, not of the panel it happens to be
                sitting in.

                `encodeURIComponent` rather than btoa: labels can contain
                non-Latin characters and btoa throws on those.

                The white ground is deliberate even for a viewer in dark mode.
                This file goes into a report.
            --}}
            <button
                type="button"
                x-on:click="
                    const svg = $refs.light.querySelector('svg');
                    if (! svg) return;
                    const box = svg.viewBox.baseVal;
                    const width = box && box.width ? box.width : 720;
                    const height = box && box.height ? box.height : 360;
                    const scale = 3;
                    const serialised = new XMLSerializer().serializeToString(svg);
                    const image = new Image();
                    image.onload = () => {
                        const canvas = document.createElement('canvas');
                        canvas.width = Math.round(width * scale);
                        canvas.height = Math.round(height * scale);
                        const context = canvas.getContext('2d');
                        context.fillStyle = '#ffffff';
                        context.fillRect(0, 0, canvas.width, canvas.height);
                        context.drawImage(image, 0, 0, canvas.width, canvas.height);
                        const link = document.createElement('a');
                        link.download = {{ Illuminate\Support\Js::from($filename) }};
                        link.href = canvas.toDataURL('image/png');
                        link.click();
                    };
                    image.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(serialised);
                "
                class="font-medium text-slate-500 transition hover:text-slate-800"
            >
                Download PNG
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

    {{-- The figures behind the picture. --}}
    <div x-show="showData" x-cloak class="border-t border-slate-200">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50">
                        @foreach ($table->columns as $column)
                            <th scope="col" class="whitespace-nowrap px-3 py-2 text-left text-xs font-semibold text-slate-600">
                                {{ $column }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($table->rows as $row)
                        <tr class="border-b border-slate-100 last:border-0">
                            @foreach ($row as $cell)
                                <td class="whitespace-nowrap px-3 py-2 tabular-nums text-slate-700">{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
