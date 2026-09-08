@props([
    /*
     * Livewire property the editor writes its HTML into.
     *
     * A separate property from the Markdown one on purpose. The component
     * receives HTML and converts it with App\Support\RichText\RichText, so the
     * Markdown property is never written directly from the browser and the
     * conversion has exactly one entry point on the server.
     */
    'property',

    /*
     * The document to open, already rendered to HTML by
     * RichText::toEditorHtml(). Not ContentRenderer output — see that method
     * for why feeding a per-viewer render into an editor is a bug.
     */
    'html' => '',

    /** Livewire property that receives pasted and chosen files. */
    'uploader' => null,

    /*
     * Livewire method to call a few seconds after typing stops, or null for an
     * editor that only saves when its form is submitted.
     *
     * Naming one turns on the save-state indicator in the toolbar as well —
     * the two belong together, because a save that happens on a timer has to
     * say so somewhere or nobody can tell whether their work is safe.
     *
     * What the method actually writes is its own business. Documentation
     * points this at a draft rather than the page itself; see
     * App\Livewire\Docs\Show.
     */
    'autosave' => null,

    'label' => 'Description',
    'placeholder' => 'Write what needs doing. Use the toolbar, or Markdown shortcuts like ## and - [ ].',
    'invalid' => false,

    /*
     * How much empty room the writing surface starts with: 'default' for a
     * field in a form, 'tall' for a whole document.
     *
     * Two named sizes rather than a free class, so every utility this component
     * can emit is written out below where Tailwind's scanner can see it.
     */
    'minHeight' => 'default',
])

@php
    $canUpload = filled($uploader);
    $autosaves = filled($autosave);
    $surfaceId = 'editor-'.md5($property);
    $surfaceHeight = $minHeight === 'tall' ? 'min-h-96' : 'min-h-48';
@endphp

{{--
    wire:ignore is not optional here.

    Inside this element ProseMirror owns the DOM: it holds selection state, a
    mutation observer and node views. Livewire's morph would reconcile that
    live tree against a server-rendered one on every request and destroy the
    cursor, the undo history, or the whole editor — so the server renders this
    container once and never touches it again. Content flows out through
    $wire.set (see resources/js/editor.js), never back in.
--}}
<div
    x-data="richEditor({
        initialHtml: @js($html),
        property: @js($property),
        uploader: @js($uploader),
        canUpload: @js($canUpload),
        placeholder: @js($placeholder),
        autosave: @js($autosave),
    })"
    wire:ignore
    @class([
        'overflow-hidden rounded-lg border bg-white shadow-xs transition',
        'border-slate-300 focus-within:border-brand-400 focus-within:ring-2 focus-within:ring-brand-500/20' => ! $invalid,
        'border-rose-400 focus-within:ring-2 focus-within:ring-rose-500/20' => $invalid,
    ])
