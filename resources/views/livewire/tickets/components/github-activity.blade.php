<div>
    <x-ui.card
        title="GitHub"
        :description="$links->isEmpty() ? null : $links->count().' linked '.\Illuminate\Support\Str::plural('item', $links->count())"
    >
        @if ($links->isEmpty())
            <p class="text-sm text-slate-400">
                Nothing linked yet.
                @if ($configured)
                    Mention <span class="font-mono text-xs text-slate-500">{{ $ticket->key() }}</span>
                    in a branch name, commit message or pull request title and it will appear here.
                @else
                    The GitHub webhook is not configured for this workspace yet.
                @endif
            </p>
        @else
            <ul class="divide-y divide-slate-100" role="list">
                @foreach ($links as $link)
                    <li wire:key="gh-{{ $link->id }}" class="py-3 first:pt-0 last:pb-0">
                        <div class="flex items-start gap-3">
                            <span class="mt-0.5 shrink-0 text-slate-400" aria-hidden="true">
                                @if ($link->type === \App\Enums\GithubLinkType::PullRequest)
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 100 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186l9.566-5.314m-9.566 7.5l9.566 5.314m0 0a2.25 2.25 0 103.935 2.186 2.25 2.25 0 00-3.935-2.186zm0-12.814a2.25 2.25 0 103.933-2.185 2.25 2.25 0 00-3.933 2.185z" />
                                    </svg>
                                @elseif ($link->type === \App\Enums\GithubLinkType::Branch)
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 3v12m0 0a3 3 0 103 3m-3-3a3 3 0 113-3m9-6a3 3 0 11-6 0 3 3 0 016 0zm-3 3v3a6 6 0 01-6 6" />
                                    </svg>
                                @else
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 12h5.25m7.5 0H21M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" />
                                    </svg>
                                @endif
                            </span>

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    {{--
                                        rel="noopener noreferrer" is not decoration: without it the
                                        opened tab gets a window.opener handle back to this page.
                                    --}}
                                    <a
                                        href="{{ $link->url }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="truncate text-sm font-medium text-slate-800 hover:text-brand-700"
                                    >
                                        {{ $link->title ?: $link->shortReference() }}
                                    </a>

                                    @if ($link->state)
                                        <x-ui.badge :variant="$link->state->badgeVariant()">
                                            {{ $link->state->label() }}
                                        </x-ui.badge>
                                    @endif

                                    @if ($link->hasCiStatus())
                                        <x-ui.badge :variant="$link->ciBadgeVariant()">{{ $link->ciLabel() }}</x-ui.badge>
                                    @endif
                                </div>

                                <p class="mt-0.5 truncate text-xs text-slate-500">
                                    <span class="font-mono">{{ $link->shortReference() }}</span>
                                    <span class="text-slate-300">·</span>
                                    {{ $link->repository }}
                                    @if ($link->author_login)
                                        <span class="text-slate-300">·</span>
                                        {{ $link->author_login }}
                                    @endif
                                    <span class="text-slate-300">·</span>
                                    <time datetime="{{ $link->updated_at?->toIso8601String() }}">
                                        {{ $link->updated_at?->diffForHumans(short: true) }}
                                    </time>
                                </p>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>

            <p class="mt-4 border-t border-slate-100 pt-3 text-xs text-slate-400">
                Internal to the delivery team. None of this is shown to the customer on their view of this
                ticket.
            </p>
        @endif
    </x-ui.card>
</div>
