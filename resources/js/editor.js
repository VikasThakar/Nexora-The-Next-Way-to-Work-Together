/*
 * The rich text editor.
 *
 * TipTap (ProseMirror underneath), registered as an Alpine component in the
 * same shape as ./dialog and ./ai-panel: no separate Alpine install, nothing to
 * import in a Blade file, one `x-data="richEditor(...)"` at the call site.
 *
 *
 * Why TipTap
 * ----------
 * It is headless. There is no stylesheet to fight Tailwind with, no theme, no
 * toolbar markup — the toolbar in resources/views/components/ui/rich-editor.blade.php
 * is ordinary Blade buttons, styled like every other button in the product.
 * It is also framework-agnostic vanilla JavaScript, so no React or Vue enters
 * the build, which was a hard requirement.
 *
 * The alternative worth naming is contenteditable with document.execCommand.
 * That is deprecated, behaves differently in every browser, and produces
 * whatever HTML the browser feels like — `<font>`, `<b>`, nested `<div>`s. A
 * ProseMirror schema is the opposite: the document can only ever contain nodes
 * the schema declares, so the HTML the editor emits is constrained by
 * construction rather than by hope. That is a security property as much as a
 * correctness one, and it is the first of the three filters described in
 * App\Support\RichText\EditorHtml.
 *
 *
 * How it talks to Livewire
 * ------------------------
 * Not through wire:model. The editor owns a live ProseMirror DOM inside
 * wire:ignore, and a property bound with wire:model.live would round-trip on
 * every keystroke and re-render the container underneath the cursor.
 *
 * Instead the flow is one-directional and explicit:
 *
 *   in    the server renders the stored Markdown to HTML once
 *         (App\Support\RichText\RichText::toEditorHtml) and it is read from a
 *         data attribute at init. After that the server does not push content.
 *   out   the HTML is written to the Livewire property on blur, on an idle
 *         pause, and — the one that must not be missed — synchronously before
 *         the form submits.
 *
 * The submit hook is the load-bearing one. A debounce alone loses the last few
 * characters somebody typed immediately before hitting save, which is exactly
 * the moment they are watching.
 *
 *
 * Autosaving
 * ----------
 * Optional, and off unless the call site names a Livewire method. Documentation
 * uses it; a ticket description does not, because a ticket form is short enough
 * to finish in one sitting and its Save button is always on screen.
 *
 * Where an autosave goes is the server's business, not this file's. In
 * documentation it writes a draft rather than the page — see
 * App\Livewire\Docs\Show — and all this side has to be right about is that the
 * document reaches the property before the request goes, and that the person
 * is told which of "unsaved", "saving", "saved" and "not saved" they are
 * looking at.
 */

/** How long after the last keystroke the draft is pushed to Livewire. */
const IDLE_SYNC_MS = 900

/**
 * How long after the last keystroke an autosaving editor asks the server to
 * persist, when an `autosave` method was named by the call site.
 *
 * Longer than IDLE_SYNC_MS by design, and the gap is what makes the ordering
 * safe: by the time this fires, sync() has already put the current document
 * into the Livewire property, so the request carries what is on screen rather
 * than the previous pause's text.
 *
 * Reset on every keystroke, so this is "six seconds after you stop typing",
 * not "every six seconds" — a paragraph typed without pause costs one write.
 */
const AUTOSAVE_MS = 6000

/**
 * TipTap, fetched on first use rather than bundled into app.js.
 *
 * ProseMirror and its schema are around 300 KB minified — more than the rest of
 * this application's JavaScript put together. Importing it statically would
 * make the dashboard, the board, the statistics screens and every other page
 * download an editor they will never show, which is the opposite of the
 * "keep the form fast" requirement.
 *
 * Vite turns this dynamic import into its own chunk automatically. The promise
 * is cached in the module, so a page with two editors fetches it once and the
 * second one initialises from memory.
 */
let bundle = null

function loadEditor() {
    return bundle ??= Promise.all([
        import('@tiptap/core'),
        import('@tiptap/starter-kit'),
        import('@tiptap/extension-list'),
        import('@tiptap/extension-table'),
        import('@tiptap/extension-image'),

        // Already in the tree as a dependency of StarterKit, so this costs
        // nothing extra. Placeholder is not one of the extensions StarterKit
        // enables by default.
        import('@tiptap/extensions'),
    ]).then(([core, starterKit, list, table, image, extras]) => ({
        Editor: core.Editor,
        StarterKit: starterKit.default ?? starterKit.StarterKit,
        TaskList: list.TaskList,
        TaskItem: list.TaskItem,
        TableKit: table.TableKit,
        Image: image.default ?? image.Image,
        Placeholder: extras.Placeholder,
    }))
}

