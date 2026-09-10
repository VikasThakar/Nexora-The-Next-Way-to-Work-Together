@props([
    // array{available: bool, reason: ?string, voices: array<string, string>}
    'voice',
    'disabled' => false,
])

@php
    /*
     * Spoken conversation.
     *
     * Three states with three appearances, one flag for the speaker, and a
     * sentence when the feature is not configured. Nothing here fakes working:
     * with no credential the control is disabled and says why, which was an
     * explicit requirement — a microphone button that records into nothing is
     * worse than no button.
     *
     * The state machine lives in resources/js/ai-voice.js. This template only
     * renders it, which is why there is no `@if ($listening)` anywhere: Alpine
     * owns the state and the classes are bound rather than branched, so the
     * markup cannot disagree with the machine.
     */
    $available = (bool) ($voice['available'] ?? false);
    $reason = $voice['reason'] ?? null;
    $voices = $voice['voices'] ?? [];
    $defaultVoice = $voices === [] ? null : array_key_first($voices);
@endphp

<div
    x-data="aiVoice({
        available: @js($available),
        reason: @js($reason),
        voice: @js($defaultVoice),
        listenUrl: @js(route('ai.voice.listen')),
        {{-- A template rather than a built URL: the message id is not known
             until an answer exists, and generating a route per turn would mean
             a round trip to learn a URL the browser can assemble.

             Generated with a numeric stand-in and then substituted, because
             the route constrains the parameter to digits — asking the
             generator for a literal placeholder would be asking it for a URL
             the router would refuse. --}}
        speakUrl: @js(str_replace('987654321', '__ID__', route('ai.voice.speak', ['message' => 987654321]))),
    })"
    class="flex flex-col gap-1.5"
>
    <div class="flex items-center gap-1.5">
        {{-- The microphone. One button for start and stop, because a
             conversation has one control and its meaning is its state. --}}
        <button
            type="button"
            x-on:click="toggle()"
            x-bind:disabled="!available || @js($disabled)"
            x-bind:aria-label="label"
            x-bind:title="available ? label : unavailableReason"
            x-bind:aria-pressed="listening ? 'true' : 'false'"
            class="relative inline-flex size-8 items-center justify-center rounded-md transition disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-solid"
            x-bind:class="{
                'bg-rose-50 text-rose-600 dark:bg-rose-500/10': listening,
                'bg-brand-50 text-brand-700': processing || speaking,
                'text-slate-500 hover:bg-slate-100 hover:text-slate-700': !busy,
            }"
        >
            {{-- The listening halo. A ring that breathes rather than a colour
                 that flashes: it has to read as "on" from the corner of the eye
                 without competing with the answer being written. --}}
            <span
                x-show="listening"
                x-cloak
                class="nx-listening absolute inset-0 rounded-md ring-2 ring-rose-400/70"
                aria-hidden="true"
            ></span>

            {{-- Microphone, when idle or listening. --}}
            <svg x-show="!processing && !speaking" class="relative size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" />
            </svg>

            {{-- Processing. --}}
            <svg x-show="processing" x-cloak class="nx-spin relative size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992V4.356m-4.992 4.992-2.65-2.65a7.5 7.5 0 0 0-10.6 10.6 7.5 7.5 0 0 0 10.6 0" />
            </svg>

            {{-- Speaking. --}}
            <svg x-show="speaking" x-cloak class="relative size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.114 5.636a9 9 0 0 1 0 12.728M16.463 8.288a5.25 5.25 0 0 1 0 7.424M6.75 8.25l4.72-4.72a.75.75 0 0 1 1.28.53v15.88a.75.75 0 0 1-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.009 9.009 0 0 1 2.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75Z" />
            </svg>
        </button>

        {{-- Stop. Shown only while something is happening, because a stop
             button on an idle conversation is a control with nothing to do. --}}
        <button
            type="button"
            x-show="busy"
            x-cloak
            x-on:click="stop()"
            class="inline-flex size-8 items-center justify-center rounded-md text-slate-500 transition hover:bg-slate-100 hover:text-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-solid"
            aria-label="Stop the voice conversation"
            title="Stop"
        >
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 7.5A2.25 2.25 0 0 1 7.5 5.25h9a2.25 2.25 0 0 1 2.25 2.25v9a2.25 2.25 0 0 1-2.25 2.25h-9a2.25 2.25 0 0 1-2.25-2.25v-9Z" />
            </svg>
        </button>

        {{-- Mute. The speaker, not the microphone: answers stop being read
             aloud and everything else keeps working. --}}
        <button
            type="button"
            x-show="available"
            x-on:click="toggleMute()"
            x-bind:aria-pressed="muted ? 'true' : 'false'"
            x-bind:aria-label="muted ? 'Unmute spoken answers' : 'Mute spoken answers'"
            x-bind:title="muted ? 'Answers are not read aloud' : 'Answers are read aloud'"
            class="inline-flex size-8 items-center justify-center rounded-md transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-solid"
            x-bind:class="muted ? 'bg-slate-100 text-slate-700' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-700'"
        >
            <svg x-show="!muted" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.114 5.636a9 9 0 0 1 0 12.728M16.463 8.288a5.25 5.25 0 0 1 0 7.424M6.75 8.25l4.72-4.72a.75.75 0 0 1 1.28.53v15.88a.75.75 0 0 1-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.009 9.009 0 0 1 2.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75Z" />
            </svg>

            <svg x-show="muted" x-cloak class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17.25 9.75 19.5 12m0 0 2.25 2.25M19.5 12l2.25-2.25M19.5 12l-2.25 2.25m-10.5-6 4.72-4.72a.75.75 0 0 1 1.28.53v15.88a.75.75 0 0 1-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.009 9.009 0 0 1 2.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75Z" />
            </svg>
        </button>

        {{-- What is happening, in words. The icons carry the state for people
             watching; this carries it for people who are not. --}}
        <span
            x-show="busy"
            x-cloak
            class="text-xs text-slate-500"
            x-text="processing ? 'Thinking…' : (listening ? 'Listening…' : 'Speaking…')"
        ></span>

        <span aria-live="polite" class="sr-only" x-text="label"></span>
    </div>

    @unless ($available)
        {{-- Not configured. The provider writes this sentence and it names the
             remedy, so it is shown verbatim rather than summarised. --}}
        <p class="text-xs text-slate-500">
            {{ $reason ?? 'Spoken conversation is not configured for this deployment.' }}
        </p>
    @endunless

    {{-- Something went wrong during a turn. Cleared on the next attempt. --}}
    <p x-show="error" x-cloak class="text-xs text-rose-600" x-text="error"></p>
</div>
