/*
 * Spoken conversation with Nexora AI, as the `aiVoice` Alpine component.
 *
 * The browser's half of the feature, and it is deliberately the only half that
 * touches a microphone or a speaker. Everything it does is one of three things:
 * record, hand the recording to the server, play back audio the server
 * produced. It holds no vendor credential, opens no socket to a vendor, and
 * never decides what the assistant said — it asks the server to read a specific
 * stored turn aloud, by id.
 *
 * The state machine
 * -----------------
 *     idle ──▶ listening ──▶ processing ──▶ speaking ──▶ idle
 *       ▲          │              │             │
 *       └──────────┴──────────────┴─────────────┘
 *
 * Every arrow back to idle is reachable from every state, because a
 * conversation that gets stuck in "processing" with the microphone light on is
 * worse than one that fails. stop() is therefore always safe to call, and the
 * component releases the microphone track on every exit — a MediaStream left
 * open keeps the browser's recording indicator lit, which people reasonably
 * read as being listened to.
 *
 * `muted` is about the speaker, not the microphone: it suppresses playback of
 * answers while leaving the rest of the conversation working. A separate flag
 * rather than a state, because it survives turns.
 */

const IDLE = 'idle'
const LISTENING = 'listening'
const PROCESSING = 'processing'
const SPEAKING = 'speaking'

/*
 * Recording is capped in the browser as well as on the server.
 *
 * The server refuses anything over ten megabytes, which is the real limit; this
 * one is about the person. A microphone left open by accident should stop by
 * itself rather than upload two minutes of a room.
 */
const MAX_RECORDING_MS = 60_000

