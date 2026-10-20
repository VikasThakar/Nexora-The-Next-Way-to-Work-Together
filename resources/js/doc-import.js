/*
 * The documentation screen, as the `docsPage` Alpine component: the page-tree
 * drawer on a narrow screen, and importing files as pages.
 *
 * Importing has three entry points — the Import button in the sidebar, the
 * empty state's button, and "Import a file inside this page" — and two drop
 * areas: the sidebar and the empty state. Never the editor: a file dropped on
 * the page being written goes into that page, as it always has (see
 * ./editor), and the editor's handler does not stop the event, so a drop area
 * around it would import the same file as a second page.
 *
 *
 * Not reacting to a page being dragged
 * ------------------------------------
 * The tree reorders pages with x-sort, and those drags fire the same events.
 * A file from the operating system carries 'Files' in `dataTransfer.types` and
 * a page from the tree does not, so every handler returns early without it —
 * the same test ./drop-zone uses on the board.
 *
 *
 * Batches
 * -------
 * The server takes at most 32 MB in one request (nginx and PHP agree on that),
 * so a drop of several large files goes up in batches under `batchBytes`, one
 * after another. Livewire calls updatedImports() as each batch lands and this
 * calls finishImport() after the last, which is what opens the first new page.
 *
 *
 * The file picker
 * ---------------
 * Opened in the same click that asked for it. Safari ignores a programmatic
 * click on a file input once the click that caused it is more than a moment
 * old, so the page to file under goes to the server *deferred*, with the next
 * request, rather than by a round trip before the picker opens.
 */

function carriesFiles(event) {
    return Array.from(event.dataTransfer?.types ?? []).includes('Files')
}

function component(config) {
    const enabled = config !== null && typeof config === 'object'
    const { maxFiles = 10, maxBytes = 20 * 1024 * 1024, batchBytes = 28 * 1024 * 1024 } = enabled ? config : {}

    return {
        treeOpen: false,

        canImport: enabled,

        /** A file drag is over a drop area. */
        dragging: false,

        /** dragenter/leave fire per child crossed; counting keeps it steady. */
        depth: 0,

        importing: false,
        progress: 0,
        notice: '',

        dragEnter(event) {
            if (!enabled || !carriesFiles(event) || event.defaultPrevented) {
                return
            }

            event.preventDefault()
            this.depth++
            this.dragging = true
        },

        dragOver(event) {
            if (!enabled || !carriesFiles(event)) {
                return
            }

            // Without this the browser opens the file instead of dropping it.
            event.preventDefault()

            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'copy'
            }
        },

        dragLeave(event) {
            if (!enabled || !carriesFiles(event)) {
                return
            }

            this.depth = Math.max(0, this.depth - 1)

            if (this.depth === 0) {
                this.dragging = false
            }
        },

        drop(event) {
            this.depth = 0
            this.dragging = false

            // Taken already — by the editor, say — or not files at all.
            if (!enabled || !carriesFiles(event) || event.defaultPrevented) {
                return
            }

            event.preventDefault()

            // A drop on the sidebar or the empty state files at the top level.
            this.$wire.$set('importParentId', null, false)

            this.send(Array.from(event.dataTransfer?.files ?? []))
        },

        /** The Import buttons; `parentId` files the import under that page. */
        choose(parentId = null) {
            if (!enabled || this.importing) {
                return
            }

            this.$wire.$set('importParentId', parentId, false)

            const picker = this.$refs.importPicker

            if (picker) {
                picker.value = ''
                picker.click()
            }
        },

        picked(event) {
            this.send(Array.from(event.target.files ?? []))
        },

        async send(files) {
            if (files.length === 0 || this.importing) {
                return
            }

            this.notice = ''

            if (files.length > maxFiles) {
                this.notice = `Only the first ${maxFiles} files are imported at once.`
                files = files.slice(0, maxFiles)
            }

            // Too large to send at all: the server would refuse it anyway, and
            // saying so here saves uploading twenty megabytes to be told.
            const tooLarge = files.filter((file) => file.size > maxBytes)

            if (tooLarge.length > 0) {
                const limit = Math.round(maxBytes / (1024 * 1024))
                this.notice = `${tooLarge.map((file) => file.name).join(', ')}: files can be at most ${limit} MB.`
                files = files.filter((file) => file.size <= maxBytes)
            }

            if (files.length === 0) {
                return
            }

            this.importing = true
            this.progress = 0

            const batches = []
            let batch = []
            let size = 0

            for (const file of files) {
                if (batch.length > 0 && size + file.size > batchBytes) {
                    batches.push(batch)
                    batch = []
                    size = 0
                }

                batch.push(file)
                size += file.size
            }

            if (batch.length > 0) {
                batches.push(batch)
            }

            try {
                for (const [index, part] of batches.entries()) {
                    await new Promise((resolve) => {
                        this.$wire.uploadMultiple(
                            'imports',
                            part,
                            resolve,
                            () => {
                                this.notice = 'An upload could not be completed. Try again.'
                                resolve()
                            },
                            (event) => {
                                const share = (event.detail?.progress ?? 0) / 100
                                this.progress = Math.round(((index + share) / batches.length) * 100)
                            },
                        )
                    })
                }

                await this.$wire.finishImport()
            } finally {
                this.importing = false
                this.progress = 0
            }
        },
    }
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('docsPage', component)
})
