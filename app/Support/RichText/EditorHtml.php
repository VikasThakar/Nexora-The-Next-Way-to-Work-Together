<?php

declare(strict_types=1);

namespace App\Support\RichText;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * The allow-list every scrap of editor HTML passes through, in both directions.
 *
 * Why an allow-list rather than a filter of known-bad things: a deny-list has
 * to anticipate `onpointerrawupdate`, `xlink:href`, `<math><mtext><table>`
 * parser-confusion tricks and whatever lands in browsers next year. An
 * allow-list only has to know the dozen elements this product's Markdown can
 * express. Every unknown element, every unlisted attribute and every URL
 * scheme that is not plainly a link is gone by construction.
 *
 * This runs on HTML travelling BOTH ways, on purpose:
 *
 *   browser -> server   the posted document is attacker-controlled. It is
 *                       cleaned before the Markdown converter sees it, so
 *                       nothing hostile is stored — not even as inert text,
 *                       which would still surface in search results, board
 *                       card excerpts and AI prompts.
 *   server -> browser   the stored Markdown is rendered by App\Support\Markdown
 *                       (which has already stripped raw HTML) and cleaned again
 *                       here before it reaches the editor. That pass is not
 *                       about safety; it is about shape. It drops the
 *                       syntax-highlighting spans the editor must not serialize
 *                       back into the source, and it rewrites task lists into
 *                       the form TipTap recognises.
 *
 * That second job is the one worth reading twice. CommonMark renders a GFM task
 * list as `<li><input disabled type="checkbox"> text</li>`, and TipTap's
 * TaskList only recognises `<ul data-type="taskList">` with
 * `<li data-checked="...">` children. Without the rewrite below, opening a
 * ticket whose description contained a checklist and pressing save would return
 * a plain bullet list, the checkboxes silently deleted because `input` is not in
 * the editor's schema. Nothing would have errored.
 */
class EditorHtml
{
    /**
     * Elements that may survive, and the attributes each may keep.
     *
     * Anything absent is unwrapped: the element goes, its text stays. That is
     * the right default for the wrappers editors love to emit — `<div>`,
     * `<span style>`, `<font>`, Word's `<o:p>` — because the words are the
     * user's content and only the wrapper is noise.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        'p' => [],
        'br' => [],

        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],

        'strong' => [], 'b' => [],
        'em' => [], 'i' => [],
        's' => [], 'del' => [], 'strike' => [],
        'u' => [], 'ins' => [],

        // `class` survives on <code> for one reason: a fenced block carries its
        // language as `class="language-php"`, and dropping it would turn every
        // highlighted block into an unlabelled one on the first edit.
        'code' => ['class'],
        'pre' => [],

        'ul' => ['data-type'],
        'ol' => ['start'],
        'li' => ['data-checked', 'data-type'],

        'blockquote' => [],
        'hr' => [],

        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'title'],

        'table' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [],
        'th' => ['align', 'colspan', 'rowspan'],
        'td' => ['align', 'colspan', 'rowspan'],
    ];

    /**
     * Elements removed together with everything inside them.
     *
     * Unwrapping these would be worse than useless: the text inside a
     * `<script>` *is* the payload, so keeping the children while dropping the
     * tag is precisely how a naive sanitizer converts markup injection into
     * stored text that the next careless renderer executes.
     *
     * `<input>` is here for a different reason, and it is order-dependent: task
     * checkboxes are read and rewritten by normaliseTaskLists() before this
     * list is applied, so any `<input>` still standing at that point is one
     * nobody asked for.
     *
     * @var list<string>
     */
    private const STRIPPED = [
        'script', 'style', 'noscript', 'template', 'iframe', 'frame', 'frameset',
        'object', 'embed', 'applet', 'link', 'meta', 'base', 'title', 'head',
        'form', 'input', 'button', 'select', 'option', 'textarea', 'label',
        'svg', 'math', 'audio', 'video', 'source', 'track', 'canvas', 'map', 'area',
    ];

