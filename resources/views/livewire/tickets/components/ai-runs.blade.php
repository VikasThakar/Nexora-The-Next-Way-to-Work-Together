{{--
    The AI panel on a ticket.

    Rendered only for staff: the component authorizes on mount AND on every
    render, and AiRun::visibleTo() returns nothing for a customer anyway. The
    amber styling is the same visual language as the internal notes tab — if it
    is amber in this product, the customer cannot see it.

    Polling runs only while something is queued or running, and stops by itself.
--}}
<div
    @if ($hasActive) wire:poll.5s @endif
    class="overflow-hidden rounded-xl border border-amber-200 bg-amber-50/40 shadow-xs"
>
    <header class="flex flex-wrap items-start justify-between gap-3 border-b border-amber-200 bg-amber-50 px-5 py-4">
        <div class="min-w-0">
            <h2 class="flex items-center gap-2 text-sm font-semibold text-amber-900">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" />
                </svg>
                AI automation
            </h2>
            <p class="mt-0.5 text-xs text-amber-700">
                Internal only. Nothing here is visible to the customer.
            </p>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            @if ($canConfigure)
                <x-ui.button
                    :href="route('boards.ai-settings', $ticket->board)"
                    variant="secondary"
                    size="sm"
                >
                    Settings
                </x-ui.button>
            @endif
        </div>
    </header>

    <div class="space-y-5 px-5 py-5">
        {{-- Shown here as well as in the layout: a Livewire action that flashes
             without redirecting re-renders only this component, so the layout's
             banner would not appear until the next full page load. --}}
        @if (session('status'))
            <p class="rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-800 ring-1 ring-inset ring-emerald-200">
                {{ session('status') }}
            </p>
        @endif

        @if (session('error'))
            <p class="rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-800 ring-1 ring-inset ring-rose-200">
                {{ session('error') }}
            </p>
        @endif

        {{-- ------------------------------------------------------------- --}}
        {{-- Starting a run                                                  --}}
        {{-- ------------------------------------------------------------- --}}
        @if (! $providerConfigured)
            <p class="text-xs text-amber-800">
                No AI provider is configured for this deployment, so no run can be started.
                Set <code class="font-mono">ANTHROPIC_API_KEY</code> on the web and worker services.
            </p>
        @elseif ($confirmingApply)
            <div class="rounded-lg border border-rose-200 bg-rose-50 p-4">
                <p class="text-sm font-medium text-rose-900">Start an apply run?</p>
                <p class="mt-1 text-xs text-rose-800">
                    Claude will work in an isolated clone of the repository selected for this
                    board, create a branch and open a <strong>draft</strong> pull request. It never pushes to a
                    protected branch and never merges &mdash; a person still reviews everything.
                </p>

                <div class="mt-3 flex items-center gap-2">
                    <x-ui.button type="button" size="sm" variant="danger" wire:click="run">
                        Yes, open a pull request
                    </x-ui.button>
                    <x-ui.button type="button" size="sm" variant="ghost" wire:click="cancelApply">
                        Cancel
                    </x-ui.button>
                </div>
            </div>
        @else
            <form wire:submit="run" class="flex flex-wrap items-end gap-2">
                <x-ui.field label="Run mode" for="ai-mode" class="min-w-48 flex-1">
                    <x-ui.select id="ai-mode" wire:model="mode">
                        @foreach ($modes as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.button type="submit" size="md" :disabled="$refusal !== null">
                    <span wire:loading.remove wire:target="run">Run</span>
                    <span wire:loading wire:target="run">Queueing…</span>
                </x-ui.button>
            </form>

            @if ($refusal)
                <p class="text-xs text-amber-800">{{ $refusal->getMessage() }}</p>
            @endif
        @endif

        {{-- ------------------------------------------------------------- --}}
        {{-- Automation state and caps                                       --}}
        {{-- ------------------------------------------------------------- --}}
        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 border-t border-amber-200 pt-4 text-xs sm:grid-cols-3">
            <div>
                <dt class="text-amber-700">Automatic runs</dt>
                <dd class="font-medium text-amber-900">
                    @if ($settings->automaticMode())
                        {{ $settings->automaticMode()->label() }}
                    @else
                        Off
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-amber-700">Automatic today</dt>
                <dd class="font-medium text-amber-900">{{ $autoUsedToday }} / {{ $autoLimit }}</dd>
            </div>
            <div>
                <dt class="text-amber-700">Manual today</dt>
                <dd class="font-medium text-amber-900">{{ $manualUsedToday }} / {{ $manualLimit }}</dd>
            </div>
        </dl>

        {{-- ------------------------------------------------------------- --}}
        {{-- History                                                         --}}
        {{-- ------------------------------------------------------------- --}}
        <div class="border-t border-amber-200 pt-4">
            @if ($runs->isEmpty())
                <p class="text-xs text-amber-700">No AI run has been started on this ticket.</p>
            @else
                <ul class="space-y-3">
                    @foreach ($runs as $run)
                        <li wire:key="ai-run-{{ $run->id }}" class="rounded-lg border border-amber-200 bg-white p-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-ui.badge :variant="$run->status->badge()">{{ $run->status->label() }}</x-ui.badge>
                                <span class="text-xs font-medium text-slate-700">{{ $run->mode->label() }}</span>
                                <span class="text-xs text-slate-500">
                                    {{ $run->trigger_source->label() }}
                                    @if ($run->triggeredBy)
                                        &middot; {{ $run->triggeredBy->name }}
                                    @endif
                                </span>
                                <span class="ml-auto text-xs text-slate-400">
                                    {{ $run->created_at?->diffForHumans() }}
                                </span>
                            </div>

                            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                                @if ($run->repository)
                                    <span class="font-mono">{{ $run->repository }}</span>
                                @endif

                                @if ($run->model)
                                    <span>{{ $run->model }}</span>
                                @endif

                                {{-- Tokens and cost are null when the provider did not report
                                     them. Rendered as "not reported" rather than as zero: an
                                     invented figure in a cost total is worse than a blank. --}}
                                <span>
                                    Tokens:
                                    @if ($run->totalTokens() === null)
                                        not reported
                                    @else
                                        {{ number_format((int) $run->tokens_input) }} in /
                                        {{ number_format((int) $run->tokens_output) }} out
                                    @endif
                                </span>

                                <span>Cost: {{ $run->formattedCost() ?? 'not reported' }}</span>

                                @if ($run->duration())
                                    <span>Took {{ $run->duration() }}</span>
                                @endif
                            </div>

                            @if ($run->pull_request_url)
                                <p class="mt-2 text-xs">
                                    <a
                                        href="{{ $run->pull_request_url }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="font-medium text-brand-700 underline hover:text-brand-800"
                                    >
                                        Draft pull request &rarr;
                                    </a>
                                    <span class="text-slate-500">on branch <span class="font-mono">{{ $run->branch_name }}</span></span>
                                </p>
                            @endif

                            @if ($run->error_message)
                                <p class="mt-2 rounded bg-rose-50 px-2 py-1.5 text-xs text-rose-800">
                                    {{ \Illuminate\Support\Str::limit($run->error_message, 300) }}
                                </p>
                            @endif

                            @if ($run->result_comment_id)
                                <p class="mt-2 text-xs text-slate-500">
                                    The result was posted as an internal note above.
                                </p>
                            @endif

                            @if ($run->status->isCancellable())
                                <div class="mt-2">
                                    <x-ui.button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        wire:click="cancel({{ $run->id }})"
                                    >
                                        Cancel run
                                    </x-ui.button>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
