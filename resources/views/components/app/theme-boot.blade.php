@props([
    /**
     * The signed-in person's stored preference, or null for a guest.
     *
     * Passed in rather than read from auth() here so both layouts state
     * plainly where it comes from, and so the guest layout can be rendered
     * without a session at all.
     */
    'preference' => null,
])

@php
    /*
     * The server's answer, if it has one.
     *
     * A stored preference beats whatever this browser last remembered, because
     * it is the one that followed the person here from another device. A guest
     * gets null and the script falls through to localStorage — which is what
     * keeps the login page in the right appearance after a sign-out.
     */
    $stored = $preference instanceof \App\Enums\ThemePreference ? $preference->value : null;

    /*
     * What to do when nobody has said anything: light, from the enum, so this
     * cannot disagree with the column default or with theme.js.
     */
    $fallback = \App\Enums\ThemePreference::default()->value;
@endphp

{{--
    The appearance, applied before the first paint.

    This has to be an inline script in <head>, and it has to be here rather
    than in the bundle. The stylesheet and resources/js/theme.js both arrive
    over the network; anything that waits for them paints a light page first
    and then corrects it, which is the flash this exists to prevent. Eleven
    lines executed synchronously before <body> is parsed is the whole trick.

    It sets two attributes, because two different readers need two different
    facts: the `dark` class is what the stylesheet keys off (see the
    @custom-variant in resources/css/app.css), and `data-theme` is the
    preference itself, which the sidebar control reads to know which of its
    three buttons is pressed. `system` is resolved here and *stays* `system` in
    the attribute — collapsing it to the resolved value would lose the fact
    that the person asked to follow their device.

    `data-theme-endpoint` is present only for a signed-in viewer. Its absence
    is how theme.js knows there is no row to write to.
--}}
<script>
    (function () {
        var root = document.documentElement;
        var stored = @js($stored);
        var preference = stored;

        if (!preference) {
            try {
                preference = window.localStorage.getItem('nexora.theme');
            } catch (e) {
                // Private mode throws on read as well as on write.
            }
        }

        if (preference !== 'light' && preference !== 'dark' && preference !== 'system') {
            preference = @js($fallback);
        }

        // Write the server's answer back, so a later sign-out leaves this
        // browser in the appearance the person was actually using.
        try {
            window.localStorage.setItem('nexora.theme', preference);
        } catch (e) {
            // See above.
        }

        var dark = preference === 'dark'
            || (preference === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

        root.classList.toggle('dark', dark);
        root.dataset.theme = preference;

        @auth
            root.dataset.themeEndpoint = @js(route('settings.theme'));
        @endauth
    })();
</script>
