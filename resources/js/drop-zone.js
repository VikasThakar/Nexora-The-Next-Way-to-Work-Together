/*
 * A place to drop files, as the `dropZone` Alpine component.
 *
 * Used by the board's inline quick add, which has no rich editor to drop into —
 * see resources/js/editor.js for the other half of this, where a drop lands
 * inside a ProseMirror document and comes back as an inline image.
 *
 * Here there is no document, so the job is smaller: take the files, hand them
 * to Livewire, and let the server re-render the card with what it stored. The
 * browser never learns where a file lives, which is deliberate — a quick-add
 * thumbnail is an <img> pointing at route('attachments.show'), exactly like
 * every other attachment in the product, and that route is the thing that
 * re-checks who is allowed to see it.
 *
 *
 * Why this is not wire:model on a file input
 * ------------------------------------------
 * It is, underneath — `uploadMultiple` writes the same property an input would.
 * What a bare input cannot do is the part people actually want: dragging a
 * screenshot from the desktop onto the card. That needs the drag events, the
 * highlight that says the card will take it, and a guard against the board's
 * own card dragging, none of which an input gives you.
 *
 *
 * Not reacting to a card being dragged
 * ------------------------------------
 * The Kanban board drags tickets with x-sort, and those drags fire exactly the
 * same dragenter/dragover/drop events on whatever they pass over. The two are
 * told apart by `dataTransfer.types`: a file dragged from the operating system
 * carries 'Files', and a card dragged from the column next door does not. Every
 * handler below returns early when it is absent, so dragging a card across an
 * open quick add neither highlights it nor swallows the drop.
 */

/**
 * Does this drag carry files from outside the page?
 *
 * `types` is the only thing readable during dragover — the files themselves are
 * deliberately not, until the drop — so it is what the decision has to be made
 * from.
 */
function carriesFiles(event) {
    return Array.from(event.dataTransfer?.types ?? []).includes('Files')
}

function component({ property, maxFiles = 20 }) {
    return {
        /** True while a file drag is over this element. */
        over: false,

        /**
         * dragenter and dragleave fire for every child element the pointer
         * crosses, so a plain boolean flickers off the moment the pointer moves
         * from the card onto the thumbnail inside it. Counting entries and
         * exits is the standard fix and the only one that survives nesting.
         */
        depth: 0,

        uploading: false,
        error: '',

        enter(event) {
            if (!carriesFiles(event)) {
                return
            }

            this.depth++
            this.over = true
        },

        leave(event) {
            if (!carriesFiles(event)) {
                return
            }

            this.depth = Math.max(0, this.depth - 1)

            if (this.depth === 0) {
                this.over = false
            }
        },

        /**
         * Allowing the drop is what `preventDefault` on dragover means; without
         * it the browser navigates to the file instead, replacing the page and
         * losing whatever was typed into the card.
         */
        over_(event) {
            if (!carriesFiles(event)) {
                return
            }

            event.preventDefault()
        },

        drop(event) {
            if (!carriesFiles(event)) {
                return
            }

            event.preventDefault()

            this.depth = 0
            this.over = false

            this.send(Array.from(event.dataTransfer?.files ?? []))
        },

        /**
         * A screenshot pasted while the card has focus.
         *
         * Images only, unlike a drop. A paste carrying a file is nearly always
         * a screenshot; a paste carrying text is somebody pasting a title, and
         * swallowing that to upload a .txt would be a surprise.
         */
        paste(event) {
            const files = Array.from(event.clipboardData?.files ?? [])
                .filter((file) => file.type.startsWith('image/'))

            if (files.length === 0) {
                return
            }

            event.preventDefault()
            this.send(files)
        },

        /** The "add a file" button. */
        choose() {
            this.$refs.input?.click()
        },

        chosen(event) {
            this.send(Array.from(event.target.files ?? []))

            // Cleared so choosing the same file twice in a row still fires.
            event.target.value = ''
        },

        /**
         * Hand the files to Livewire.
         *
         * The count is checked here only to say something useful before a round
         * trip; the limit that matters is the validation rule on the server,
         * which reads the same configuration every other upload in the product
         * reads.
         */
        send(files) {
            if (files.length === 0) {
                return
            }

            if (files.length > maxFiles) {
                this.error = `Up to ${maxFiles} files at a time.`

                return
            }

            this.uploading = true
            this.error = ''

            this.$wire.uploadMultiple(
                property,
                files,
                () => {
                    this.uploading = false
                },
                () => {
                    this.uploading = false
                    this.error = 'That upload could not be saved.'
                },
            )
        },
    }
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('dropZone', component)
})
