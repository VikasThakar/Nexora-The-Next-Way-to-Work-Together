/*
 * Dragging the shell's two panels wider and narrower, as the `panelResize`
 * Alpine component.
 *
 * Nexora's application shell is a fixed 16rem navigation on the left, the
 * application in the middle, and a 28rem assistant drawer on the right. Those
 * were two numbers in a stylesheet, and the right width for them is not really
 * a design decision at all — it depends on the monitor, on how long a
 * workspace's board names are, and on whether somebody is reading a chart in
 * the assistant or asking it a one-line question. This is the handle on each of
 * those two numbers.
 *
 *
 * Two custom properties, and nothing else
 * ---------------------------------------
 * A drag writes `--nav-width` or `--ai-panel-width` and stops there. Everything
 * that needs to know reads one of those two properties from CSS: the sidebar
 * column's width, the assistant's max-width, and the padding that insets the
 * content column so the fixed assistant is not covering it. That last pair used
 * to be the same number written down twice.
 *
 * They are written into a <style> element rather than onto `html`'s style
 * attribute, because Livewire deletes <html> attributes the incoming page does
 * not have and keeps <head> assets that it does not recognise — so a style
 * attribute would be wiped by the first wire:navigate and a <style> element is
 * not. The reasoning is written out in
 * resources/views/components/app/panel-boot.blade.php, which creates it.
 *
 * It matters that the move handler touches no reactive state. Writing an Alpine
 * property sixty times a second would re-evaluate every binding in the
 * component on every frame of the drag, and writing a Livewire property would
 * put a network round trip there. Setting a property on a CSS rule re-runs CSS
 * and nothing else, which is the cheapest thing a drag can do.
 *
 *
 * Why rem and not pixels
 * ----------------------
 * The stored number is rem. resources/css/app.css sets the root font size to
 * 93.75% and its comment is explicit that this is "a *base*, not a lock": a
 * person who has raised their browser's minimum font size gets a
 * proportionally larger product. A width stored in pixels would opt the panels
 * out of that and leave somebody with large type reading their board names
 * through a rail sized for small type. Pointer coordinates arrive in pixels, so
 * they are converted on the way in.
 *
 * Note that this is *element* rem, and that the two are not interchangeable.
 * `rem` inside a media query ignores `html { font-size }` and always resolves
 * against the browser's initial 16px, so the `64rem` in the `lg` breakpoint is
 * 1024px while `64rem` in a width property would be 960px. That is why the
 * breakpoint test below asks matchMedia rather than doing the arithmetic.
 *
 *
 * Where the width is kept
 * -----------------------
 * localStorage, per browser, under `nexora.panels`. Deliberately not on the
 * `users` row beside the appearance preference: an appearance is a taste and
 * should follow somebody to their laptop, but a panel width is a fact about the
 * screen in front of them, and the width chosen on a 27-inch monitor is the
 * wrong width on a 13-inch one.
 */

const KEY = 'nexora.panels'

/*
 * Everything that differs between the two panels.
 *
 * `anchor` is the side of the viewport the panel is pinned to, and it is the
 * only thing that changes the arithmetic: the navigation's width is the
 * distance from the left edge to the handle, the assistant's is the distance
 * from the handle to the right edge.
 *
 * The bounds are the design's rather than arbitrary. The navigation cannot go
 * below 16rem because that is the width the sidebar's own contents were built
 * for — the appearance control at its foot is three labelled buttons and is
 * already at its content width there. The assistant stops being readable below
 * 20rem, where its tables and KPI grids begin scrolling sideways, and stops
 * being a chat column above 42rem.
 */
const PANELS = {
    nav: {
        property: '--nav-width',
        field: 'nav',
        anchor: 'left',
        min: 16,
        max: 24,
        fallback: 16,
    },
    ai: {
        property: '--ai-panel-width',
        field: 'ai',
        anchor: 'right',
        min: 20,
        max: 42,
        fallback: 28,
    },
}

/**
 * How little of the application itself we are willing to leave on screen.
 *
 * Each panel's own maximum is a number somebody chose; this is the one that
 * stops two individually legal panels from squeezing the product out between
 * them on a narrow laptop. It is why the effective maximum is derived from the
 * viewport rather than fixed.
 */
