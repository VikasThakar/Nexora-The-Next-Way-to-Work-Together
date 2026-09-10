@props(['run'])

@php
    /**
     * The lifecycle of one AI run, as a timeline.
     *
     * Answers the question the status badge cannot: not "is it running" but
     * "what is it doing, and how far has it got". For an apply run that is
     * Preparing → Analysing → Coding → Testing → Pull request; for a suggest
     * run it is one step, because reading a ticket and writing an opinion is
     * the whole of what a suggest run does and five greyed-out steps would
     * imply otherwise.
     *
     * Everything here is read from the run row. `stage()` prefers the status
     * over the recorded progress, so a worker that died mid-clone shows a
     * failure at the clone rather than a run that claims to still be preparing
     * — see App\Enums\AiRunStage.
     *
     * Staff only, and that is inherited rather than decided here: AiRun rows
     * refuse customers in SQL, and the panel this sits in authorizes on every
     * render.
     */
    $stage = $run->stage();
    $stoppedAt = $run->stoppedAt();
    $pipeline = \App\Enums\AiRunStage::pipelineFor($run->mode);

    // Which step is "now". A failed run marks the step it died on, so the
    // timeline reads as a diagnosis rather than as a row of ticks.
    $currentOrder = $stage === \App\Enums\AiRunStage::Failed
        ? ($stoppedAt?->order() ?? 0)
        : $stage->order();

    $changed = $run->changedFiles();
    $validation = $run->validation();
@endphp

<div class="mt-2.5 rounded-md border border-slate-200 bg-slate-50/60 p-2.5">
    <ol class="flex flex-wrap items-center gap-x-1.5 gap-y-1">
        @foreach ($pipeline as $step)
            @php
                $done = $currentOrder > $step->order()
                    || $stage === \App\Enums\AiRunStage::Finished;

                $active = ! $done
                    && $currentOrder === $step->order()
                    && ! $stage->isTerminal();

                $stopped = $stage === \App\Enums\AiRunStage::Failed
                    && $stoppedAt?->order() === $step->order();
            @endphp

            <li class="flex items-center gap-1.5">
                <span
                    @class([
                        'inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-medium ring-1 ring-inset',
                        'bg-emerald-50 text-emerald-700 ring-emerald-200' => $done && ! $stopped,
                        // The one animated element: the step in flight breathes,
                        // using the same motion as everything else the assistant
                        // does. Static under prefers-reduced-motion.
                        'bg-brand-50 text-brand-700 ring-brand-200' => $active,
                        'bg-rose-50 text-rose-700 ring-rose-200' => $stopped,
                        'bg-slate-100 text-slate-400 ring-slate-200' => ! $done && ! $active && ! $stopped,
                    ])
                    @if ($active) title="{{ $step->description() }}" @endif
                >
                    @if ($active)
                        <span class="nx-thinking-dot" aria-hidden="true"></span>
                    @endif

                    {{ $step->label() }}
                </span>

                @unless ($loop->last)
                    <span class="text-slate-300" aria-hidden="true">›</span>
                @endunless
            </li>
        @endforeach

        {{-- The outcome, at the end of the line rather than as a sixth step:
             finishing is not a stage of the work, it is the absence of more. --}}
        @if ($stage === \App\Enums\AiRunStage::Finished && $run->pull_request_url)
            <li class="flex items-center gap-1.5">
                <span class="text-slate-300" aria-hidden="true">›</span>
                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-medium ring-1 ring-inset bg-emerald-50 text-emerald-700 ring-emerald-200">
                    Pull request opened
                </span>
            </li>
        @endif
    </ol>

    @if ($stage === \App\Enums\AiRunStage::Failed && $stoppedAt !== null)
        <p class="mt-1.5 text-[11px] text-slate-500">
            Stopped while {{ strtolower($stoppedAt->label()) }}. {{ $stoppedAt->description() }}
        </p>
    @elseif (! $stage->isTerminal())
        <p class="mt-1.5 text-[11px] text-slate-500">{{ $stage->description() }}</p>
    @endif

    {{-- ------------------------------------------------------------- --}}
    {{-- What the session actually produced                              --}}
    {{-- ------------------------------------------------------------- --}}

    @if ($run->branch_name || $run->repository)
        <dl class="mt-2 grid gap-x-4 gap-y-1 text-[11px] sm:grid-cols-2">
            @if ($run->repository)
                <div class="flex gap-1">
                    <dt class="text-slate-500">Repository</dt>
                    <dd class="min-w-0 truncate font-mono text-slate-700">{{ $run->repository }}</dd>
                </div>
            @endif

            @if ($run->branch_name)
                <div class="flex gap-1">
                    <dt class="text-slate-500">Branch</dt>
                    <dd class="min-w-0 truncate font-mono text-slate-700">{{ $run->branch_name }}</dd>
                </div>
            @endif
        </dl>
    @endif

    @if ($changed !== [])
        {{-- Collapsed by default. A refactor touches forty files and the count
             is the fact; the list is for the one person who wants it. --}}
        <details class="mt-2">
            <summary class="cursor-pointer text-[11px] text-slate-600 hover:text-slate-900">
                {{ count($changed) }} {{ \Illuminate\Support\Str::plural('file', count($changed)) }} changed
            </summary>

            <ul class="mt-1 max-h-40 space-y-0.5 overflow-y-auto font-mono text-[11px] text-slate-600">
                @foreach (array_slice($changed, 0, 200) as $file)
                    <li class="truncate">{{ $file }}</li>
                @endforeach
            </ul>
        </details>
    @endif

    @if ($validation !== [])
        <div class="mt-2 flex flex-wrap items-center gap-1.5">
            <span class="text-[11px] text-slate-500">Tests</span>

            @foreach ($validation as $check)
                {{-- A failed command is shown, not hidden. Validation runs
                     before the push, so a red command here means no pull
                     request was opened — which is the useful thing to know. --}}
                <span
                    @class([
                        'inline-flex items-center rounded px-1.5 py-0.5 font-mono text-[11px] ring-1 ring-inset',
                        'bg-emerald-50 text-emerald-700 ring-emerald-200' => $check['passed'],
                        'bg-rose-50 text-rose-700 ring-rose-200' => ! $check['passed'],
                    ])
                    title="{{ $check['passed'] ? 'Passed' : 'Failed' }}"
                >
                    {{ \Illuminate\Support\Str::limit($check['command'], 40) }}
                </span>
            @endforeach
        </div>
    @endif
</div>
