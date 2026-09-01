@php
    $messages = array_filter([
        'emerald' => session('status'),
        'rose' => session('error'),
    ]);
@endphp

@foreach ($messages as $tone => $message)
    <div
        x-data="{ shown: true }"
        x-show="shown"
        x-init="setTimeout(() => shown = false, 6000)"
        class="mb-5 flex items-start gap-3 rounded-lg border px-4 py-3 text-sm
            {{ $tone === 'emerald'
                ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                : 'border-rose-200 bg-rose-50 text-rose-800' }}"
        role="status"
    >
        <span class="flex-1">{{ $message }}</span>
        <button type="button" @click="shown = false" class="shrink-0 opacity-60 transition hover:opacity-100" aria-label="Dismiss">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
        </button>
    </div>
@endforeach
