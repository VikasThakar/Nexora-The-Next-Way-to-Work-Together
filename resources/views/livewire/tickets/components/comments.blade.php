@php
    use App\Enums\CommentStream;

    $internal = ! $activeStream->isCustomerFacing();
@endphp

{{--
    The two conversations.

    The internal panel is deliberately unlike the customer one: amber frame,
    amber body, a lock on the tab and a banner above the composer. The visual
    difference is the reminder; the guarantee is on the server, and in the fact
    that each tab writes to its own draft property.
--}}
<section @class([
    'overflow-hidden rounded-xl border shadow-xs',
    'border-amber-300 bg-amber-50/40' => $internal,
    'border-slate-200 bg-white' => ! $internal,
])>
    <header @class([
        'flex flex-wrap items-center gap-1 border-b px-3 pt-3',
        'border-amber-200' => $internal,
        'border-slate-200' => ! $internal,
    ])>
        @if ($canPostToCustomer || ! $internal)
            <button
                type="button"
                wire:click="switchStream('{{ CommentStream::Customer->value }}')"
                @class([
                    '-mb-px flex items-center gap-2 rounded-t-lg border border-b-0 px-3 py-2 text-sm font-medium transition',
                    'border-slate-200 bg-white text-slate-900' => ! $internal,
                    'border-transparent text-slate-500 hover:text-slate-800' => $internal,
                ])
            >
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
                </svg>
                {{ CommentStream::Customer->label() }}
                <span class="rounded-full bg-slate-100 px-1.5 text-[10px] text-slate-600">{{ $customerCount }}</span>
            </button>
        @endif

        @if ($canPostToInternal)
            <button
                type="button"
                wire:click="switchStream('{{ CommentStream::Internal->value }}')"
                @class([
                    '-mb-px flex items-center gap-2 rounded-t-lg border border-b-0 px-3 py-2 text-sm font-medium transition',
                    'border-amber-300 bg-amber-50 text-amber-900' => $internal,
                    'border-transparent text-slate-500 hover:text-slate-800' => ! $internal,
                ])
            >
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                </svg>
                {{ CommentStream::Internal->label() }}
                <span class="rounded-full bg-amber-200/70 px-1.5 text-[10px] text-amber-900">{{ $internalCount }}</span>
            </button>
        @endif
    </header>

    <div class="px-5 py-5">
        @if ($internal)
            <p class="mb-4 flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-100/60 px-3 py-2 text-xs text-amber-900">
                <svg class="mt-px size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                </svg>
                <span>
                    <strong>Internal only.</strong>
                    Customers on this board never receive these notes &mdash; not in the ticket, not in
                    search, not in notifications, not in a link.
                </span>
            </p>
        @endif

        {{-- ------------------------------------------------------------- --}}
        {{-- The thread                                                      --}}
        {{-- ------------------------------------------------------------- --}}
        @if ($comments->isEmpty())
            <p class="text-sm text-slate-400">
                {{ $internal ? 'No internal notes on this ticket yet.' : 'No messages yet.' }}
            </p>
        @else
            <ol class="space-y-4">
                @foreach ($comments as $comment)
                    <li wire:key="comment-{{ $comment->id }}" class="flex gap-3">
                        @php $automated = in_array($comment->id, $automatedIds, true); @endphp

                        <x-ui.avatar :name="$automated ? 'AI' : ($comment->author?->name ?? '?')" size="sm" class="mt-0.5" />

                        <div @class([
                            'min-w-0 flex-1 rounded-lg border px-3 py-2.5',
                            'border-amber-200 bg-amber-50' => $comment->isInternal(),
                            'border-slate-200 bg-slate-50' => ! $comment->isInternal(),
                        ])>
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                {{-- An AI note has no author on purpose: it does not
                                     impersonate whoever pressed the button. But it is not a
                                     removed user either, and a reader has to be able to tell. --}}
                                <span class="text-sm font-medium text-slate-900">
                                    @if ($automated)
                                        Workspace AI
                                    @else
                                        {{ $comment->author?->name ?? 'Removed user' }}
                                    @endif
                                </span>

                                @if ($automated)
                                    <x-ui.badge variant="brand">Generated</x-ui.badge>
                                @endif

                                <span class="text-xs text-slate-500">{{ $comment->created_at->diffForHumans() }}</span>

                                @if ($comment->wasEdited())
                                    <span class="text-xs text-slate-400"
                                          title="Edited {{ $comment->edited_at->toDayDateTimeString() }}">(edited)</span>
                                @endif

                                @if ($comment->isInternal())
                                    <x-ui.badge variant="amber" class="ml-auto">Internal</x-ui.badge>
                                @endif
                            </div>

                            @if ($editingId === $comment->id)
                                <form wire:submit="saveEdit" class="mt-2 space-y-2">
                                    <x-ui.textarea rows="4" class="font-mono text-xs" wire:model="editDraft"
                                                   :invalid="$errors->has('editDraft')">{{ $editDraft }}</x-ui.textarea>

                                    @error('editDraft')
                                        <p class="text-xs text-rose-600">{{ $message }}</p>
                                    @enderror

                                    <div class="flex items-center gap-2">
                                        <x-ui.button type="submit" size="sm">Save</x-ui.button>
                                        <x-ui.button type="button" variant="ghost" size="sm" wire:click="cancelEditing">
                                            Cancel
                                        </x-ui.button>
                                    </div>
                                </form>
                            @else
                                {{-- Safe to render unescaped: App\Support\Markdown strips raw HTML
                                     and unsafe link schemes, and the mention/ticket-reference
                                     post-processors escape everything they insert. --}}
                                <div class="markdown mt-1">{!! $bodies[$comment->id] !!}</div>

                                @if ($comment->attachments->isNotEmpty())
                                    <ul class="mt-2 flex flex-wrap gap-2">
                                        @foreach ($comment->attachments as $attachment)
                                            <li wire:key="comment-file-{{ $attachment->id }}">
                                                {{-- Authorized download, never a direct storage URL. --}}
                                                <a href="{{ route('attachments.show', $attachment) }}"
                                                   class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-2 py-1 text-xs text-slate-700 hover:border-brand-300 hover:text-brand-700">
                                                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13" />
                                                    </svg>
                                                    {{ $attachment->filename }}
                                                    <span class="text-slate-400">{{ $attachment->humanSize() }}</span>
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                <div class="mt-1.5 flex items-center gap-3 text-xs">
                                    @can('update', $comment)
                                        <button type="button" class="text-slate-500 hover:text-slate-800"
                                                wire:click="startEditing({{ $comment->id }})">Edit</button>
                                    @endcan

                                    @can('delete', $comment)
                                        <button type="button" class="text-rose-600 hover:text-rose-700"
                                                wire:click="remove({{ $comment->id }})"
                                                x-confirm="@js([
                                                    'title' => 'Delete this comment?',
                                                    'body' => 'It disappears from the thread for everyone, not just for you. This cannot be undone.',
                                                    'confirmText' => 'Delete comment',
                                                ])">
                                            Delete
                                        </button>
                                    @endcan
                                </div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif

        {{-- ------------------------------------------------------------- --}}
        {{-- Composer                                                        --}}
        {{-- ------------------------------------------------------------- --}}
        @if (($internal && $canPostToInternal) || (! $internal && $canPostToCustomer))
            <form wire:submit="post" @class([
                'mt-5 rounded-lg border p-3',
                'border-amber-300 bg-amber-50' => $internal,
                'border-slate-200 bg-white' => ! $internal,
            ])>
                <div class="mb-2 flex items-center justify-between gap-2">
                    <p @class([
                        'text-xs font-medium',
                        'text-amber-900' => $internal,
                        'text-slate-600' => ! $internal,
                    ])>
                        {{ $internal
                            ? 'Writing an internal note — the customer will not see this'
                            : 'Writing to the customer — they will see this' }}
                    </p>

                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="togglePreview">
                        {{ $previewing ? 'Write' : 'Preview' }}
                    </x-ui.button>
                </div>

                @if ($previewing)
                    <div class="markdown min-h-24 rounded-lg border border-slate-200 bg-white p-3">
                        {!! $previewHtml ?: '<p class="text-slate-400">Nothing to preview.</p>' !!}
                    </div>
                @else
                    {{-- Two separate fields, one per stream. Text written here
                         cannot be submitted to the other conversation. --}}
                    @if ($internal)
                        <x-ui.textarea rows="4" wire:model="internalDraft" wire:key="draft-internal"
                                       placeholder="Notes for the delivery team. Mention someone with @handle."
                                       :invalid="$errors->has('internalDraft')">{{ $internalDraft }}</x-ui.textarea>
                    @else
                        <x-ui.textarea rows="4" wire:model="customerDraft" wire:key="draft-customer"
                                       placeholder="Reply to the customer. Markdown is supported."
                                       :invalid="$errors->has('customerDraft')">{{ $customerDraft }}</x-ui.textarea>
                    @endif
                @endif

                @error($draftField)
                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                @enderror
                @error('files.*')
                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                @enderror

                <div class="mt-2 flex flex-wrap items-center gap-3">
                    <x-ui.button type="submit" :variant="$internal ? 'secondary' : 'primary'" size="sm"
                                 @class(['border-amber-400 text-amber-900' => $internal])>
                        {{ $internal ? 'Post internal note' : 'Send to customer' }}
                    </x-ui.button>

                    <label class="inline-flex cursor-pointer items-center gap-1.5 text-xs text-slate-600 hover:text-slate-900">
                        <input type="file" multiple wire:model="files" class="sr-only">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13" />
                        </svg>
                        <span wire:loading.remove wire:target="files">Attach files</span>
                        <span wire:loading wire:target="files">Uploading…</span>
                    </label>

                    @if ($files !== [])
                        <span class="text-xs text-slate-500">
                            {{ count($files) }} {{ \Illuminate\Support\Str::plural('file', count($files)) }} ready
                        </span>
                    @endif

                    @if ($mentionable->isNotEmpty())
                        <span class="ml-auto text-[11px] text-slate-400">
                            Mention:
                            @foreach ($mentionable->take(4) as $person)
                                <span class="font-mono" title="{{ $person['name'] }}">&#64;{{ $person['handle'] }}</span>{{ ! $loop->last ? ' ' : '' }}
                            @endforeach
                        </span>
                    @endif
                </div>
            </form>
        @endif
    </div>
</section>
