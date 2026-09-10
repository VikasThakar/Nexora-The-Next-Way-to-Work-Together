@props([
    // App\Models\AiChatMessage
    'message',
    // list<App\Support\RichResponse\RichBlock>|null — present only for an
    // answer that actually contains a table or a chart.
    'blocks' => null,
    // Pre-rendered HTML for everything else.
    'html' => null,
    /*
     * Which chart type each chart block is currently shown as.
     *
     * Keyed "<message id>.<block index>", supplied by the host component from
     * its own state — see App\Livewire\Ai\Concerns\TalksToWorkspaceAi. Empty
     * means "as the model asked for it", which is every chart until somebody
     * presses a Change View button.
     */
    'chartViews' => [],
])

{{--
    One turn's body.

    Two paths, and which one is taken was decided in PHP by
    RichResponseParser::looksRich(): an ordinary answer is one string of
    already-sanitised HTML, exactly as it has always been, and an answer
    carrying a table or a chart is a list of blocks rendered in the order the
    model wrote them.

    That ordering is the requirement — text, then a chart, then the explanation
    — and it comes out of the parser for free, because the parser walks the
    answer rather than pulling structures out of it.

    Both paths echo prose unescaped, and both are safe for the same reason:
    every string came from App\Services\ContentRenderer, which is the one
    sanitising Markdown pipeline in the product. Nothing else in this file
    prints model output except through a component that escapes it.
--}}
@if (is_array($blocks) && $blocks !== [])
    <div class="space-y-1">
        @foreach ($blocks as $block)
            @if ($block->isTable())
                <x-ai.rich-table :table="$block->table" :message="$message" :index="$block->index" />
            @elseif ($block->isChart())
                <x-ai.rich-chart
                    :chart="$block->chart"
                    :message="$message"
                    :index="$block->index"
                    :view="$chartViews[$message->getKey().'.'.$block->index] ?? null"
                />
            @elseif ($block->isKpi())
                <x-ai.rich-kpi :kpi="$block->kpi" :message="$message" :index="$block->index" />
            @else
                <div class="markdown text-sm">{!! $block->html !!}</div>
            @endif
        @endforeach
    </div>
@else
    <div class="markdown text-sm">{!! $html ?? e($message->content) !!}</div>
@endif
