@php
    /**
     * The top-bar entry point to the assistant.
     *
     * This component is re-rendered on every page, which is what makes it the
     * right place to report the current page to the panel: the panel itself is
     * persisted across wire:navigate and therefore cannot see the route it is
     * sitting on.
     *
     * Everything below is a *hint*. App\Services\AI\PageContextResolver
     * re-resolves each part through the reader that governs it before it
     * reaches a prompt, so a forged value buys nothing.
     */
    $routeBoard = request()->route('board');
    $boardSlug = $routeBoard instanceof \App\Models\Board ? $routeBoard->slug : $routeBoard;

    $ticketNumber = request()->routeIs('tickets.show') ? request()->route('number') : null;
    $docSlug = request()->routeIs('docs.show') ? request()->route('slug') : null;
@endphp

<button
    type="button"
    x-data="{
        page: {
            board: @js($boardSlug),
            ticket: @js($ticketNumber === null ? null : (int) $ticketNumber),
            doc: @js($docSlug),
        },
    }"
    x-init="$store.aiPanel.setPage(page)"
    @click="$store.aiPanel.toggle(page)"
    :aria-expanded="$store.aiPanel.open ? 'true' : 'false'"
    aria-label="Workspace AI"
    aria-keyshortcuts="Escape"
    title="Workspace AI"
    @class([
        'relative rounded-lg p-2 transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500',
    ])
    ::class="$store.aiPanel.open
        ? 'bg-brand-50 text-brand-700'
        : 'text-slate-500 hover:bg-slate-100 hover:text-slate-700'"
>
    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" />
    </svg>

    <span class="sr-only">Workspace AI</span>
</button>