>
    {{-- ------------------------------------------------------------- --}}
    {{-- Toolbar                                                        --}}
    {{-- ------------------------------------------------------------- --}}
    {{--
        role="toolbar" with a horizontal orientation, so a screen reader
        announces it as one control rather than thirty loose buttons. Every
        button carries aria-pressed, which is what makes a toggle audible: a
        "Bold" button that is already on has to say so.

        It scrolls horizontally on a narrow screen rather than wrapping to four
        rows and pushing the writing area off the bottom of a phone.
    --}}
    <div
        role="toolbar"
        aria-orientation="horizontal"
        aria-label="{{ $label }} formatting"
        class="flex items-center gap-0.5 overflow-x-auto border-b border-slate-200 bg-slate-50 px-1.5 py-1 scrollbar-none"
    >
        <x-ui.editor-button command="undo" label="Undo" x-bind:disabled="! state.canUndo">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
        </x-ui.editor-button>

        <x-ui.editor-button command="redo" label="Redo" x-bind:disabled="! state.canRedo">
            <path stroke-linecap="round" stroke-linejoin="round" d="m15 15 6-6m0 0-6-6m6 6H9a6 6 0 1 0 0 12h3" />
        </x-ui.editor-button>

        <x-ui.editor-divider />

        {{-- Block level. A <select> rather than six buttons: it states the
             current block, which a row of toggles cannot. --}}
        <label class="sr-only" for="{{ $surfaceId }}-block">Text style</label>
        <select
            id="{{ $surfaceId }}-block"
            class="mr-0.5 shrink-0 rounded border-0 bg-transparent py-1 pr-6 pl-1.5 text-xs font-medium text-slate-600 hover:bg-slate-200 focus:ring-2 focus:ring-brand-500/40"
            x-on:change="run($event.target.value); $event.target.value = currentBlock()"
            x-effect="$el.value = currentBlock()"
        >
            <option value="paragraph">Paragraph</option>
            <option value="h1">Heading 1</option>
            <option value="h2">Heading 2</option>
            <option value="h3">Heading 3</option>
        </select>

        <x-ui.editor-divider />

        <x-ui.editor-button command="bold" label="Bold">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3.75h6a3.75 3.75 0 0 1 0 7.5h-6v-7.5Zm0 7.5h6.75a3.75 3.75 0 0 1 0 7.5H6.75v-7.5Z" />
        </x-ui.editor-button>

        <x-ui.editor-button command="italic" label="Italic">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 3.75h-6m4.5 0-4.5 16.5m0 0h6m-10.5 0h4.5" />
        </x-ui.editor-button>

        <x-ui.editor-button command="underline" label="Underline">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3.75v6.75a5.25 5.25 0 0 0 10.5 0V3.75M4.5 20.25h15" />
        </x-ui.editor-button>

        <x-ui.editor-button command="strike" label="Strikethrough">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12h15M7.5 7.5A3.75 3.75 0 0 1 11.25 4.5h1.5a3.75 3.75 0 0 1 3.6 2.7M16.5 16.5a3.75 3.75 0 0 1-3.75 3H11.25a3.75 3.75 0 0 1-3.6-2.7" />
        </x-ui.editor-button>

        <x-ui.editor-button command="code" label="Inline code">
            <path stroke-linecap="round" stroke-linejoin="round" d="m6.75 7.5-3.75 4.5 3.75 4.5m10.5-9 3.75 4.5-3.75 4.5M13.5 4.5l-3 15" />
        </x-ui.editor-button>

        <x-ui.editor-divider />

        <x-ui.editor-button command="bulletList" label="Bullet list">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 12h.007v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm-.375 5.25h.007v.008H3.75v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
        </x-ui.editor-button>

        <x-ui.editor-button command="orderedList" label="Numbered list">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.242 5.992h12m-12 6.003h12m-12 5.999h12M4.117 7.495v-3.75H2.99m1.127 3.75H2.99m1.127 0H5.24m-1.123 7.5H2.99l2.25-3h-2.25m0 6h1.125a1.125 1.125 0 0 0 0-2.25H3.74" />
        </x-ui.editor-button>

        {{-- The client's headline request: checklists inside the description. --}}
        <x-ui.editor-button command="taskList" label="Checklist">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
        </x-ui.editor-button>

        <x-ui.editor-divider />

        <x-ui.editor-button command="blockquote" label="Quote">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19 14.5M14.25 3.104c.251.023.501.05.75.082M19 14.5v5.25a2.25 2.25 0 0 1-2.25 2.25H7.25A2.25 2.25 0 0 1 5 19.75V14.5" />
        </x-ui.editor-button>

        <x-ui.editor-button command="codeBlock" label="Code block">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.25 9.75 16.5 12l-2.25 2.25m-4.5 0L7.5 12l2.25-2.25M6 20.25h12A2.25 2.25 0 0 0 20.25 18V6A2.25 2.25 0 0 0 18 3.75H6A2.25 2.25 0 0 0 3.75 6v12A2.25 2.25 0 0 0 6 20.25Z" />
        </x-ui.editor-button>

        <x-ui.editor-button label="Link" x-on:click="promptForLink()" x-bind:aria-pressed="isActive('link')">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
        </x-ui.editor-button>

        <x-ui.editor-button label="Insert table" x-on:click="insertTable()">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 0 1-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0 1 12 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m17.25-3.75h-7.5c-.621 0-1.125.504-1.125 1.125m8.625-1.125c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m0 0h-7.5m0 0c-.621 0-1.125.504-1.125 1.125M12 10.875v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 10.875c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125M13.125 12h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125M20.625 12c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5M12 14.625v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 14.625c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125m0 1.5v-1.5m0 0c0-.621.504-1.125 1.125-1.125m0 0h7.5" />
        </x-ui.editor-button>

        <x-ui.editor-button command="rule" label="Divider">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5" />
        </x-ui.editor-button>

        @if ($canUpload)
            <x-ui.editor-divider />

            {{-- Files. The label says "attach", not "upload", because the file
                 becomes an attachment on this ticket and is served through the
                 authorized download route like any other. --}}
            <x-ui.editor-button
                label="Attach a file or image"
                x-on:click="chooseFiles()"
                x-bind:disabled="uploading"
            >
                <path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13" />
            </x-ui.editor-button>

            <input
                type="file"
                x-ref="file"
                multiple
                class="sr-only"
                x-on:change="filesChosen($event)"
                tabindex="-1"
                aria-hidden="true"
            >

            <span
                x-show="uploading"
                x-cloak
                class="shrink-0 px-1 text-xs text-slate-500"
                role="status"
            >Uploading…</span>
        @endif

        @if ($autosaves)
            {{--
                The save state.

                Four separate spans rather than one with a bound class,
                because Tailwind reads this file to decide which utilities to
                generate — a colour assembled in JavaScript is a colour that
                never reaches the stylesheet.

                aria-live so the change is announced without stealing focus.
                Amber for "unsaved" is the warning it is; this is one of the
                places amber still means what it says.
            --}}
            <span
                class="ms-auto flex shrink-0 items-center gap-1 ps-2 text-xs"
                role="status"
                aria-live="polite"
            >
                <span x-show="saveState === 'dirty'" x-cloak class="text-amber-700">Unsaved changes</span>
                <span x-show="saveState === 'saving'" x-cloak class="text-slate-500">Saving…</span>
                <span x-show="saveState === 'saved'" x-cloak class="text-emerald-700">Draft saved</span>
                <span x-show="saveState === 'failed'" x-cloak class="text-rose-700">Not saved</span>
            </span>
        @endif
    </div>

    {{-- ------------------------------------------------------------- --}}
    {{-- Writing surface                                                --}}
    {{-- ------------------------------------------------------------- --}}
    {{-- ProseMirror replaces this element's contents on init. --}}
    <div
        x-ref="surface"
        id="{{ $surfaceId }}"
        aria-label="{{ $label }}"
        class="{{ $surfaceHeight }} cursor-text px-3 py-2.5"
        x-on:click.self="editor?.chain().focus().run()"
    ></div>

    <p
        x-show="uploadError"
        x-cloak
        class="border-t border-rose-100 bg-rose-50 px-3 py-1.5 text-xs text-rose-700"
        role="alert"
        x-text="uploadError"
    ></p>
</div>