const MIN_CONTENT = 22

/** Two presses closer together than this are a double-click. */
const DOUBLE_PRESS_MS = 400

/** Below `lg` the navigation is an off-canvas drawer and the assistant a full-width sheet. */
function resizable() {
    return window.matchMedia('(min-width: 64rem)').matches
}

/**
 * The viewport, excluding the scrollbar.
 *
 * `window.innerWidth` includes it, and this layout always has one — which would
 * make every limit here about fifteen pixels too generous and let a drag put
 * the page one scrollbar wider than it fits.
 */
function viewport() {
    return document.documentElement.clientWidth
}

/**
 * Pixels per rem, read from the document rather than assumed.
 *
 * `html { font-size: 93.75% }` is a percentage of a browser setting the person
 * can change while the page is open. Read once per drag rather than per frame:
 * it is a computed-style read, and doing one of those on every pointermove
 * forces a style recalculation on the element the drag is mutating.
 */
function rootFontSize() {
    const size = parseFloat(getComputedStyle(document.documentElement).fontSize)

    return size > 0 ? size : 16
}

/** Two decimal places: enough to be imperceptible, little enough to stay readable in storage. */
function round(rem) {
    return Math.round(rem * 100) / 100
}

/**
 * The rule the widths are written into.
 *
 * Created by the inline script in the layout head so that a stored width is on
 * screen before the first paint; created here too, because this module must
 * work on a page where that script did not run. Falls back to the <html>
 * element's own style, which is correct for everything except surviving a
 * wire:navigate — and a page without the boot script is a page without the
 * layout that navigates.
 */
function surface() {
    let element = document.getElementById('nexora-panels')

    if (!element) {
        element = document.createElement('style')
        element.id = 'nexora-panels'
        element.textContent = ':root{}'
        document.head.appendChild(element)
    }

    try {
        const rule = element.sheet?.cssRules?.[0]

        if (rule) {
            return rule.style
        }
    } catch {
        // An unreachable stylesheet is not worth an exception.
    }

    return document.documentElement.style
}

function read() {
    try {
        const parsed = JSON.parse(window.localStorage.getItem(KEY) || '{}')

        return parsed && typeof parsed === 'object' ? parsed : {}
    } catch {
        // A browser in private mode, or one told to block site data, throws on
        // read as well as on write. A panel width is not worth an exception:
        // the chosen width simply lasts as long as the page does.
        return {}
    }
}

/**
 * Remember one panel's width.
 *
 * Read-modify-write rather than replacing the object, so that storing the
 * navigation's width never reads, moves or discards the assistant's. The two
 * are independent and have to stay that way.
 */
function write(field, rem) {
    try {
        const stored = read()

        stored[field] = rem

        window.localStorage.setItem(KEY, JSON.stringify(stored))
    } catch {
        // See read().
    }
}

/** What this panel is wearing right now, from the property the stylesheet reads. */
function applied(config) {
    const value = parseFloat(
        getComputedStyle(document.documentElement).getPropertyValue(config.property),
    )

    return Number.isFinite(value) ? value : config.fallback
}

/**
 * The width this person actually asked for, which is not always the one on
 * screen: a narrow window can be holding a wider preference down.
 */
function preferred(config) {
    const stored = read()[config.field]

    return typeof stored === 'number' && Number.isFinite(stored) ? stored : applied(config)
}

function assistantOpen() {
    return window.Alpine?.store('aiPanel')?.open === true
}

/**
 * How wide this panel is allowed to be, right now.
 *
 * The other panel is subtracted only when it is actually taking space — the
 * assistant is a closed drawer most of the time, and holding room for a panel
 * nobody has opened would make the navigation narrower than it needs to be.
 *
 * Opening the assistant does not then go back and shrink the navigation. That
 * would be the application moving something the person did not touch, and what
 * it avoids is a content column that is cramped rather than broken.
 */
