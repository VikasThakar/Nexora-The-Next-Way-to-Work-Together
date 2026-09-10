@props([
    // App\Models\AiAttachment
    'attachment',
    // Whether the remove control is offered. False while a question is in
    // flight: removing a file mid-answer would leave the transcript quoting
    // something that is no longer there.
    'removable' => true,
])

{{--
    One attached file, in the composer.

    Four states and each looks different at a glance, because the whole point of
    the card is answering "can it read my file yet" without reading any words:

      Queued / Processing   a pulsing dot and the status word
      Ready                 the kind's glyph and what was found
      Failed / Unsupported  amber, with the reason in full

    The reason text comes from the processor and is written for a person — see
    App\Services\AI\Attachments\AiAttachmentExtraction::failed(). It is escaped
    like any other model- or file-derived string.

    Amber, not rose: a file we could not read is a warning, not a fault in the
    product. Rose in this design system means something broke. See x-ui.badge.
--}}
<div
    wire:key="ai-attachment-{{ $attachment->id }}"
    @class([
        'group flex items-start gap-3 rounded-lg border px-3 py-2.5 text-sm transition',
        'border-slate-200 bg-surface' => ! $attachment->status->isProblem(),
        'border-amber-200 bg-amber-50/60' => $attachment->status->isProblem(),
    ])
>
    {{-- The glyph, or a pulse while it is still being read. --}}
    <div class="mt-0.5 shrink-0 text-base leading-none" aria-hidden="true">
        @if ($attachment->status->isTerminal())
            {{ $attachment->kind->icon() }}
        @else
            <span class="inline-block size-2.5 animate-pulse rounded-full bg-brand-500"></span>
        @endif
    </div>

    <div class="min-w-0 flex-1">
        <div class="flex items-center gap-2">
            <p class="truncate font-medium text-slate-800" title="{{ $attachment->filename() }}">
                {{ $attachment->filename() }}
            </p>

            @if (! $attachment->status->isReady())
                <x-ui.badge :variant="$attachment->status->badgeVariant()">
                    {{ $attachment->status->label() }}
                </x-ui.badge>
            @endif
        </div>

        {{-- What it is, how big, and what came out of it. --}}
        <p class="mt-0.5 truncate text-xs text-slate-500">
            {{ $attachment->descriptor() }}

            @if ($attachment->truncated)
                {{-- Stated on the card as well as in the prompt, so nobody is
                     surprised that an answer covers only part of a long file. --}}
                · only the first part is read
            @endif
        </p>

        @if ($attachment->status->isProblem() && filled($attachment->error))
            <p class="mt-1.5 text-xs text-amber-800">{{ $attachment->error }}</p>
        @endif

        @if ($attachment->status->isReady() && $attachment->token_estimate)
            {{-- Labelled an estimate, always. The real figures come from the
                 provider and live in ai_usage_records; this one is derived from
                 a characters-per-token ratio. --}}
            <p class="mt-1 text-xs text-slate-400">
                about {{ number_format($attachment->token_estimate) }} tokens of context
                @if ($attachment->wasSentInFull())
                    · already sent, so later questions reference it rather than re-sending it
                @endif
            </p>
        @endif
    </div>

    <div class="flex shrink-0 items-center gap-1">
        {{-- The stored file, through the authorized download route. Never a
             link into storage; see AttachmentController. --}}
        @if ($attachment->attachment)
            <a
                href="{{ route('attachments.show', $attachment->attachment) }}"
                target="_blank"
                rel="noopener"
                class="rounded p-1 text-xs text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                title="Download {{ $attachment->filename() }}"
            >
                <span aria-hidden="true">↓</span>
                <span class="sr-only">Download {{ $attachment->filename() }}</span>
            </a>
        @endif

        @if ($removable)
            <button
                type="button"
                wire:click="removeAttachment({{ $attachment->id }})"
                wire:loading.attr="disabled"
                wire:target="removeAttachment({{ $attachment->id }})"
                class="rounded p-1 text-xs text-slate-400 transition hover:bg-rose-50 hover:text-rose-600"
                title="Remove {{ $attachment->filename() }}"
            >
                <span aria-hidden="true">✕</span>
                <span class="sr-only">Remove {{ $attachment->filename() }}</span>
            </button>
        @endif
    </div>
</div>
