{{--
    The panel widths, applied before the first paint.

    An inline script in <head>, for exactly the reason
    components/app/theme-boot.blade.php is one: everything else arrives over
    the network. A page that waits for resources/js/panel-resize.js paints a
    16rem sidebar and a 28rem assistant, then jumps to whatever the person
    actually chose. A handful of lines executed synchronously before <body> is
    parsed is the difference between a remembered width and a flicker.

    Only in the application layout. The sign-in pages have neither panel.


    Why a <style> element and not a style attribute on <html>
    --------------------------------------------------------
    Because an inline style on <html> does not survive wire:navigate, and that
    is not a guess. Livewire's `replaceHtmlAttributes` copies the new document's
    <html> attributes over the live ones and then removes every attribute the
    new element does not have — and the server renders `<html lang="…"
    class="h-full">` with no style attribute at all. Both widths would be
    deleted on the first link somebody clicked.

    A <style> element in <head> survives instead, by a different rule in the
    same file: `mergeNewHead` keeps every current head child for which
    `isAsset` is true, and `isAsset` is true for <style>. This element has no
    counterpart in the incoming head, so it is never matched, never replaced and
    never removed. resources/js/panel-resize.js then edits the rule inside it
    rather than an attribute, which is the same cost — a property write on a
    CSSStyleDeclaration, no reparse.

    `:root` rather than `html` on purpose: both this rule and the defaults in
    resources/css/app.css sit outside any cascade layer, so specificity decides
    between them, and `:root` (0,1,0) beats `html` (0,0,1) wherever the two
    elements end up in the head.

    The rule starts empty. The defaults live in one place, the stylesheet, and
    only a width somebody actually chose is written here.


    This script must stay byte-identical on every page
    -------------------------------------------------
    `mergeNewHead` recognises an already-present head asset by hashing its outer
    HTML, and skips it. A script that varied per page would be re-executed on
    every navigation. This one takes no props and interpolates nothing, so it
    runs exactly once per full page load.
--}}
<script>
    (function () {
        var sheet = document.getElementById('nexora-panels');

        if (!sheet) {
            sheet = document.createElement('style');
            sheet.id = 'nexora-panels';
            sheet.textContent = ':root{}';
            document.head.appendChild(sheet);
        }

        var stored;

        try {
            stored = JSON.parse(window.localStorage.getItem('nexora.panels') || '{}');
        } catch (e) {
            // A browser in private mode, or one told to block site data, throws
            // on read as well as on write. The defaults in the stylesheet are
            // already correct, so there is nothing to do.
            return;
        }

        if (!stored || typeof stored !== 'object') {
            return;
        }

        var rule = sheet.sheet && sheet.sheet.cssRules[0];

        if (!rule) {
            return;
        }

        /*
         * Numbers are rem, so the panels follow a raised browser font size the
         * way the rest of the layout does — see resources/js/panel-resize.js,
         * which owns the real minimum and maximum for each panel and re-clamps
         * on boot against the viewport this browser actually has.
         *
         * The only limit applied here is a sanity range wide enough to contain
         * any width that module would have written. Its job is to stop a
         * hand-edited or corrupted value from painting something absurd before
         * anything is loaded that could correct it, not to restate the design.
         */
        function apply(field, property) {
            var width = stored[field];

            if (typeof width !== 'number' || !isFinite(width) || width < 4 || width > 64) {
                return;
            }

            rule.style.setProperty(property, width + 'rem');
        }

        apply('nav', '--nav-width');
        apply('ai', '--ai-panel-width');
    })();
</script>
