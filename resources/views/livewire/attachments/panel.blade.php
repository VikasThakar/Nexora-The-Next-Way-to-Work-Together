<x-ui.card
    title="Attachments"
    :description="'Up to '.$maxSizeMb.' MB per file. Stored in object storage, never on the web container.'"
>
    @php
        // Pictures and recordings are shown; everything else is listed. The
        // split is presentation only — both halves are the same rows, served
        // by the same authorized route.
        $previewable = $attachments->filter(fn ($attachment) => $attachment->isPreviewable());
        $listed = $attachments->reject(fn ($attachment) => $attachment->isPreviewable());
    @endphp

    @if ($attachments->isEmpty())
        <p class="text-sm text-slate-400">No attachments.</p>
    @else
        @if ($previewable->isNotEmpty())
            {{--
                Inline, never a filename with an icon: a screenshot is worth
                looking at, and a recording is worth playing, right here.
                Both come through route('attachments.show'), which serves
                image and video types inline and re-authorizes each fetch.
            --}}
            <ul class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                @foreach ($previewable as $attachment)
                    <li wire:key="attachment-{{ $attachment->id }}" class="group relative overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
                        @if ($attachment->isVideo())
                            <video
                                src="{{ route('attachments.show', $attachment) }}"
                                controls
                                preload="metadata"
                                playsinline
                                class="block aspect-video w-full bg-black"
                                title="{{ $attachment->filename }}"
                            ></video>
                        @else
                            <a href="{{ route('attachments.show', $attachment) }}" target="_blank" rel="noopener"
                               class="block" title="Open {{ $attachment->filename }}">
                                <img src="{{ route('attachments.show', $attachment) }}"
                                     alt="{{ $attachment->filename }}"
                                     loading="lazy"
                                     class="block aspect-video w-full object-cover transition group-hover:opacity-90">
                            </a>
                        @endif

                        <div class="flex items-center gap-2 px-2 py-1.5">
                            <span class="min-w-0 flex-1 truncate text-[11px] text-slate-500" title="{{ $attachment->filename }}">
                                {{ $attachment->humanSize() }}
                                @if ($attachment->uploadedBy)
                                    &middot; {{ $attachment->uploadedBy->name }}
                                @endif
                            </span>

                            @can('delete', $attachment)
                                <button type="button"
                                        class="shrink-0 rounded p-0.5 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600"
                                        wire:click="remove({{ $attachment->id }})"
                                        x-confirm="{{ \Illuminate\Support\Js::from([
                                            'title' => 'Delete this file?',
                                            'body' => $attachment->filename.' will be removed permanently. This cannot be undone.',
                                            'confirmText' => 'Delete file',
                                        ]) }}"
                                        aria-label="Delete {{ $attachment->filename }}">
                                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            @endcan
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        <ul @class(['divide-y divide-slate-100', 'mt-2' => $previewable->isNotEmpty()])>
            @foreach ($listed as $attachment)
                <li wire:key="attachment-{{ $attachment->id }}" class="flex items-center gap-3 py-2.5">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500">
                        @if ($attachment->isImage())
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M18 9h.008v.008H18V9Zm2.25 9A2.25 2.25 0 0 1 18 20.25H6A2.25 2.25 0 0 1 3.75 18V6A2.25 2.25 0 0 1 6 3.75h12A2.25 2.25 0 0 1 20.25 6v12Z" />
                            </svg>
                        @else
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M6.75 12h9m-9 3h5.25M7.5 2.25h3.75c.621 0 1.125.504 1.125 1.125v3.75c0 .621.504 1.125 1.125 1.125h3.75c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125H7.5A1.125 1.125 0 0 1 6.375 19.5V3.375c0-.621.504-1.125 1.125-1.125Z" />
                            </svg>
                        @endif
                    </span>

                    <div class="min-w-0 flex-1">
                        {{-- Never a direct storage URL: this route authorizes
                             against the ticket before issuing a signed link. --}}
                        <a href="{{ route('attachments.show', $attachment) }}"
                           class="block truncate text-sm font-medium text-slate-900 hover:text-brand-700">
                            {{ $attachment->filename }}
                        </a>
                        <p class="text-xs text-slate-500">
                            {{ $attachment->humanSize() }}
                            @if ($attachment->uploadedBy)
                                &middot; {{ $attachment->uploadedBy->name }}
                            @endif
                            &middot; {{ $attachment->created_at->diffForHumans() }}
                        </p>

                        @if ($withSnippets)
                            {{-- Paste into the page body. The URL is the
                                 authorized download route, so the image is only
                                 served to somebody who may read the page. --}}
                            <code class="mt-1 block truncate rounded bg-slate-50 px-1.5 py-0.5 font-mono text-[11px] text-slate-500"
                                  title="Copy into the page to embed this file">{{ $attachment->isImage() ? '!' : '' }}[{{ $attachment->filename }}]({{ route('attachments.show', $attachment) }})</code>
                        @endif
                    </div>

                    @can('delete', $attachment)
                        <x-ui.button type="button" variant="ghost" size="sm" class="text-rose-600 hover:bg-rose-50"
                                     wire:click="remove({{ $attachment->id }})"
                                     :confirm="[
                                         'title' => 'Delete this file?',
                                         'body' => $attachment->filename.' will be removed permanently. This cannot be undone.',
                                         'confirmText' => 'Delete file',
                                     ]">
                            Delete
                        </x-ui.button>
                    @endcan
                </li>
            @endforeach
        </ul>
    @endif

    @if ($canManage)
        <div class="mt-4 border-t border-slate-100 pt-4"
             x-data="fileDrop"
             x-on:dragenter.prevent="dragEnter($event)"
             x-on:dragover.prevent
             x-on:dragleave.prevent="dragLeave($event)"
             x-on:drop.prevent="drop($event)">
            <label class="flex cursor-pointer items-center justify-center gap-2 rounded-lg border border-dashed px-4 py-5 text-sm text-slate-500 transition hover:border-brand-400 hover:text-brand-700"
                   x-bind:class="dragging ? 'border-brand-500 bg-brand-50/60' : 'border-slate-300'">
                <input type="file" multiple wire:model="files" x-ref="picker" class="sr-only">
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
                </svg>
                <span wire:loading.remove wire:target="files">Drop files here, or choose</span>
                <span wire:loading wire:target="files">Uploading…</span>
            </label>

            @error('files.*')
                <p class="mt-2 text-xs text-rose-600">{{ $message }}</p>
            @enderror
            @error('files')
                <p class="mt-2 text-xs text-rose-600">{{ $message }}</p>
            @enderror
        </div>
    @endif
</x-ui.card>
