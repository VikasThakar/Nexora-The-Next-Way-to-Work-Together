/*
 * Files by drop, paste or picker, into a Livewire file input.
 *
 * Registered as the `fileDrop` Alpine component and used wherever a form
 * accepts files without a rich editor: the board's quick-add card and the
 * assistant's composer. The rich text editor has its own handling in
 * ./editor.js because ProseMirror owns paste and drop on its surface.
 *
 * The mechanism is deliberately dumb. Whatever the source — a drop anywhere
 * inside the element, a paste with files on the clipboard, or the picker —
 * the files are assigned to the one `<input type="file">` the component owns
 * (x-ref="picker") and a `change` event is dispatched on it. That input has
 * wire:model, so Livewire uploads exactly as if the files had been chosen
 * by hand, and nothing here knows how the upload works or where it goes.
 *
 * Progress is real progress from Livewire's upload events, which bubble to
 * this element from the input; a 30 MB recording on a slow connection takes
 * long enough that a spinner would be a lie.
 */
function component() {
    return {
        dragging: false,
        uploading: false,
        progress: 0,

        // A dragenter fires for every child crossed; the counter keeps the
        // highlight steady until the pointer actually leaves the element.
        depth: 0,

        init() {
            this.onStart = () => { this.uploading = true; this.progress = 0 }
            this.onEnd = () => { this.uploading = false; this.progress = 0 }
            this.onProgress = (event) => { this.progress = event.detail?.progress ?? 0 }

            this.$el.addEventListener('livewire-upload-start', this.onStart)
            this.$el.addEventListener('livewire-upload-finish', this.onEnd)
            this.$el.addEventListener('livewire-upload-cancel', this.onEnd)
            this.$el.addEventListener('livewire-upload-error', this.onEnd)
            this.$el.addEventListener('livewire-upload-progress', this.onProgress)
        },

        destroy() {
            this.$el.removeEventListener('livewire-upload-start', this.onStart)
            this.$el.removeEventListener('livewire-upload-finish', this.onEnd)
            this.$el.removeEventListener('livewire-upload-cancel', this.onEnd)
            this.$el.removeEventListener('livewire-upload-error', this.onEnd)
            this.$el.removeEventListener('livewire-upload-progress', this.onProgress)
        },

        dragEnter(event) {
            if (!hasFiles(event.dataTransfer)) return

            this.depth++
            this.dragging = true
        },

        dragLeave() {
            this.depth = Math.max(0, this.depth - 1)

            if (this.depth === 0) this.dragging = false
        },

        drop(event) {
            this.depth = 0
            this.dragging = false

            this.give(Array.from(event.dataTransfer?.files ?? []))
        },

        /**
         * A paste with files on the clipboard — a screenshot, most of the
         * time. Text pastes are left entirely alone so the textarea behaves.
         */
        paste(event) {
            const files = Array.from(event.clipboardData?.files ?? [])

            if (files.length === 0) return

            event.preventDefault()
            this.give(files)
        },

        choose() {
            this.$refs.picker?.click()
        },

        /**
         * Hand files to the Livewire-bound input.
         *
         * @param {File[]} files
         */
        give(files) {
            const input = this.$refs.picker

            if (!input || files.length === 0 || input.disabled) return

            const transfer = new DataTransfer()

            files.forEach((file) => transfer.items.add(file))

            input.files = transfer.files
            input.dispatchEvent(new Event('change', { bubbles: true }))
        },
    }
}

function hasFiles(transfer) {
    return Array.from(transfer?.types ?? []).includes('Files')
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('fileDrop', component)
})