    /**
     * URL schemes a link or an image may use.
     *
     * A relative URL has no scheme and is always allowed, which is what lets an
     * inline image point at `/attachments/42` — the authorized download route,
     * and the only reason an image on an internal ticket stays internal.
     *
     * `data:` is refused even for images. It is the standard way to smuggle
     * `data:text/html,<script>` past a scheme check, and an editor that
     * accepted it would let one pasted screenshot put a megabyte of base64 into
     * a column that search and AI prompts both read.
     *
     * @var list<string>
     */
    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * Clean a document down to the allow-list.
     *
     * Returns a fragment, not a document: no doctype, no <html>, no <body>.
     * Callers embed it, and the Markdown converter reads it.
     */
    public function sanitize(?string $html): string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return '';
        }

        $document = $this->load($html);

        $body = $document->getElementsByTagName('body')->item(0);

        if (! $body instanceof DOMElement) {
            return '';
        }

        // Runs first, because it reads the <input> elements the allow-list is
        // about to delete.
        $this->normaliseTaskLists($body);

        $this->normaliseCodeBlocks($body);

        $this->clean($body);

        return trim($this->innerHtml($body));
    }

    // -----------------------------------------------------------------

    /**
     * Parse without libxml deciding the document is malformed.
     *
     * Two things are being worked around. libxml assumes ISO-8859-1 unless told
     * otherwise, which would mangle every non-ASCII character — hence the
     * charset hint. And it warns about every HTML5 element it has never heard
     * of, which is not an error here: an unknown element is exactly what the
     * allow-list is for.
     */
    private function load(string $html): DOMDocument
    {
        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        $document->loadHTML(
            '<?xml encoding="utf-8" ?><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>',
            LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    /**
     * Rewrite checklists between CommonMark's shape and the editor's.
     *
     * Both directions land on one representation — `data-checked` on the `<li>`
     * and `data-type` on both the list and the item — so the converter on the
     * way out has a single shape to read and the editor on the way in has a
     * single shape to parse. See the class comment for what happens without it.
     */
    private function normaliseTaskLists(DOMElement $root): void
    {
        foreach (iterator_to_array($root->getElementsByTagName('li')) as $item) {
            if (! $item instanceof DOMElement) {
                continue;
            }

            $checkbox = $this->leadingCheckbox($item);

            if ($checkbox instanceof DOMElement) {
                $item->setAttribute('data-checked', $checkbox->hasAttribute('checked') ? 'true' : 'false');

                $checkbox->parentNode?->removeChild($checkbox);
            }

            if (! $item->hasAttribute('data-checked')) {
                continue;
            }

            $item->setAttribute('data-type', 'taskItem');

            // The list itself has to be marked too, or TipTap parses the items
            // as ordinary bullets and the checkboxes vanish on the next save.
            $list = $item->parentNode;

            if ($list instanceof DOMElement && in_array($list->nodeName, ['ul', 'ol'], true)) {
                $list->setAttribute('data-type', 'taskList');
            }
        }
    }

    /**
     * Drop the newline CommonMark appends inside a fenced code block.
     *
     * Not cosmetic. `<pre><code>` content always ends with a newline once
     * CommonMark has rendered it, and the HTML-to-Markdown converter adds its
     * own before the closing fence, so a block would gain one blank line every
     * time the ticket was edited — three edits, three blank lines, growing
     * forever. Trimming here fixes both directions at once and leaves the
     * library's converters alone.
     */
    private function normaliseCodeBlocks(DOMElement $root): void
    {
        foreach (iterator_to_array($root->getElementsByTagName('code')) as $code) {
            if (! $code instanceof DOMElement) {
                continue;
            }

            if (! $code->parentNode instanceof DOMElement || $code->parentNode->nodeName !== 'pre') {
                // An inline code span. Its whitespace is the author's.
                continue;
            }

            $code->textContent = rtrim($code->textContent, "\r\n");
        }
    }

    /**
     * The `<input type="checkbox">` a task item begins with, if this is one.
     *
     * "Begins with" rather than "contains anywhere", so a checkbox somebody
     * pasted into the middle of a sentence is not mistaken for a task marker.
     */
    private function leadingCheckbox(DOMElement $item): ?DOMElement
    {
        foreach ($item->childNodes as $child) {
            if ($child instanceof DOMText && trim($child->textContent) === '') {
                continue;
            }

            if (! $child instanceof DOMElement) {
                return null;
            }

            if ($child->nodeName === 'input' && strtolower($child->getAttribute('type')) === 'checkbox') {
                return $child;
            }

            // TipTap wraps its checkbox one level down, inside a <label>.
            if (in_array($child->nodeName, ['label', 'span', 'div'], true)) {
                return $this->leadingCheckbox($child);
            }

            return null;
        }

        return null;
    }

    /**
     * Apply the allow-list to a subtree.
     *
     * The child list is snapshotted first because the loop rewrites it:
     * unwrapping a node replaces one entry with several, and iterating a live
     * DOMNodeList while doing that skips nodes.
     */
    private function clean(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMText) {
                continue;
            }

            if (! $child instanceof DOMElement) {
                // Comments, CDATA sections, processing instructions. None of
                // them are content, and a comment can hide markup from a
                // careless reader while a browser still parses it.
                $node->removeChild($child);

                continue;
            }

            $name = strtolower($child->nodeName);

            if (in_array($name, self::STRIPPED, true)) {
                $node->removeChild($child);

                continue;
            }

            if (! array_key_exists($name, self::ALLOWED)) {
                $this->clean($child);
                $this->unwrap($child);

                continue;
            }

            $this->stripAttributes($child, self::ALLOWED[$name]);
            $this->clean($child);
        }
    }

    /**
     * Remove every attribute the element is not explicitly allowed to keep.
     *
     * Expressed as "delete everything not named" rather than "delete the
     * dangerous ones", so a handler nobody has heard of yet is already gone.
     * One rule covers the whole `on*` family, `style`, `srcset`, `formaction`
     * and `xlink:href` without this method having to know any of them.
     *
     * @param  list<string>  $allowed
     */
    private function stripAttributes(DOMElement $element, array $allowed): void
    {
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);

            if (! in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            if (($name === 'href' || $name === 'src') && ! $this->isSafeUrl($attribute->nodeValue)) {
                $element->removeAttribute($attribute->nodeName);
            }
        }
    }

    /**
     * Move an element's children up into its place and drop the element.
     */
    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            $parent->insertBefore($child, $element);
        }

        $parent->removeChild($element);
    }

    /**
     * Is this something a browser may safely be pointed at?
     *
     * Deliberately strict and deliberately dull. Control characters are
     * stripped first, because `java\0script:` and a tab-separated
     * `java&#x09;script:` are the classic ways to make a scheme check read one
     * string while the browser reads another.
     */
    private function isSafeUrl(?string $url): bool
    {
        $url = (string) preg_replace('/[\x00-\x20\x7F]/', '', (string) $url);

        if ($url === '') {
            return false;
        }

        // Root-relative, fragment or query. No scheme, so no scheme to abuse —
        // and this is the case that matters, because an inline attachment is
        // referenced through the application's own authorized route.
        if (str_starts_with($url, '/') || str_starts_with($url, '#') || str_starts_with($url, '?')) {
            return true;
        }

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $url, $matches) !== 1) {
            // A bare relative path such as "docs/readme.md".
            return true;
        }

        return in_array(strtolower($matches[1]), self::SAFE_SCHEMES, true);
    }

    /**
     * Serialize a node's children without the node itself.
     */
    private function innerHtml(DOMElement $element): string
    {
        $html = '';

        foreach ($element->childNodes as $child) {
            $html .= $element->ownerDocument?->saveHTML($child) ?? '';
        }

        return $html;
    }
}
