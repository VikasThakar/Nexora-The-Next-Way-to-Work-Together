<?php

declare(strict_types=1);

namespace App\Support\RichText;

use App\Support\Markdown;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * The bridge between the rich text editor and the database.
 *
 * The single most consequential decision in this feature is that it does not
 * change the storage format. `tickets.description_md` stays Markdown; the
 * editor is a surface over it. Storing the editor's HTML instead would have
 * meant:
 *
 *   - migrating every existing description, with no way to be sure the result
 *     was faithful, on data nobody can reconstruct if it goes wrong;
 *   - introducing an HTML sanitizer as the thing standing between user input
 *     and a page — where today the answer is stronger, because the stored
 *     format cannot express HTML at all (App\Support\Markdown renders with
 *     `html_input => 'strip'`);
 *   - breaking Ticket::scopeSearch, which runs LIKE over description_md and
 *     would start matching tag names;
 *   - inflating every AI prompt, because App\Services\AI\BoardContextBuilder
 *     sends descriptions to the model verbatim and tags are pure token cost.
 *
 * So the conversion happens here, and it is asymmetric on purpose:
 *
 *   Markdown -> HTML   done by App\Support\Markdown, the same converter that
 *                      renders the ticket for reading. Not a second parser
 *                      with its own opinions: one flavour, so what the editor
 *                      shows and what the page shows cannot disagree.
 *   HTML -> Markdown   done here, on the server. It could have been done in
 *                      the browser with a JavaScript serializer, and that is
 *                      the usual arrangement. Doing it in PHP means the
 *                      conversion is covered by the ordinary test suite
 *                      instead of being the one untested step in the middle of
 *                      the write path.
 *
 * Round-trip honesty: this normalises. A description written with `*` bullets
 * or `__bold__` comes back with `-` and `**bold**`, because the editor's model
 * has no memory of which spelling produced it. Content is preserved; spelling
 * is not. That is why the editor only writes back when the document was
 * actually edited, and why the Markdown source view still exists for people
 * who want their formatting left exactly as they typed it.
 */
class RichText
{
    public function __construct(
        private readonly Markdown $markdown,
        private readonly EditorHtml $editorHtml,
    ) {}

    /**
     * Stored Markdown, as HTML the editor can load.
     *
     * Deliberately NOT App\Services\ContentRenderer, which is what the *read*
     * view uses. That adds @mention highlighting and ticket-reference links,
     * which are decorations applied per viewer — feeding them into an editor
     * would serialize somebody's rendered view back into the source, turning
     * every mention into a permanent `<span>` and every ticket key into a hard
     * link the author never typed.
     */
    public function toEditorHtml(?string $markdown): string
    {
        return $this->editorHtml->sanitize($this->markdown->toHtml($markdown));
    }

    /**
     * HTML from the editor, as Markdown to store.
     *
     * The input is attacker-controlled: it arrives as a Livewire property, so
     * "it came from our editor" is a statement about the happy path and nothing
     * more. It is put through the allow-list before the converter ever sees it.
     */
    public function toMarkdown(?string $html): string
    {
        $clean = $this->editorHtml->sanitize($html);

        if ($clean === '') {
            return '';
        }

        return $this->normalise($this->converter()->convert($clean));
    }

    /**
     * Would storing this HTML change the stored Markdown?
     *
     * Used to decide whether a save is a real edit. Comparing the *converted*
     * text rather than comparing HTML is what makes reopening a ticket and
     * pressing save a no-op: the two spellings of unchanged content differ, the
     * two Markdown strings do not.
     */
    public function matches(?string $html, ?string $markdown): bool
    {
        return $this->comparable($this->toMarkdown($html)) === $this->comparable((string) $markdown);
    }

    // -----------------------------------------------------------------

