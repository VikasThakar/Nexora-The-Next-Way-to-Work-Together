/*
 * Downloading a chart.
 *
 * The charts in this application are inline SVG rendered by Blade — see
 * resources/views/components/charts/. That is what makes this file short:
 * exporting a picture of a chart is serialising a node that is already on the
 * page, and rasterising it is drawing that node into a canvas. No library, no
 * headless browser, no server round trip.
 *
 * The CSV is deliberately NOT produced here. It comes from
 * App\Http\Controllers\StatisticsExportController, which re-derives the numbers
 * from the database under the viewer's own scope. Building it in the browser
 * would mean shipping the full dataset into the page in order to hand it
 * straight back — a second copy of the data, outside every policy, for no gain.
 *
 *
 * The one hard part
 * -----------------
 * A chart on the page is coloured by Tailwind classes: `class="text-brand-500"`
 * on a path, resolved by the page's stylesheet. Serialise that node on its own
 * and every colour is gone, because the stylesheet is not coming with it.
 *
 * So the clone is walked and the handful of properties that actually draw
 * anything are copied from `getComputedStyle` onto the element as presentation
 * attributes. Computed values are resolved pixels and `rgb()` — no classes, no
 * variables, no inheritance — which is exactly what a standalone file needs.
 */

/** The properties without which an exported chart is a blank rectangle. */
const PAINTED = [
    'fill',
    'fill-opacity',
    'stroke',
    'stroke-width',
    'stroke-linecap',
    'stroke-linejoin',
    'stroke-dasharray',
    'opacity',
    'font-family',
    'font-size',
    'font-weight',
    'text-anchor',
    // Set as an attribute by every chart that needs it, so cloneNode already
    // carries it — copied anyway, because a class-based baseline would not
    // survive the removeAttribute below.
    'dominant-baseline',
]

/** Rasterise at twice the rendered size, so a PNG is not soft on a good screen. */
const PIXEL_RATIO = 2

/**
 * A standalone copy of an on-page SVG.
 *
 * @param {SVGSVGElement} svg
 * @returns {{markup: string, width: number, height: number}}
 */
function serialise(svg) {
    const box = svg.getBoundingClientRect()
    const width = Math.max(1, Math.round(box.width))
    const height = Math.max(1, Math.round(box.height))

    const clone = svg.cloneNode(true)

    /*
     * Explicit dimensions. On the page these come from CSS and a viewBox with
     * preserveAspectRatio="none"; a file opened on its own has no CSS to read,
     * and without them it renders at some default square size.
     */
    clone.setAttribute('width', String(width))
    clone.setAttribute('height', String(height))
    clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg')
    clone.setAttribute('xmlns:xlink', 'http://www.w3.org/1999/xlink')

    // Zip the live tree and the clone together: computed styles can only be
    // read from the element that is actually in the document.
    const live = [svg, ...svg.querySelectorAll('*')]
    const copied = [clone, ...clone.querySelectorAll('*')]

    live.forEach((element, index) => {
        const target = copied[index]

        if (!target) {
            return
        }

        const computed = window.getComputedStyle(element)

        for (const property of PAINTED) {
            const value = computed.getPropertyValue(property)

            if (value && value !== 'none' && value !== 'normal') {
                target.setAttribute(property, value)
            }
        }

        // The classes did their work above and mean nothing outside the page.
        target.removeAttribute('class')
    })

    const markup = new XMLSerializer().serializeToString(clone)

    return {
        markup: '<?xml version="1.0" standalone="no"?>\n' + markup,
        width,
        height,
    }
}

/**
 * The colour of the nearest ancestor that actually paints something.
 *
 * Walks up rather than reading <body>, because a chart sits on a card and a
 * card is not the same colour as the page behind it. Stops at the first
 * non-transparent background and falls back to white, which is what an
 * unstyled page is.
 *
 * @param {Element} node
 * @returns {string}
 */
function surfaceBehind(node) {
    let element = node.parentElement

    while (element) {
        const background = window.getComputedStyle(element).backgroundColor

        if (background && background !== 'transparent' && !background.startsWith('rgba(0, 0, 0, 0')) {
            return background
        }

        element = element.parentElement
    }

    return '#ffffff'
}

/**
 * Hand a blob to the browser as a download.
 *
 * The object URL is revoked on the next turn rather than immediately: Safari
 * reads the href asynchronously and revoking in the same tick gives it nothing
 * to fetch.
 */
function save(blob, filename) {
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')

    link.href = url
    link.download = filename
    document.body.appendChild(link)
    link.click()
    link.remove()

    setTimeout(() => URL.revokeObjectURL(url), 0)
}

function component() {
    return {
        /** Set while a rasterise is in flight, so the button can say so. */
        exporting: false,

        /** Shown in place of the buttons when something went wrong. */
        exportError: '',

        /**
         * The chart this toolbar belongs to.
         *
         * Resolved from the DOM rather than passed in, so the wrapper does not
         * have to know how the chart inside it is built.
         */
        chart() {
            return this.$el.querySelector('[data-chart] svg')
        },

        downloadSvg(filename) {
            const svg = this.chart()

            if (!svg) {
                this.exportError = 'There is no chart to export.'

                return
            }

            this.exportError = ''

            save(
                new Blob([serialise(svg).markup], { type: 'image/svg+xml;charset=utf-8' }),
                filename + '.svg',
            )
        },

        /**
         * SVG to PNG, through an image and a canvas.
         *
         * A data: URL rather than a blob URL for the intermediate image: a
         * blob URL counts as a separate origin for the purposes of tainting
         * the canvas in some browsers, and a tainted canvas refuses toBlob.
         */
        downloadPng(filename) {
            const svg = this.chart()

            if (!svg) {
                this.exportError = 'There is no chart to export.'

                return
            }

            const { markup, width, height } = serialise(svg)

            this.exporting = true
            this.exportError = ''

            const image = new Image()

            image.onload = () => {
                const canvas = document.createElement('canvas')

                canvas.width = width * PIXEL_RATIO
                canvas.height = height * PIXEL_RATIO

                const context = canvas.getContext('2d')

                /*
                 * A PNG has to carry its own background: without one it is
                 * transparent, and every line in it vanishes against whatever
                 * the image viewer happens to use.
                 *
                 * Which colour is not a constant, because the chart is not.
                 * Its labels are `fill: var(--color-slate-500)`, resolved
                 * above from the live page — so in the dark appearance they
                 * are pale, and painting them onto white would export a
                 * chart nobody can read. Asking the card behind the chart
                 * what colour it is keeps the two in step, whatever the
                 * appearance and whatever a future palette does to it.
                 */
                context.fillStyle = surfaceBehind(svg)
                context.fillRect(0, 0, canvas.width, canvas.height)
                context.drawImage(image, 0, 0, canvas.width, canvas.height)

                canvas.toBlob((blob) => {
                    this.exporting = false

                    if (!blob) {
                        this.exportError = 'That chart could not be turned into an image.'

                        return
                    }

                    save(blob, filename + '.png')
                }, 'image/png')
            }

            image.onerror = () => {
                this.exporting = false
                this.exportError = 'That chart could not be turned into an image.'
            }

            image.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(markup)
        },
    }
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('chartExport', component)
})