/**
 * The marks and nodes the toolbar can toggle, and how.
 *
 * Kept as data so the toolbar template and the keyboard shortcuts cannot
 * disagree about what a button does, and so `isActive` has one spelling.
 */
const COMMANDS = {
    bold: { active: 'bold', run: (c) => c.toggleBold() },
    italic: { active: 'italic', run: (c) => c.toggleItalic() },
    underline: { active: 'underline', run: (c) => c.toggleUnderline() },
    strike: { active: 'strike', run: (c) => c.toggleStrike() },
    code: { active: 'code', run: (c) => c.toggleCode() },

    paragraph: { active: 'paragraph', run: (c) => c.setParagraph() },
    h1: { active: ['heading', { level: 1 }], run: (c) => c.toggleHeading({ level: 1 }) },
    h2: { active: ['heading', { level: 2 }], run: (c) => c.toggleHeading({ level: 2 }) },
    h3: { active: ['heading', { level: 3 }], run: (c) => c.toggleHeading({ level: 3 }) },

    bulletList: { active: 'bulletList', run: (c) => c.toggleBulletList() },
    orderedList: { active: 'orderedList', run: (c) => c.toggleOrderedList() },
    taskList: { active: 'taskList', run: (c) => c.toggleList('taskList', 'taskItem') },

    blockquote: { active: 'blockquote', run: (c) => c.toggleBlockquote() },
    codeBlock: { active: 'codeBlock', run: (c) => c.toggleCodeBlock() },
    rule: { active: null, run: (c) => c.setHorizontalRule() },

    undo: { active: null, run: (c) => c.undo() },
    redo: { active: null, run: (c) => c.redo() },
}

/**
 * State the toolbar needs but has no button that toggles it.
 *
 * A link is applied through promptForLink() rather than a plain toggle, but the
 * button still has to show whether the cursor is inside one — otherwise there
 * is no way to tell "add a link" from "edit this link".
 */
const TRACKED = ['link']

/** Block types offered by the style select, in the order it lists them. */
const BLOCKS = ['h1', 'h2', 'h3']

/**
 * Build the extension set.
 *
 * Heading levels stop at three because resources/css/app.css only styles h1-h4
 * inside .markdown, and offering a level the read view renders as body text
 * would be a control that appears to do nothing.
 */
function extensions({ StarterKit, TaskList, TaskItem, TableKit, Image, Placeholder }, placeholder) {
    return [
        Placeholder.configure({ placeholder: placeholder || '' }),

        StarterKit.configure({
            heading: { levels: [1, 2, 3] },

            /*
             * Links are inserted through the toolbar's prompt, never by typing.
             * openOnClick would navigate away mid-edit, which in a Livewire
             * application means losing the unsaved document.
             */
            link: {
                openOnClick: false,
                autolink: true,
                protocols: ['http', 'https', 'mailto'],
                HTMLAttributes: { rel: 'noopener noreferrer nofollow' },
            },
        }),

        TaskList,

        // nested:true so a checklist can have sub-items, which GFM supports and
        // App\Support\RichText round-trips.
        TaskItem.configure({ nested: true }),

        TableKit.configure({
            table: { resizable: false },
        }),

        /*
         * inline:false — an image is its own block.
         *
         * allowBase64 is deliberately NOT set. A pasted screenshot goes through
         * the upload path below and comes back as a URL on the application's
         * authorized attachment route; a base64 image would put the bytes in
         * the description column, where they would be readable by anyone who
         * can read the text and invisible to AttachmentPolicy.
         */
        Image.configure({ inline: false, allowBase64: false }),
    ]
}