    private function converter(): HtmlConverter
    {
        $converter = new HtmlConverter([
            /*
             * `## Heading`, not the underlined form. Setext is the library's
             * default and it only works for two levels, so h3 would silently
             * become `### h3` anyway — mixing both styles in one document.
             */
            'header_style' => 'atx',

            // Belt and braces. EditorHtml has already unwrapped anything not on
            // the allow-list, so this should never have work to do.
            'strip_tags' => true,
            'remove_nodes' => 'script style iframe object embed form input',

            /*
             * An <a> with no href becomes its text.
             *
             * This is the tidy-up for a link EditorHtml refused: it strips the
             * `href` from `javascript:alert(1)` and leaves the element behind,
             * and the library's default for an href-less anchor is to emit the
             * raw `<a>text</a>` into the Markdown. That is inert on the page —
             * `html_input => 'strip'` removes it — but it would sit in the
             * column to be read verbatim by search, by card excerpts and by the
             * AI context builder. The words were the user's; the tag was not.
             */
            'strip_placeholder_links' => true,

            'list_item_style' => '-',
            'italic_style' => '*',
            'bold_style' => '**',

            // An <a> whose text is its own href becomes <https://…> rather than
            // a doubled [https://…](https://…).
            'use_autolinks' => true,

            'preserve_comments' => false,
            'suppress_errors' => true,
        ]);

        $environment = $converter->getEnvironment();

        // Not registered by createDefaultEnvironment, so a table would
        // otherwise be flattened into a run of words. GFM tables are on in
        // App\Support\Markdown (Laravel's Str::markdown uses the GitHub
        // Flavoured converter), so the output is read back correctly.
        $environment->addConverter(new TableConverter);

        // Both of these must come after the defaults: Environment keys
        // converters by tag name, so the last registration for `li` wins.
        $environment->addConverter(new TaskListItemConverter);
        $environment->addConverter(new InlineMarkConverter);

        return $converter;
    }

    /**
     * Tidy the converter's output without changing what it says.
     *
     * Note what this does NOT do: it does not strip trailing whitespace. Two
     * spaces at the end of a line are a hard line break in Markdown, which is
     * how a `<br>` from the editor survives, and stripping them would silently
     * rejoin lines the author deliberately broke. It would also corrupt fenced
     * code blocks, whose content is whitespace-significant and none of a
     * formatter's business.
     */
    private function normalise(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $markdown = (string) preg_replace('/\n{3,}/', "\n\n", $markdown);

        return trim($markdown);
    }

    /**
     * The same document reduced to something two spellings of it agree on.
     *
     * Only ever used to answer "did this edit change anything?" — never stored,
     * so it is free to throw away the whitespace that normalise() has to keep.
     */
    private function comparable(string $markdown): string
    {
        $markdown = $this->normalise($markdown);

        $markdown = (string) preg_replace('/[ \t]+$/m', '', $markdown);

        /*
         * A table's delimiter row, with its padding removed: `| --- | --- |`
         * and `|---|---|` are the same table, and the converter writes the
         * tighter spelling.
         */
        $markdown = (string) preg_replace_callback(
            '/^\|[ \t:|\-]+\|$/m',
            static fn (array $row): string => (string) preg_replace('/[ \t]+/', '', $row[0]),
            $markdown
        );

        /*
         * The bullet character, which is not content.
         *
         * CommonMark treats `-`, `*` and `+` as the same list marker and this
         * product has descriptions written with all three; the converter emits
         * `-`. Without this, opening a ticket whose author happened to type `*`
         * and pressing save would rewrite every bullet in it, record an edit on
         * the timeline, put a line in the workspace feed and notify the
         * watchers — for a save that changed nothing anybody can see.
         *
         * A marker must be followed by whitespace to be a list item, so `***`
         * (a horizontal rule) and `*emphasis*` are not touched.
         */
        $markdown = (string) preg_replace('/^([ \t]*)[*+-][ \t]/m', '$1- ', $markdown);

        return trim($markdown);
    }
}