function aiVoice(config = {}) {
    return {
        state: IDLE,
        muted: false,
        error: null,

        /** Set from the server: whether voice is configured at all. */
        available: Boolean(config.available),

        /** The sentence to show when it is not. */
        unavailableReason: config.reason || null,

        /** Which voice reads the answers, when the deployment offers a choice. */
        voice: config.voice || null,

        recorder: null,
        stream: null,
        chunks: [],
        audio: null,
        stopTimer: null,
        abandoned: false,

        init() {
            /*
             * MediaRecorder and getUserMedia are not universal, and an
             * insecure origin has neither. Reported as unavailable with a
             * reason rather than as a button that does nothing when pressed.
             */
            if (this.available && !this.supported()) {
                this.available = false
                this.unavailableReason =
                    'This browser cannot record audio on this connection. Voice needs a secure (https) page and a recent browser.'
            }

            document.addEventListener('livewire:navigating', () => this.reset(), { once: false })

            /*
             * Closing the panel ends the turn.
             *
             * A microphone that keeps recording into a drawer somebody has
             * dismissed is the one behaviour nobody would forgive.
             *
             * A $watch on the store rather than an x-effect calling stop():
             * stop() reads and writes this component's own reactive state, so
             * an effect around it would take a dependency on everything it
             * touches and re-run itself every time a recording started. A
             * watcher fires on one value changing, which is the actual
             * trigger.
             */
            this.$watch('$store.aiPanel.open', (open) => {
                if (! open) this.stop()
            })
        },

        supported() {
            return (
                typeof window !== 'undefined' &&
                typeof window.MediaRecorder !== 'undefined' &&
                Boolean(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)
            )
        },

        get listening() {
            return this.state === LISTENING
        },

        get processing() {
            return this.state === PROCESSING
        },

        get speaking() {
            return this.state === SPEAKING
        },

        get busy() {
            return this.state !== IDLE
        },

        /** One label for the whole machine, so the markup has no branches. */
        get label() {
            if (!this.available) return 'Voice unavailable'

            switch (this.state) {
                case LISTENING:
                    return 'Listening — press to stop'
                case PROCESSING:
                    return 'Thinking…'
                case SPEAKING:
                    return 'Speaking — press to stop'
                default:
                    return 'Start a voice conversation'
            }
        },

        // -----------------------------------------------------------------

        async toggle() {
            if (!this.available) return

            if (this.state === LISTENING) {
                this.finishRecording()
                return
            }

            if (this.state === SPEAKING) {
                this.silence()
                this.state = IDLE
                return
            }

            if (this.state === PROCESSING) return

            await this.start()
        },

        async start() {
            this.error = null

            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ audio: true })
            } catch (exception) {
                /*
                 * A refused permission is not an error to log — it is an
                 * answer, and the useful response is to say what to do about
                 * it. Naming the browser control is more helpful than
                 * repeating the exception.
                 */
                this.error =
                    exception && exception.name === 'NotAllowedError'
                        ? 'Microphone access was refused. Allow it for this site in the browser address bar, then try again.'
                        : 'No microphone could be opened. Check that one is connected and not in use elsewhere.'

                this.reset()
                return
            }

            this.chunks = []

            try {
                this.recorder = new MediaRecorder(this.stream)
            } catch {
                this.error = 'This browser could not start a recording.'
                this.reset()
                return
            }

            this.recorder.addEventListener('dataavailable', (event) => {
                if (event.data && event.data.size > 0) this.chunks.push(event.data)
            })

            this.recorder.addEventListener('stop', () => {
                // Abandoned means stop() was pressed rather than the turn
                // finishing: the recording is dropped instead of sent. A
                // flag rather than removing the listener, because the
                // listener was added with addEventListener and clearing
                // `onstop` would not detach it.
                if (this.abandoned) {
                    this.abandoned = false
                    this.chunks = []

                    return
                }

                this.transcribe()
            })

            this.recorder.start()
            this.state = LISTENING

            // The safety net described at the top of the file.
            this.stopTimer = window.setTimeout(() => this.finishRecording(), MAX_RECORDING_MS)
        },

        finishRecording() {
            this.clearTimer()

            if (this.recorder && this.recorder.state !== 'inactive') {
                // The `stop` listener above continues the flow. Setting the
                // state here rather than there means the button changes the
                // instant it is pressed.
                this.state = PROCESSING
                this.recorder.stop()
                return
            }

            this.reset()
        },

        async transcribe() {
            this.releaseMicrophone()

            const blob = new Blob(this.chunks, { type: this.chunks[0]?.type || 'audio/webm' })

            this.chunks = []

            if (blob.size === 0) {
                this.error = 'Nothing was recorded. Check the microphone and try again.'
                this.state = IDLE
                return
            }

            const body = new FormData()

            body.append('audio', blob, 'turn.webm')

            let text = ''

            try {
                const response = await fetch(config.listenUrl, {
                    method: 'POST',
                    body,
                    headers: {
                        'X-CSRF-TOKEN': this.csrf(),
                        Accept: 'application/json',
                    },
                })

                const payload = await response.json().catch(() => ({}))

                if (!response.ok) {
                    // The server writes these sentences for the person, so they
                    // are shown verbatim rather than replaced with a generic
                    // failure.
                    this.error = payload.message || 'That recording could not be transcribed.'
                    this.state = IDLE
                    return
                }

                text = String(payload.text || '').trim()
            } catch {
                this.error = 'The recording could not be sent. Check the connection and try again.'
                this.state = IDLE
                return
            }

            if (text === '') {
                this.error = 'Nothing could be made out in that recording.'
                this.state = IDLE
                return
            }

            await this.ask(text)
        },

        /**
         * Hand the transcript to the ordinary assistant, then play the answer.
         *
         * `sendSpoken` returns the id of the turn it produced, or null when the
         * question was refused. Playing by id is what keeps the browser unable
         * to make the assistant say anything it did not actually answer.
         */
        async ask(text) {
            this.state = PROCESSING

            let answerId = null

            try {
                answerId = await this.$wire.sendSpoken(text)
            } catch {
                this.error = 'The question could not be sent.'
                this.state = IDLE
                return
            }

            if (!answerId || this.muted) {
                this.state = IDLE
                return
            }

            await this.play(answerId)
        },

        async play(messageId) {
            this.silence()

            const url = config.speakUrl.replace('__ID__', String(messageId)) +
                (this.voice ? `?voice=${encodeURIComponent(this.voice)}` : '')

            this.state = SPEAKING

            try {
                const response = await fetch(url, { headers: { Accept: 'audio/mpeg' } })

                if (!response.ok) {
                    const payload = await response.json().catch(() => ({}))

                    this.error = payload.message || 'The answer could not be read aloud. It is on screen.'
                    this.state = IDLE
                    return
                }

                const blob = await response.blob()

                this.audio = new Audio(URL.createObjectURL(blob))

                this.audio.addEventListener('ended', () => {
                    this.state = IDLE
                    this.silence()
                })

                await this.audio.play()
            } catch {
                /*
                 * Autoplay policies block playback when the person has not
                 * interacted recently. They pressed a button to get here, so
                 * this is rare — and the answer is on screen either way, which
                 * is what the message says.
                 */
                this.error = 'The answer could not be played. It is on screen.'
                this.state = IDLE
            }
        },

        toggleMute() {
            this.muted = !this.muted

            if (this.muted && this.state === SPEAKING) {
                this.silence()
                this.state = IDLE
            }
        },

        /** Stop everything, whatever state it is in. */
        stop() {
            this.clearTimer()
            this.silence()

            if (this.recorder && this.recorder.state !== 'inactive') {
                this.abandoned = true
                this.recorder.stop()
            }

            this.reset()
        },

        // -----------------------------------------------------------------

        silence() {
            if (!this.audio) return

            this.audio.pause()

            // The object URL holds the blob alive; released here so a long
            // conversation does not accumulate audio in memory.
            if (this.audio.src.startsWith('blob:')) URL.revokeObjectURL(this.audio.src)

            this.audio = null
        },

        releaseMicrophone() {
            if (!this.stream) return

            this.stream.getTracks().forEach((track) => track.stop())
            this.stream = null
        },

        clearTimer() {
            if (this.stopTimer === null) return

            window.clearTimeout(this.stopTimer)
            this.stopTimer = null
        },

        reset() {
            this.clearTimer()
            this.releaseMicrophone()
            this.recorder = null
            this.chunks = []
            this.abandoned = false
            this.state = IDLE
        },

        csrf() {
            return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        },
    }
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('aiVoice', aiVoice)
})