function component({ initialHtml, property, uploader, canUpload, placeholder, autosave }) {
    return {
        /** The TipTap instance. Not reactive: Alpine must not proxy it. */
        editor: null,

        /** Mirrors editor state so the toolbar can style itself. */
        state: {},

        /** False until the bundle has arrived and the surface is live. */
        ready: false,

        uploading: false,
        uploadError: '',

        /** Set once the document differs from what the server sent. */
        dirty: false,

        /**
         * What the indicator says: clean, dirty, saving, saved or failed.
         *
         * Only rendered when this editor autosaves. Without one, "is my work
         * safe?" has no answer on screen, which is the question a timer-based
         * save makes people ask.
         */
        saveState: 'clean',

        /**
         * Bumped on every edit, so a reply that arrives after further typing
         * cannot declare the document clean. Without it, typing during a save
         * silently loses its dirty flag and the next autosave never fires.
         */
        revision: 0,

        idleTimer: null,
        autosaveTimer: null,

        /** The enclosing <form>, and the submit listener attached to it. */
        form: null,
        onSubmit: null,

        /** Window-level guards, removed again by destroy(). */
        onUnload: null,
        onNavigate: null,

        /** Set by destroy(), so a late-arriving bundle does not start up. */
        gone: false,

        /**
         * Alpine does not await init(), which is exactly what is wanted here:
         * the toolbar renders immediately, `ready` stays false, and the surface
         * comes alive when the chunk lands. Every method below guards on
         * `this.editor`, so a click in that window is a no-op rather than an
         * error.
         */
        init() {
            this.attachToForm()
            this.attachGuards()

            loadEditor()
                .then((tiptap) => this.start(tiptap))
                .catch(() => {
                    this.uploadError = 'The editor could not be loaded. Switch to Markdown to keep working.'
                })
        },

        start(tiptap) {
            // The element left the DOM while the bundle was in flight.
            if (this.gone || !this.$refs.surface) {
                return
            }

            this.editor = new tiptap.Editor({
                element: this.$refs.surface,
                extensions: extensions(tiptap, placeholder),
                content: initialHtml || '',
                editorProps: {
                    attributes: {
                        // .markdown is the same class the read view uses, so
                        // what is being typed looks like what will be saved.
                        class: 'markdown editor-surface',
                        // Announced as a rich text field rather than a text box.
                        role: 'textbox',
                        'aria-multiline': 'true',
                    },
                    handlePaste: (view, event) => this.handlePaste(event),
                    handleDrop: (view, event) => this.handleDrop(event),
                },
                onTransaction: () => this.refreshState(),
                onUpdate: () => this.touched(),
                onBlur: () => this.sync(),
            })

            this.refreshState()
            this.ready = true
        },

        /**
         * The submit guard.
         *
         * Registered in the capture phase on the enclosing form so it runs
         * before Livewire's own submit handler reads the component state.
         * Without it, a save pressed within IDLE_SYNC_MS of the last keystroke
         * stores the previous draft — the one moment somebody is watching.
         *
         * Attached in init() rather than in start(), so it is in place even if
         * the editor chunk is still downloading when the form is submitted.
         */
        attachToForm() {
            this.form = this.$el.closest('form')

            if (this.form) {
                this.onSubmit = () => this.sync()
                this.form.addEventListener('submit', this.onSubmit, { capture: true })
            }
        },

        /**
         * Two ways an unsaved document can be walked away from.
         *
         * beforeunload covers closing the tab, a hard refresh and typing a new
         * address. It cannot ask a custom question — browsers show their own
         * wording — so all it does is make the browser ask at all.
         *
         * wire:navigate is the other one, and it needs opposite treatment.
         * It never unloads the document, so beforeunload does not fire; but it
         * does tear this component down. Blocking it would mean interrogating
         * somebody for clicking a page in the sidebar, so an autosaving editor
         * flushes instead and lets the navigation continue. The request is
         * already in flight when the component goes, and a same-document
         * navigation does not cancel it.
         */
        attachGuards() {
            this.onUnload = (event) => {
                if (!this.dirty) {
                    return
                }

                event.preventDefault()

                // Older browsers want a returned string; current ones ignore
                // it and show their own wording.
                event.returnValue = ''

                return ''
            }

            window.addEventListener('beforeunload', this.onUnload)

            if (!autosave) {
                return
            }

            this.onNavigate = () => {
                if (this.dirty) {
                    this.runAutosave()
                }
            }

            document.addEventListener('livewire:navigate', this.onNavigate)
        },

        /**
         * Alpine's teardown hook for a data component, called when the element
         * leaves the DOM.
         *
         * Not optional. Livewire removes this element on wire:navigate and on
         * any re-render that replaces the container, and ProseMirror keeps a
         * mutation observer plus document-level listeners alive until destroy()
         * is called — so without this, navigating between tickets leaks an
         * editor per visit and the stale ones keep answering key events.
         */
        destroy() {
            this.gone = true

            if (this.form && this.onSubmit) {
                this.form.removeEventListener('submit', this.onSubmit, { capture: true })
            }

            if (this.onUnload) {
                window.removeEventListener('beforeunload', this.onUnload)
            }

            if (this.onNavigate) {
                document.removeEventListener('livewire:navigate', this.onNavigate)
            }

            clearTimeout(this.idleTimer)
            clearTimeout(this.autosaveTimer)
            this.editor?.destroy()
            this.editor = null
        },

        // -------------------------------------------------------------
        // Toolbar
        // -------------------------------------------------------------

        /** Run a named command and keep focus in the document. */
        run(name) {
            const command = COMMANDS[name]

            if (!command || !this.editor) {
                return
            }

            command.run(this.editor.chain().focus()).run()
        },

        isActive(name) {
            return this.state[name] === true
        },

        /**
         * Snapshot which commands are active.
         *
         * Recomputed on every transaction rather than read from templates,
         * because `editor.isActive()` is a method call on a non-reactive object
         * — Alpine would never know to re-render the toolbar.
         */
        refreshState() {
            if (!this.editor) {
                return
            }

            const next = {}

            for (const [name, command] of Object.entries(COMMANDS)) {
                if (command.active === null) {
                    continue
                }

                next[name] = Array.isArray(command.active)
                    ? this.editor.isActive(...command.active)
                    : this.editor.isActive(command.active)
            }

            for (const name of TRACKED) {
                next[name] = this.editor.isActive(name)
            }

            next.canUndo = this.editor.can().undo()
            next.canRedo = this.editor.can().redo()
            next.inTable = this.editor.isActive('table')

            this.state = next
        },

        /**
         * Which option the block-style select should show.
         *
         * Read from editor state rather than tracked separately, so moving the
         * cursor into a heading updates the select without anything having to
         * remember it did.
         */
        currentBlock() {
            return BLOCKS.find((name) => this.state[name] === true) ?? 'paragraph'
        },

        /**
         * Add, change or remove a link.
         *
         * Asked through the application's own dialog rather than
         * `window.prompt`. That is not cosmetic: resources/js/dialog.js exists
         * precisely to replace the native dialogs, and Tests\Feature\UI\DialogTest
         * scans this directory to make sure none come back.
         *
         * Submitting an empty field removes the link, which is why the dialog
         * distinguishes "dismissed" (null) from "submitted empty" ('').
         *
         * The scheme is checked here for a clear message and refused again on
         * the server by App\Support\RichText\EditorHtml — that second one is
         * the check that counts, since this one runs in the browser.
         */
        promptForLink() {
            const previous = this.editor?.getAttributes('link').href ?? ''

            window.dialog.ask({
                title: previous ? 'Edit link' : 'Add link',
                inputLabel: 'Address',
                placeholder: 'https://example.com',
                value: previous,
                confirmText: 'Apply',
            }).then((url) => {
                if (url === null || !this.editor) {
                    return
                }

                if (url === '') {
                    this.editor.chain().focus().unsetLink().run()

                    return
                }

                if (!/^(https?:|mailto:|\/|#)/i.test(url)) {
                    this.uploadError = 'A link must start with https://, mailto: or /.'

                    return
                }

                this.uploadError = ''
                this.editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run()
            })
        },

        insertTable() {
            this.editor?.chain().focus()
                .insertTable({ rows: 3, cols: 3, withHeaderRow: true })
                .run()
        },

        // -------------------------------------------------------------
        // Attachments
        // -------------------------------------------------------------

        /**
         * Paste of an image becomes an upload.
         *
         * Returning false lets ProseMirror handle everything else normally —
         * pasted HTML from a page, or plain text — which it filters through the
         * schema, so a pasted `<script>` never becomes a node in the first
         * place.
         */
        handlePaste(event) {
            const files = imageFiles(event.clipboardData)

            if (files.length === 0 || !canUpload) {
                return false
            }

            event.preventDefault()
            this.upload(files)

            return true
        },

        handleDrop(event) {
            const files = Array.from(event.dataTransfer?.files ?? [])

            if (files.length === 0 || !canUpload) {
                return false
            }

            event.preventDefault()
            this.upload(files)

            return true
        },

        /** The toolbar's file button. */
        chooseFiles() {
            this.$refs.file?.click()
        },

        filesChosen(event) {
            const files = Array.from(event.target.files ?? [])

            if (files.length > 0) {
                this.upload(files)
            }

            // Cleared so choosing the same file twice in a row still fires.
            event.target.value = ''
        },

        /**
         * Hand files to the Livewire component, which stores them through
         * App\Services\AttachmentStorage and answers with the authorized
         * download URL for each.
         *
         * The URL comes back from the server rather than being guessed here,
         * because it is route('attachments.show', $attachment) — the only URL
         * that re-checks who is allowed to see the file.
         */
        upload(files) {
            if (!canUpload || files.length === 0) {
                return
            }

            this.uploading = true
            this.uploadError = ''

            this.$wire.uploadMultiple(
                uploader,
                files,
                () => {
                    this.$wire.call('attachUploads').then((inserted) => {
                        this.uploading = false
                        this.insertAttachments(inserted ?? [])
                    }).catch(() => {
                        this.uploading = false
                        this.uploadError = 'That upload could not be saved.'
                    })
                },
                () => {
                    this.uploading = false
                    this.uploadError = 'That upload could not be saved.'
                },
            )
        },

        /**
         * @param {Array<{url: string, name: string, image: boolean}>} attachments
         */
        insertAttachments(attachments) {
            if (!this.editor || attachments.length === 0) {
                return
            }

            const chain = this.editor.chain().focus()

            attachments.forEach(({ url, name, image }) => {
                if (image) {
                    chain.setImage({ src: url, alt: name })
                } else {
                    chain.insertContent({
                        type: 'paragraph',
                        content: [{
                            type: 'text',
                            text: name,
                            marks: [{ type: 'link', attrs: { href: url } }],
                        }],
                    })
                }
            })

            chain.run()

            this.touched()
            this.sync()
        },

        // -------------------------------------------------------------
        // Livewire
        // -------------------------------------------------------------

        /**
         * The document changed.
         *
         * One entry point for both timers, so the two can never disagree about
         * whether there is unsaved work.
         */
        touched() {
            this.dirty = true
            this.revision++

            if (autosave) {
                this.saveState = 'dirty'
            }

            this.scheduleSync()
            this.scheduleAutosave()
        },

        scheduleSync() {
            clearTimeout(this.idleTimer)
            this.idleTimer = setTimeout(() => this.sync(), IDLE_SYNC_MS)
        },

        scheduleAutosave() {
            if (!autosave) {
                return
            }

            clearTimeout(this.autosaveTimer)
            this.autosaveTimer = setTimeout(() => this.runAutosave(), AUTOSAVE_MS)
        },

        /**
         * Ask the server to persist, and say what happened.
         *
         * sync() first, unconditionally: the property has to hold the current
         * document before the request is built. Both calls are cheap — one
         * assignment, no round trip.
         */
        runAutosave() {
            if (!autosave || !this.dirty) {
                return
            }

            clearTimeout(this.autosaveTimer)
            this.sync()

            const revision = this.revision

            this.saveState = 'saving'

            this.$wire.call(autosave).then((status) => {
                if (status === 'invalid') {
                    // The server put its reason in the error bag, which the
                    // form is already rendering; the indicator only has to
                    // stop claiming the work is safe.
                    this.saveState = 'failed'

                    return
                }

                /*
                 * Anything typed while the request was out keeps the document
                 * dirty and leaves its own timer running. Declaring it clean
                 * here would strand those keystrokes: no dirty flag, so no
                 * further autosave, and nothing on screen saying so.
                 */
                if (this.revision !== revision) {
                    return
                }

                this.dirty = false
                this.saveState = 'saved'
            }).catch(() => {
                this.saveState = 'failed'
            })
        },

        /**
         * Push the document to the server-side property.
         *
         * `$wire.set(prop, value, false)` — the third argument is what keeps
         * this cheap. It updates the property without a server round trip, so
         * typing costs nothing and the value is simply present in the payload
         * of whichever request happens next.
         */
        sync() {
            clearTimeout(this.idleTimer)

            if (!this.editor || !this.dirty) {
                return
            }

            this.$wire.set(property, this.editor.getHTML(), false)
        },
    }
}

/** Image files out of a clipboard or drop payload. */
function imageFiles(source) {
    return Array.from(source?.files ?? []).filter((file) => file.type.startsWith('image/'))
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('richEditor', component)
})
