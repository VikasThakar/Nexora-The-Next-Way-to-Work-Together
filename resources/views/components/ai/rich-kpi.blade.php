@props([
    // App\Support\RichResponse\KpiSpec
    'kpi',
    'message' => null,
    'index' => 0,
])

{{--
    Headline figures from an assistant answer.

    The right rendering for three or four unrelated totals, which a bar chart
    misrepresents — see App\Support\RichResponse\KpiSpec for why.

    No dark-mode variant here and none needed: every colour is a neutral token,
    and resources/css/app.css remaps the whole neutral scale under `html.dark`.
    That is the product's one dark-mode mechanism, so a card added here is
    themed without a single `dark:` class. The charts are the exception, because
    their colours live inside an SVG that CSS custom properties cannot reach.

    Values are escaped as text nodes. Nothing model-authored becomes markup.
--}}
<div class="my-3 overflow-hidden rounded-xl border border-slate-200 bg-surface">
    @if ($kpi->title || $kpi->source)
        <div class="border-b border-slate-200 px-4 pb-2.5 pt-3">
            @if ($kpi->title)
                <p class="text-sm font-semibold text-slate-800">{{ $kpi->title }}</p>
            @endif
            @if ($kpi->source)
                <p class="mt-0.5 text-xs text-slate-500">{{ $kpi->source }}</p>
            @endif
        </div>
    @endif

    {{--
        Two columns on a phone, then as many as there are cards.

        Capped at four rather than six so a five-card row does not become five
        unreadably narrow columns on a laptop; the fifth wraps, which is the
        correct behaviour for a stat row.
    --}}
    <dl @class([
        'grid gap-px bg-slate-200',
        'grid-cols-1' => $kpi->cardCount() === 1,
        'grid-cols-2' => $kpi->cardCount() === 2,
        'grid-cols-2 sm:grid-cols-3' => $kpi->cardCount() === 3,
        'grid-cols-2 sm:grid-cols-4' => $kpi->cardCount() >= 4,
    ])>
        @foreach ($kpi->cards as $card)
            <div class="bg-surface px-4 py-3">
                <dt class="truncate text-xs font-medium uppercase tracking-wide text-slate-500" title="{{ $card['label'] }}">
                    {{ $card['label'] }}
                </dt>
                <dd class="mt-1 text-2xl font-semibold tabular-nums leading-tight text-slate-900">
                    {{ $card['value'] }}
                </dd>
                @if ($card['caption'])
                    <p class="mt-0.5 text-xs text-slate-500">{{ $card['caption'] }}</p>
                @endif
            </div>
        @endforeach
    </dl>

    @if ($message?->exists)
        <div class="flex items-center justify-end border-t border-slate-200 bg-slate-50/70 px-4 py-2 text-xs">
            <a
                href="{{ route('ai.blocks.export', ['message' => $message->getKey(), 'block' => $index]) }}"
                class="font-medium text-brand-700 transition hover:text-brand-800"
            >
                Export CSV
            </a>
        </div>
    @endif
</div>
