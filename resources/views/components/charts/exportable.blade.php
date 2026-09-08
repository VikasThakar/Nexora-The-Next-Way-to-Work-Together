@props([
    /** Dataset name from App\Services\Statistics\StatisticsExport::KEYS. */
    'dataset',

    /** The current filters, so the download describes the same period. */
    'filters' => [],

    /**
     * Whether the wrapped chart is an SVG, and so whether a picture of it can
     * be downloaded.
     *
     * False for the bar, column and donut charts, which are divs with heights —
     * a deliberate choice made before this wrapper existed, and not one worth
     * undoing to add an image button. Those still offer their numbers as CSV,
     * which is the part somebody actually re-uses.
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
      CSV        a request to App\Http\Controllers\StatisticsExportController,
                 which re-derives the figures under the viewer's own scope. It
                 does not receive numbers from the page, so a tampered payload
                 cannot put figures into a file that were not in the report.

    The buttons appear on hover and on keyboard focus. They are controls for a
    thing somebody has decided to keep, not part of reading the chart.
--}}
<div x-data="chartExport" class="group/export relative">
    <div class="absolute -top-1 right-0 z-10 flex items-center gap-1 opacity-0 transition group-hover/export:opacity-100 focus-within:opacity-100">
        @if ($image)
            <button
                type="button"
                x-on:click="downloadPng(@js($filename))"
                x-bind:disabled="exporting"
                class="rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[10px] font-medium text-slate-500 shadow-xs transition hover:bg-slate-50 hover:text-slate-700 disabled:opacity-50"
            >PNG</button>

            <button
                type="button"
                x-on:click="downloadSvg(@js($filename))"
                class="rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[10px] font-medium text-slate-500 shadow-xs transition hover:bg-slate-50 hover:text-slate-700"
            >SVG</button>
        @endif

        {{--
            A plain link, not fetch(): the browser's own download handling gets
            the filename from Content-Disposition and the session cookie from
            the request it already knows how to make.
        --}}
        <a
            href="{{ route('stats.export', array_merge(['dataset' => $dataset], $filters)) }}"
            class="rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[10px] font-medium text-slate-500 shadow-xs transition hover:bg-slate-50 hover:text-slate-700"
        >CSV</a>
    </div>

    {{ $slot }}

    <p x-show="exportError" x-cloak x-text="exportError" class="mt-2 text-xs text-rose-600" role="alert"></p>
</div>