function limits(config) {
    const other = config.field === 'nav'
        ? (assistantOpen() ? applied(PANELS.ai) : 0)
        : applied(PANELS.nav)

    const room = viewport() / rootFontSize() - MIN_CONTENT - other

    return {
        min: config.min,
        max: Math.max(config.min, Math.min(config.max, room)),
    }
}

function clamp(bounds, rem) {
    return Math.min(bounds.max, Math.max(bounds.min, rem))
}

function component({ panel }) {
    const config = PANELS[panel]

    return {
        /** True for the length of a drag. Drives the handle's own styling, nothing else. */
        dragging: false,

        init() {
            if (!config) {
                return
            }

            this.pointerId = null
            this.offset = 0
            this.pending = null
            this.frame = 0
            this.viewportFrame = 0
            this.pressedAt = 0
            this.bounds = null
            this.pxPerRem = 16

            this.onMove = (event) => this.move(event)
            this.onEnd = () => this.end()

            /*
             * A width stored on a wider screen has to be brought in before it
             * produces a horizontal scrollbar, and let back out when the window
             * grows again — which is why settle() re-reads the preference
             * rather than clamping whatever is currently applied. Clamping the
             * applied value would ratchet the panel permanently narrower every
             * time somebody dragged their window about.
             */
            this.onViewport = () => {
                if (this.viewportFrame) {
                    return
                }

                this.viewportFrame = window.requestAnimationFrame(() => {
                    this.viewportFrame = 0
                    this.settle()
                })
            }

            window.addEventListener('resize', this.onViewport)

            this.settle()
        },

        /**
         * The navigation handle is rebuilt on every wire:navigate; the
         * assistant's is inside @persist and is not. Removing the window
         * listener here is what keeps the first of those from accumulating one
         * per page visited.
         */
        destroy() {
            window.removeEventListener('resize', this.onViewport)
            window.cancelAnimationFrame(this.viewportFrame)
            window.cancelAnimationFrame(this.frame)
            this.release()
        },

        /** Put a width on the page. The one place that touches the property. */
        apply(rem) {
            surface().setProperty(config.property, round(rem) + 'rem')
        },

        /** Re-apply the stored preference within what the viewport currently allows. */
        settle() {
            if (!resizable()) {
                return
            }

            this.apply(clamp(limits(config), preferred(config)))
            this.describe()
        },

        /**
         * Tell assistive technology how wide this is.
         *
         * In pixels, because that is the number which means something read
         * aloud — rem is an implementation detail of how it is stored. Not
         * updated during a drag: the value is announced on every change, and
         * sixty announcements a second is not information.
         */
        describe() {
            const bounds = limits(config)
            const perRem = rootFontSize()
            const pixels = (rem) => String(Math.round(rem * perRem))

            this.$el.setAttribute('aria-valuenow', pixels(applied(config)))
            this.$el.setAttribute('aria-valuemin', pixels(bounds.min))
            this.$el.setAttribute('aria-valuemax', pixels(bounds.max))
            this.$el.setAttribute('aria-valuetext', pixels(applied(config)) + ' pixels')
        },

        /**
         * Write the current width down, and say what it is.
         *
         * The synthetic resize is for the three components that size themselves
         * by measurement — the Kanban column's seven-card cap, the comment
         * thread and the documentation contents — all of which listen for
         * `resize` and none of which would otherwise learn that the space they
         * measured has just changed. Once, on commit: per frame it would force
         * a layout of every column on the board sixty times a second.
         */
        remember() {
            write(config.field, round(applied(config)))
            this.describe()

            window.dispatchEvent(new Event('resize'))
        },

        start(event) {
            if (!resizable() || event.button !== 0) {
                return
            }

            /*
             * Stops the browser starting a text selection in the page behind,
             * which is what a drag from a bare element would otherwise be. It
             * also suppresses the compatibility mouse events — so focus has to
             * be given back explicitly, and the double-press below has to be
             * counted here rather than left to a `dblclick` that may never
             * arrive.
             */
            event.preventDefault()
            this.$el.focus()

            if (event.timeStamp - this.pressedAt < DOUBLE_PRESS_MS) {
                this.pressedAt = 0
                this.reset()

                return
            }

            this.pressedAt = event.timeStamp

            /*
             * Measured once, here. Both of these are computed-style reads, and
             * neither can change during a drag — the viewport is not being
             * resized and the root font size is not moving — so repeating them
             * per frame would be a forced style recalculation for an answer we
             * already have.
             */
            this.bounds = limits(config)
            this.pxPerRem = rootFontSize()

            const box = this.$el.getBoundingClientRect()

            /*
             * Where in the handle the pointer landed. Without it the panel edge
             * jumps to sit under the cursor on the first move, by up to the
             * width of the handle.
             */
            this.offset = event.clientX - (config.anchor === 'left' ? box.right : box.left)

            this.dragging = true
            document.body.classList.add('resizing-panel')

            /*
             * Pointer capture routes every later event for this pointer to the
             * handle, however far from it the cursor travels. That is what
             * removes the document-level listeners this would otherwise need,
             * and it is what makes a pointer released outside the window still
             * end the drag.
             *
             * `lostpointercapture` is the one that is easy to forget and ugly
             * to debug: capture is dropped silently if the handle leaves the
             * document — a Livewire morph during a streamed answer would do it
             * — and without this the drag would never end, leaving the whole
             * page stuck with a resize cursor and no text selection.
             */
            this.pointerId = event.pointerId
            this.$el.setPointerCapture(event.pointerId)
            this.$el.addEventListener('pointermove', this.onMove)
            this.$el.addEventListener('pointerup', this.onEnd)
            this.$el.addEventListener('pointercancel', this.onEnd)
            this.$el.addEventListener('lostpointercapture', this.onEnd)
        },

        /**
         * Pointer events fire well above the frame rate on a precision mouse or
         * a trackpad, and every write invalidates the layout of the whole page.
         * Keep the latest position and spend one write per frame.
         */
        move(event) {
            this.pending = event.clientX

            if (this.frame) {
                return
            }

            this.frame = window.requestAnimationFrame(() => {
                this.frame = 0

                const edge = this.pending - this.offset
                const pixels = config.anchor === 'left' ? edge : viewport() - edge

                this.apply(clamp(this.bounds, pixels / this.pxPerRem))
            })
        },

        end() {
            if (!this.dragging) {
                return
            }

            this.release()
            this.remember()
        },

        release() {
            this.dragging = false
            document.body.classList.remove('resizing-panel')

            this.$el.removeEventListener('pointermove', this.onMove)
            this.$el.removeEventListener('pointerup', this.onEnd)
            this.$el.removeEventListener('pointercancel', this.onEnd)
            this.$el.removeEventListener('lostpointercapture', this.onEnd)

            if (this.pointerId !== null && this.$el.hasPointerCapture(this.pointerId)) {
                this.$el.releasePointerCapture(this.pointerId)
            }

            this.pointerId = null
        },

        /**
         * The keyboard half, as one handler rather than six bindings.
         *
         * The arrows move the *edge*, which is why widen and narrow are the
         * opposite way round on the two panels: on both of them the handle goes
         * the way the arrow points. Shift is the fine adjustment, matching how
         * a slider behaves everywhere else.
         */
        key(event) {
            if (!resizable() || event.ctrlKey || event.metaKey || event.altKey) {
                return
            }

            const bounds = limits(config)
            const direction = config.anchor === 'left' ? 1 : -1
            const step = event.shiftKey ? 0.25 : 1

            let width

            switch (event.key) {
                case 'ArrowLeft':
                    width = applied(config) - step * direction
                    break
                case 'ArrowRight':
                    width = applied(config) + step * direction
                    break
                case 'Home':
                    width = bounds.min
                    break
                case 'End':
                    width = bounds.max
                    break
                default:
                    return
            }

            // Only now, so every other key still does whatever it would do.
            event.preventDefault()

            this.apply(clamp(bounds, width))
            this.remember()
        },

        /** The way back, for anybody who has dragged themselves somewhere odd. */
        reset() {
            if (!resizable()) {
                return
            }

            this.release()
            this.apply(clamp(limits(config), config.fallback))
            this.remember()
        },
    }
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('panelResize', component)
})
