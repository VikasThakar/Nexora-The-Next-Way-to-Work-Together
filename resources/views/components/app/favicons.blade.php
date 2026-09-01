{{--
    Tab and home-screen icons.

    One partial rather than the same four lines in both layouts, so the signed-in
    and signed-out pages cannot end up with different icons.

    Order matters. A browser picks the *last* `rel="icon"` it understands, so the
    SVG is declared after the .ico and wins everywhere it is supported; the .ico
    is what Windows, older browsers, and the bare `GET /favicon.ico` a browser
    makes before parsing any HTML fall back to. The explicit `sizes` on the .ico
    stops Chrome preferring it over the scalable one.

    The version marker is what makes a change visible. Browsers cache favicons
    unusually hard — including a cached *absence*, which is why a site that
    shipped Laravel's empty placeholder keeps showing the generic globe long
    after a real icon is added. Bump it when the mark changes.

    Regenerate the two bitmaps with `php artisan workspace:icons`; favicon.svg is
    hand-written and is the source of truth for the shape.

    Root-relative rather than asset(), deliberately. asset() builds an absolute
    URL from APP_URL, so browsing the same server on a hostname APP_URL does not
    name — 127.0.0.1 when APP_URL says localhost, or a Railway preview domain —
    would send every icon request to the other host. These files always sit at
    the document root, so the path is the one thing that is true on every
    hostname the app is reachable on.
--}}
@php($iconVersion = '1')

<link rel="icon" href="/favicon.ico?v={{ $iconVersion }}" sizes="32x32">
<link rel="icon" href="/favicon.svg?v={{ $iconVersion }}" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png?v={{ $iconVersion }}">
<meta name="theme-color" content="#2A65E8">
