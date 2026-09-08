@props([
    /**
     * Dataset name from the registry behind $route — App\Services\Statistics\
     * StatisticsExport::KEYS for the team report, CustomerStatisticsExport::KEYS
     * for the customer one.
     */
    'dataset',

    /**
     * The route that serves the CSV.
     *
     * Two routes rather than one with a role branch inside it, because the two
     * reports are two different registries behind two different gates — see
     * App\Http\Controllers\CustomerStatisticsExportController for why the
     * customer's download cannot reach the team's figures even by name.
     */
    'route' => 'stats.export',

    /** The current filters, so the download describes the same period. */
    'filters' => [],

    /**
     * Whether the wrapped content can be serialised as a picture.
     *
     * True for everything that renders as an SVG, which is now every chart in
     * resources/views/components/charts/. It stays a prop because three of the
     * things worth exporting on the statistics screens are not charts at all —
     * the time-in-column table, the AI-per-board table and the AI tokens-and-
     * cost list. Each has numbers worth downloading and no picture to take.
     */
    'image' => true,

    /** Basename for the downloaded picture. The CSV is named by the server. */
    'filename' => 'nexora-chart',
])

{{--
    A chart with its exports.

    Three formats, from two places, and the split is on purpose:

      PNG, SVG   the rendered chart, serialised in the browser from the SVG
                 already on the page. There is nothing for the server to do,
                 and nothing extra to authorize — the picture can only contain
                 what was drawn.
      CSV        a request to one of the two export controllers, which
                 re-derives the figures under the viewer's own scope. Neither
                 receives numbers from the page, so a tampered payload cannot
                 put figures into a file that were not in the report.

    The buttons appear on hover and on keyboard focus. They are controls for a
    thing somebody has decided to keep, not part of reading the chart.

    `bottom-full` rather than a negative top offset: it puts the row entirely
    above the chart, inside the padding x-ui.card already has, so revealing the
    toolbar never covers the thing it belongs to. A chart's first row and a
    table's first column heading both sit at the very top of that box, and an
    earlier `-top-1` hid them for as long as the pointer was over the card.
--}}
<div x-data="chartExport" class="group/export relative">
    <div class="absolute right-0 bottom-full z-10 flex items-center gap-1 opacity-0 transition group-hover/export:opacity-100 focus-within:opacity-100">
        @if ($image)
            <button
                type="button"
                x-on:click="downloadPng(@js($filename))"
                x-bind:disabled="exporting"
                class="rounded border border-slate-200 bg-surface px-1.5 py-0.5 text-[10px] font-medium text-slate-500 shadow-xs transition hover:bg-slate-50 hover:text-slate-700 disabled:opacity-50"
            >PNG</button>

            <button
                type="button"
                x-on:click="downloadSvg(@js($filename))"
                class="rounded border border-slate-200 bg-surface px-1.5 py-0.5 text-[10px] font-medium text-slate-500 shadow-xs transition hover:bg-slate-50 hover:text-slate-700"
            >SVG</button>
        @endif

        {{--
            A plain link, not fetch(): the browser's own download handling gets
            the filename from Content-Disposition and the session cookie from
            the request it already knows how to make.
        --}}
        <a
            href="{{ route($route, array_merge(['dataset' => $dataset], $filters)) }}"
            class="rounded border border-slate-200 bg-surface px-1.5 py-0.5 text-[10px] font-medium text-slate-500 shadow-xs transition hover:bg-slate-50 hover:text-slate-700"
        >CSV</a>
    </div>

    {{ $slot }}

    <p x-show="exportError" x-cloak x-text="exportError" class="mt-2 text-xs text-rose-600" role="alert"></p>
</div>
