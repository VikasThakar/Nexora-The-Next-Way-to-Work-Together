{{--
    Notification bell.

    Every line below was built by App\Services\NotificationReader from a live
    record the viewer was re-authorized against on this request. Nothing here
    comes out of the stored notification payload, which holds identifiers only.
--}}
<div
    class="relative"
    @if ($polling) wire:poll.60s @endif
    x-data="{ open: @entangle('open') }"
    @keydown.escape.window="open = false"
>
    <button
        type="button"
        class="relative flex size-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-900"
        wire:click="toggle"
        :aria-expanded="open"
        aria-haspopup="true"
        aria-label="Notifications{{ $unread > 0 ? ' ('.$unread.' unread)' : '' }}"
    >
        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
        </svg>

        @if ($unread > 0)
            <span class="absolute -top-0.5 -right-0.5 inline-flex min-w-4 items-center justify-center rounded-full bg-rose-600 px-1 text-[10px] font-semibold text-white">
                {{ $unread >= \App\Services\NotificationReader::WINDOW ? \App\Services\NotificationReader::WINDOW.'+' : $unread }}
            </span>
        @endif
    </button>

    <div
        x-show="open"
        x-cloak
        x-transition.origin.top.right
        @click.outside="open = false"
        class="absolute right-0 z-40 mt-2 w-96 max-w-[calc(100vw-2rem)] origin-top-right rounded-xl border border-slate-200 bg-white shadow-lg"
    >
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2.5">
            <p class="text-sm font-semibold text-slate-900">Notifications</p>

            @if ($unread > 0)
                <button type="button" wire:click="markAllRead"
                        class="text-xs font-medium text-brand-600 hover:text-brand-700">
                    Mark all as read
                </button>
            @endif
        </div>

        @if ($items->isEmpty())
            <p class="px-4 py-8 text-center text-sm text-slate-400">Nothing yet.</p>
        @else
            <ul class="max-h-96 divide-y divide-slate-100 overflow-y-auto">
                @foreach ($items as $item)
                    <li wire:key="notification-{{ $item->id }}">
                        <a
                            href="{{ $item->url }}"
                            wire:navigate
                            wire:click="markRead('{{ $item->id }}')"
                            @class([
                                'flex gap-3 px-4 py-3 transition hover:bg-slate-50',
                                'bg-brand-50/40' => $item->unread,
                            ])
                        >
                            <span @class([
                                'mt-1.5 size-2 shrink-0 rounded-full',
                                'bg-brand-500' => $item->unread,
                                'bg-transparent' => ! $item->unread,
                            ])></span>

                            <span class="min-w-0 flex-1">
                                <span class="block text-sm text-slate-800">{{ $item->message }}</span>
                                <span class="mt-0.5 flex items-center gap-2 text-xs text-slate-500">
                                    {{ $item->createdAt->diffForHumans() }}
                                    @if ($item->internal)
                                        {{-- Only ever rendered for somebody who may
                                             observe internal content: the reader
                                             would have dropped the row otherwise. --}}
                                        <x-ui.internal-badge />
                                    @endif
                                </span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>

            @if ($hidden > 0)
                {{-- The badge counts everything readable in the reader's
                     window; the list shows fifteen. Saying so is the difference
                     between a truncated list and one that looks broken. --}}
                <p class="border-t border-slate-100 px-4 py-2 text-center text-xs text-slate-400">
                    {{ $hidden }} older {{ \Illuminate\Support\Str::plural('notification', $hidden) }} not shown
                </p>
            @endif
        @endif
    </div>
</div>
