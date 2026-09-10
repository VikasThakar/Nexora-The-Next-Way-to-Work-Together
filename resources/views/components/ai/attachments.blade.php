@props([
    // Collection<int, App\Models\AiAttachment>
    'attachments',
    'canAttach' => false,
    // True while any row is still being read; drives the poll.
    'settling' => false,
    'error' => null,
    // True while a question is in flight.
    'disabled' => false,
    // Unique per surface, because the panel and the page can both be on screen.
    'id' => 'ai-attachments',
])

@php
    $classifier = app(\App\Services\AI\Attachments\AiAttachmentClassifier::class);
    $accept = collect($classifier->allowedExtensions())
        ->map(fn (string $extension): string => '.'.$extension)
        ->implode(',');

    $maxMb = round(((int) config('ai.attachments.max_size_kb')) / 1024, 1);
    $limit = (int) config('ai.attachments.max_per_session', 10);
    $full = $attachments->count() >= $limit;
@endphp

{{--
    The attachment area of the composer.

    Drag and drop, a button, and the list of what is already attached.

    Progress
    --------
    Livewire emits `livewire-upload-start`, `-progress`, `-finish` and `-error`
    on the input's own element while a file is going up, which is what the bar
    below listens for. That is real progress from the browser, not a spinner
    pretending: a 15 MB PDF on a slow connection takes long enough that the
    difference matters.

    Processing
    ----------
    A separate thing from uploading, and shown separately. Upload finishes when
    the bytes arrive; processing finishes when the file has been read. The list
    polls only while something is still being read, and stops on its own — see
    attachmentsSettling() in TalksToWorkspaceAi.

    Nothing here validates a type. The pipeline does, against the extension and
    the detected bytes, and its refusal is the sentence shown above the list.
    `accept` is a convenience for the file picker and no more: a browser hint is
    not a check.
--}}
<div
    @if ($settling)
        {{-- Only while something is in flight. A settled composer makes no
             requests, which is why the attribute is conditional rather than
             always present with a long interval. --}}
        wire:poll.2s="refreshAttachments"
    @endif
>
    @if ($canAttach)
        <div
            x-data="{ dragging: false, uploading: false, progress: 0 }"
            x-on:livewire-upload-start="uploading = true; progress = 0"
            x-on:livewire-upload-finish="uploading = false; progress = 0"
            x-on:livewire-upload-cancel="uploading = false; progress = 0"
            x-on:livewire-upload-error="uploading = false; progress = 0"
            x-on:livewire-upload-progress="progress = $event.detail.progress"
            x-on:dragover.prevent="dragging = true"
            x-on:dragleave.prevent="dragging = false"
            x-on:drop.prevent="
                dragging = false;
                if ($event.dataTransfer?.files?.length) {
                    $refs.picker.files = $event.dataTransfer.files;
                    $refs.picker.dispatchEvent(new Event('change', { bubbles: true }));
                }
            "
            @class(['rounded-lg border border-dashed transition'])
            x-bind:class="dragging
                ? 'border-brand-400 bg-brand-50/60'
                : 'border-slate-300 bg-slate-50/60'"
        >
            <label
                for="{{ $id }}-picker"
                @class([
                    'flex cursor-pointer items-center gap-3 px-3 py-2.5 text-sm',
                    'cursor-not-allowed opacity-60' => $disabled || $full,
                ])
            >
                <span class="text-base leading-none" aria-hidden="true">📎</span>

                <span class="min-w-0 flex-1">
                    @if ($full)
                        <span class="font-medium text-slate-600">
                            {{ $limit }} attachments is the limit for one conversation
                        </span>
                        <span class="block text-xs text-slate-500">
                            Remove one, or start a new session for a fresh set.
                        </span>
                    @else
                        <span class="font-medium text-slate-700">
                            Attach a file, or drop one here
                        </span>
                        <span class="block truncate text-xs text-slate-500">
                            PDF, Markdown, text, CSV, images, audio, Word, Excel and PowerPoint · up to {{ $maxMb }} MB each
                        </span>
                    @endif
                </span>

                <span x-show="uploading" class="shrink-0 text-xs text-slate-500">
                    <span x-text="progress"></span>%
                </span>
            </label>

            {{-- The real input, kept out of the layout but not hidden from
                 assistive technology: the label above is its label. --}}
            <input
                id="{{ $id }}-picker"
                x-ref="picker"
                type="file"
                multiple
                accept="{{ $accept }}"
                wire:model="uploads"
                @disabled($disabled || $full)
                class="sr-only"
            />

            {{-- Real upload progress, from the browser. --}}
            <div x-show="uploading" x-cloak class="px-3 pb-2.5">
                <div class="h-1 w-full overflow-hidden rounded-full bg-slate-200">
                    <div
                        class="h-full rounded-full bg-brand-500 transition-all"
                        x-bind:style="`width: ${progress}%`"
                    ></div>
                </div>
            </div>
        </div>
    @endif

    @error('uploads.*')
        <p class="mt-2 text-xs text-rose-600">{{ $message }}</p>
    @enderror

    @if (filled($error))
        {{-- The pipeline's own words: which file, why, and what to do. --}}
        <p class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
            {{ $error }}
        </p>
    @endif

    @if ($attachments->isNotEmpty())
        <div class="mt-2 space-y-2">
            @foreach ($attachments as $attachment)
                <x-ai.attachment-card
                    :attachment="$attachment"
                    :removable="$canAttach && ! $disabled"
                />
            @endforeach
        </div>
    @endif
</div>
