<?php

declare(strict_types=1);

namespace App\Services\Docs\Import\Converters;

use App\Services\Docs\Import\ConvertedDocument;
use App\Services\Docs\Import\DocumentImportException;
use App\Services\Docs\Import\MarkdownBlocks;
use App\Services\Docs\Import\MarkdownText;
use App\Support\Ooxml\OoxmlPackage;
use App\Support\Xml\SafeXml;
use RuntimeException;
use XMLReader;

/**
 * A Word document (.docx) as Markdown, keeping its structure.
 *
 * What a to-do list or a bug report in Word is made of, and what is kept:
 *
 *   headings      from the paragraph's style — Heading 1 to 6, and Title —
 *                 read from styles.xml, so a renamed or localised style is
 *                 still found by its outline level;
 *   lists         from numbering.xml: bullet or numbered, nested by level, and
 *                 a list whose bullet is a box (☐) is a list of to-dos;
 *   checkboxes    Word's checkbox controls and box symbols, ticked or not, as
 *                 `- [x]` and `- [ ]`;
 *   emphasis      bold, italic and strike-through;
 *   links         to web and mail addresses (nothing else is followed);
 *   tables        as Markdown tables, the first row as the header;
 *   footnotes     gathered under "Notes" at the end.
 *
 * Deleted text from tracked changes, field codes and pictures are left out;
 * pictures are counted and the import says so, because the original is
 * attached to the page.
 *
 * Read with a streaming XML reader (SafeXml) rather than a document tree: a
 * large report's document.xml can be tens of megabytes, and a tree of it would
 * not fit in a web request's memory. See SafeXml for why that reader is safe
 * on an uploaded file.
 */
final class DocxConverter
{
    /** Stop writing beyond this; the page itself holds less. */
    private const MAX_OUTPUT = 210000;

    /** @var array<string, int|string> styleId => heading level, or 'title' */
    private array $styles = [];

    /** @var array<string, array<int, array{format: string, text: string}>> numId => level => format */
    private array $numbering = [];

    /** @var array<string, string> relationship id => web address */
    private array $links = [];

    private int $images = 0;

    private int $length = 0;

    public function convert(string $path): ConvertedDocument
    {
        $package = OoxmlPackage::open($path);

        if ($package === null) {
            throw new DocumentImportException(
                'That Word document could not be opened. It may be corrupt, or saved in an older format — '
                .'re-saving it as .docx will fix it.'
            );
        }

        try {
            $document = $package->read('word/document.xml');

            if ($document === null) {
                throw new DocumentImportException('That Word document has no readable body.');
            }

            $this->styles = $this->styles($package->read('word/styles.xml'));
            $this->numbering = $this->numbering($package->read('word/numbering.xml'));
            $this->links = $this->links($package->relationships('word/document.xml'));

            [$blocks, $title] = $this->body($document);

            $notes = $this->notes($package->read('word/footnotes.xml'), 'footnote')
                + $this->notes($package->read('word/endnotes.xml'), 'endnote');
        } catch (RuntimeException $exception) {
            if ($exception instanceof DocumentImportException) {
                throw $exception;
            }

            throw new DocumentImportException(
                'That Word document could not be read. It may be corrupt — opening it in Word and saving it again usually fixes this.'
            );
        } finally {
            $package->close();
        }

        if ($notes !== []) {
            $blocks[] = ['type' => 'heading', 'level' => 2, 'text' => 'Notes'];

            foreach (array_values($notes) as $index => $note) {
                $blocks[] = ['type' => 'item', 'depth' => 0, 'ordered' => true, 'number' => $index + 1, 'task' => null, 'text' => $note];
            }
        }

        $warnings = [];

        if ($this->images > 0) {
            $warnings[] = $this->images === 1
                ? 'A picture in the document was not imported; it is in the attached original.'
                : $this->images.' pictures in the document were not imported; they are in the attached original.';
        }

        return new ConvertedDocument(MarkdownBlocks::render($blocks), $title, $warnings);
    }

    // -----------------------------------------------------------------
    // The body
    // -----------------------------------------------------------------

    /**
     * @return array{0: list<array<string, mixed>>, 1: ?string}
     */
    private function body(string $xml): array
    {
        $reader = SafeXml::reader($xml);

        $blocks = [];
        $title = null;

        $stack = [];
        $paragraph = null;
        $run = null;
        $link = null;

        /** @var list<array{rows: list<list<string>>, row: list<string>|null, cell: list<string>|null, span: int}> $tables */
        $tables = [];

        $advance = true;

        while (true) {
            if ($advance && ! $reader->read()) {
                break;
            }

            $advance = true;

            if ($this->length > self::MAX_OUTPUT) {
                break;
            }

            if ($reader->nodeType === XMLReader::ELEMENT) {
                $name = $reader->localName;
                $empty = $reader->isEmptyElement;
                $parent = end($stack) ?: null;

                // Things that are not text, skipped whole: deleted revisions,
                // field codes, and pictures (counted).
                if (in_array($name, ['del', 'instrText', 'delText', 'drawing', 'pict', 'object'], true)) {
                    if (in_array($name, ['drawing', 'pict', 'object'], true)) {
                        $this->images++;
                    }

                    if (! $reader->next()) {
                        break;
                    }

                    $advance = false;

                    continue;
                }

                switch ($name) {
                    case 'p':
                        $paragraph = ['style' => null, 'outline' => null, 'numId' => null, 'ilvl' => 0, 'runs' => []];
                        break;

                    case 'pStyle':
                        if ($paragraph !== null && $parent === 'pPr') {
                            $paragraph['style'] = SafeXml::attribute($reader, 'val');
                        }
                        break;

                    case 'outlineLvl':
                        if ($paragraph !== null && $parent === 'pPr') {
                            $paragraph['outline'] = (int) SafeXml::attribute($reader, 'val');
                        }
                        break;

                    case 'numId':
                        if ($paragraph !== null && $parent === 'numPr') {
                            $paragraph['numId'] = SafeXml::attribute($reader, 'val');
                        }
                        break;

                    case 'ilvl':
                        if ($paragraph !== null && $parent === 'numPr') {
                            $paragraph['ilvl'] = (int) SafeXml::attribute($reader, 'val');
                        }
                        break;

                    case 'r':
                        $run = ['b' => false, 'i' => false, 's' => false];
                        break;

                    case 'b':
                    case 'i':
                    case 'strike':
                    case 'dstrike':
                        if ($run !== null && $parent === 'rPr') {
                            $on = ! in_array(strtolower((string) SafeXml::attribute($reader, 'val')), ['0', 'false', 'off', 'none'], true);
                            $run[$name === 'dstrike' ? 's' : ($name === 'strike' ? 's' : $name)] = $on;
                        }
                        break;

                    case 't':
                        if ($paragraph !== null) {
                            $this->append($paragraph, $run, $link, $empty ? '' : $reader->readString());
                        }

                        if (! $empty) {
                            if (! $reader->next()) {
                                break 2;
                            }

                            $advance = false;

                            continue 2;
                        }
                        break;

                    case 'tab':
                        if ($paragraph !== null && $parent === 'r') {
                            $this->append($paragraph, $run, $link, ' ');
                        }
                        break;

                    case 'br':
                    case 'cr':
                        if ($paragraph !== null && $parent === 'r') {
                            $this->append($paragraph, $run, $link, "\n");
                        }
                        break;

                    case 'noBreakHyphen':
                        if ($paragraph !== null) {
                            $this->append($paragraph, $run, $link, '-');
                        }
                        break;

                    case 'sym':
                        if ($paragraph !== null) {
                            $glyph = $this->symbol((string) SafeXml::attribute($reader, 'char'));

                            if ($glyph !== null) {
                                $this->append($paragraph, $run, $link, $glyph);
                            }
                        }
                        break;

                    case 'hyperlink':
                        $id = SafeXml::attribute($reader, 'id');
                        $link = $id !== null ? ($this->links[$id] ?? null) : null;
                        break;

                    case 'tbl':
                        $tables[] = ['rows' => [], 'row' => null, 'cell' => null, 'span' => 1];
                        break;

                    case 'tr':
                        if ($tables !== []) {
                            $tables[array_key_last($tables)]['row'] = [];
                        }
                        break;

                    case 'tc':
                        if ($tables !== []) {
                            $tables[array_key_last($tables)]['cell'] = [];
                            $tables[array_key_last($tables)]['span'] = 1;
                        }
                        break;

                    case 'gridSpan':
                        if ($tables !== []) {
                            $tables[array_key_last($tables)]['span'] = max(1, min(20, (int) SafeXml::attribute($reader, 'val')));
                        }
                        break;
                }

                if (! $empty) {
                    $stack[] = $name;
                }

                continue;
            }

            if ($reader->nodeType !== XMLReader::END_ELEMENT) {
                continue;
            }

            $name = $reader->localName;
            array_pop($stack);

            switch ($name) {
                case 'p':
                    if ($paragraph !== null) {
                        $block = $this->paragraph($paragraph, $tables !== []);

                        if ($block !== null) {
                            if ($tables !== []) {
                                $last = array_key_last($tables);

                                if ($tables[$last]['cell'] !== null) {
                                    $tables[$last]['cell'][] = (string) $block['text'];
                                }
                            } else {
                                if ($block['type'] === 'heading' && ($block['title'] ?? false) && $title === null && $blocks === []) {
                                    $title = (string) $block['plain'];
                                }

                                unset($block['title'], $block['plain']);
                                $blocks[] = $block;
                                $this->length += mb_strlen((string) $block['text']);
                            }
                        }
                    }

                    $paragraph = null;
                    break;

                case 'r':
                    $run = null;
                    break;

                case 'hyperlink':
                    $link = null;
                    break;

                case 'tc':
                    if ($tables !== []) {
                        $last = array_key_last($tables);

                        if ($tables[$last]['row'] !== null) {
                            $tables[$last]['row'][] = implode(' ', (array) $tables[$last]['cell']);

                            for ($extra = 1; $extra < $tables[$last]['span']; $extra++) {
                                $tables[$last]['row'][] = '';
                            }
                        }

                        $tables[$last]['cell'] = null;
                    }
                    break;

                case 'tr':
                    if ($tables !== []) {
                        $last = array_key_last($tables);

                        if ($tables[$last]['row'] !== null && implode('', $tables[$last]['row']) !== '') {
                            $tables[$last]['rows'][] = $tables[$last]['row'];
                        }

                        $tables[$last]['row'] = null;
                    }
                    break;

                case 'tbl':
                    $table = array_pop($tables);

                    if ($table === null || $table['rows'] === []) {
                        break;
                    }

                    if ($tables !== []) {
                        // A table inside a table cell: flattened into that cell.
                        $last = array_key_last($tables);

                        if ($tables[$last]['cell'] !== null) {
                            foreach ($table['rows'] as $row) {
                                $tables[$last]['cell'][] = implode(' · ', array_filter($row, static fn (string $cell): bool => $cell !== ''));
                            }
                        }

                        break;
                    }

                    $markdown = $this->table($table['rows']);
                    $blocks[] = ['type' => 'table', 'markdown' => $markdown];
                    $this->length += mb_strlen($markdown);
                    break;
            }
        }

        $reader->close();

        return [$blocks, $title];
    }

    /**
     * Add text to the paragraph being read, as part of the current run.
     *
     * @param  array<string, mixed>  $paragraph
     * @param  array{b: bool, i: bool, s: bool}|null  $run
     */
    private function append(array &$paragraph, ?array $run, ?string $link, string $text): void
    {
        if ($text === '') {
            return;
        }

        $run ??= ['b' => false, 'i' => false, 's' => false];

        $paragraph['runs'][] = ['text' => $text, 'b' => $run['b'], 'i' => $run['i'], 's' => $run['s'], 'link' => $link];
    }

    /**
     * One finished paragraph as a block, or null when it holds nothing.
     *
     * @param  array<string, mixed>  $paragraph
     * @return array<string, mixed>|null
     */
    private function paragraph(array $paragraph, bool $inTable): ?array
    {
        $runs = $paragraph['runs'];
        $plain = trim(implode('', array_column($runs, 'text')));

        if ($plain === '') {
            return null;
        }

        // A to-do, from a checkbox at the start of the text.
        $task = null;

        if (preg_match('/^(\[\s?\]|\[[xX]\]|☐|□|☑|☒|✓|✔|✅)\s*/u', $plain, $match) === 1) {
            $task = preg_match('/[xX☑☒✓✔✅]/u', $match[1]) === 1;
            $runs = $this->stripLeading($runs, $match[0]);
            $plain = trim(mb_substr($plain, mb_strlen($match[0])));
        }

        $text = $this->inline($runs, $inTable);

        if ($inTable) {
            return ['type' => 'paragraph', 'text' => ($task === null ? '' : ($task ? '☑ ' : '☐ ')).$text];
        }

        $style = $paragraph['style'] !== null ? ($this->styles[$paragraph['style']] ?? $this->styleFromId($paragraph['style'])) : null;
        $outline = $paragraph['outline'];

        if ($style === 'title') {
            return ['type' => 'heading', 'level' => 1, 'text' => $text, 'title' => true, 'plain' => $plain];
        }

        $level = is_int($style) ? $style : ($outline !== null && $outline < 6 ? $outline + 1 : null);

        if ($level !== null && $task === null && $paragraph['numId'] === null) {
            return ['type' => 'heading', 'level' => $level, 'text' => $text, 'title' => $level === 1, 'plain' => $plain];
        }

        $numId = $paragraph['numId'];
        $format = $numId !== null && $numId !== '0' ? ($this->numbering[$numId][$paragraph['ilvl']] ?? null) : null;

        if ($format !== null || $task !== null) {
            if ($task === null && $format !== null && preg_match('/[☐□❏❑]/u', $format['text']) === 1) {
                $task = false;
            }

            return [
                'type' => 'item',
                'depth' => min(5, max(0, (int) $paragraph['ilvl'])),
                'ordered' => $format !== null && $format['format'] !== 'bullet' && $format['format'] !== 'none',
                'number' => 1,
                'task' => $task,
                'text' => $text,
            ];
        }

        return ['type' => 'paragraph', 'text' => $this->guardStart($text)];
    }

    /**
     * Runs as inline Markdown: escaped text, with emphasis and links.
     *
     * Adjacent runs with the same formatting are merged first — Word splits a
     * word into several runs for reasons nobody can see — and whitespace is
     * kept outside the markers, because `** bold**` is not bold.
     *
     * @param  list<array{text: string, b: bool, i: bool, s: bool, link: ?string}>  $runs
     */
    private function inline(array $runs, bool $inTable): string
    {
        $merged = [];

        foreach ($runs as $run) {
            $key = ($run['b'] ? 'b' : '').($run['i'] ? 'i' : '').($run['s'] ? 's' : '').'|'.($run['link'] ?? '');
            $last = array_key_last($merged);

            if ($last !== null && $merged[$last]['key'] === $key) {
                $merged[$last]['text'] .= $run['text'];

                continue;
            }

            $merged[] = ['key' => $key] + $run;
        }

        $out = '';

        foreach ($merged as $run) {
            $text = $run['text'];

            if (trim($text) === '') {
                $out .= str_contains($text, "\n") ? "\n" : ' ';

                continue;
            }

            preg_match('/^(\s*)(.*?)(\s*)$/us', $text, $parts);
            [$all, $lead, $core, $trail] = $parts + ['', '', '', ''];

            $core = MarkdownText::inline($core);

            if ($run['s']) {
                $core = '~~'.$core.'~~';
            }

            if ($run['i']) {
                $core = '*'.$core.'*';
            }

            if ($run['b']) {
                $core = '**'.$core.'**';
            }

            if ($run['link'] !== null) {
                $core = '['.$core.']('.str_replace([' ', ')'], ['%20', '%29'], $run['link']).')';
            }

            $out .= $lead.$core.$trail;
        }

        $out = (string) preg_replace('/[ \t]+/u', ' ', $out);
        $out = (string) preg_replace('/ *\n */u', "\n", trim($out));

        // A line break inside a paragraph: a hard break in the body, a space in
        // a table cell, which cannot hold one.
        return $inTable ? str_replace("\n", ' ', $out) : str_replace("\n", "\\\n", $out);
    }

    /**
     * A paragraph that would start like structure — "1. " or "# " — escaped so
     * it stays a paragraph.
     */
    private function guardStart(string $text): string
    {
        return (string) preg_replace('/^(#{1,6}\s|>|[-+]\s|\d+[.)]\s)/', '\\\\$1', $text);
    }

    /**
     * Runs without the checkbox `$prefix` they start with.
     *
     * @param  list<array{text: string, b: bool, i: bool, s: bool, link: ?string}>  $runs
     * @return list<array{text: string, b: bool, i: bool, s: bool, link: ?string}>
     */
    private function stripLeading(array $runs, string $prefix): array
    {
        // The box itself ("☒", "[ ]"), without the space after it; that space,
        // and any before the box, may sit in a run of its own.
        $skip = mb_strlen(trim($prefix));
        $trim = true;
        $out = [];

        foreach ($runs as $run) {
            $text = $trim ? ltrim($run['text']) : $run['text'];

            if ($skip > 0) {
                $length = mb_strlen($text);

                if ($length <= $skip) {
                    $skip -= $length;

                    continue;
                }

                $text = ltrim(mb_substr($text, $skip));
                $skip = 0;
            }

            if ($trim && $text === '') {
                continue;
            }

            $trim = false;
            $run['text'] = $text;
            $out[] = $run;
        }

        return $out;
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function table(array $rows): string
    {
        // The first row is the header, as it is in nearly every Word table.
        // Cells are inline Markdown already, so they are not escaped again.
        $header = array_shift($rows);

        return MarkdownText::table($header ?? [], $rows, markdown: true);
    }

    /**
     * The glyph a Wingdings/Symbol character stands for, when it is a box.
     */
    private function symbol(string $code): ?string
    {
        return match (strtoupper(ltrim($code, '0'))) {
            'F0FE', 'F0FD', 'F078', 'F0FB' => '☒',
            'F0A8', 'F06F', 'F071', 'F072' => '☐',
            default => null,
        };
    }

    // -----------------------------------------------------------------
    // Styles, numbering, links, notes
    // -----------------------------------------------------------------

    /**
     * Which paragraph styles are headings, and which is the title.
     *
     * By the style's own name ("heading 2", which Word writes in English even
     * in a localised copy) or its outline level, following `basedOn` one step
     * so a custom "My Heading" built on Heading 2 counts.
     *
     * @return array<string, int|string>
     */
    private function styles(?string $xml): array
    {
        if ($xml === null) {
            return [];
        }

        $reader = SafeXml::reader($xml);
        $found = [];
        $basedOn = [];
        $current = null;

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            switch ($reader->localName) {
                case 'style':
                    $current = SafeXml::attribute($reader, 'type') === 'paragraph'
                        ? SafeXml::attribute($reader, 'styleId')
                        : null;
                    break;

                case 'name':
                    if ($current !== null) {
                        $name = strtolower(trim((string) SafeXml::attribute($reader, 'val')));

                        if ($name === 'title') {
                            $found[$current] = 'title';
                        } elseif (preg_match('/^heading\s*([1-6])$/', $name, $match) === 1) {
                            $found[$current] = (int) $match[1];
                        }
                    }
                    break;

                case 'basedOn':
                    if ($current !== null) {
                        $basedOn[$current] = (string) SafeXml::attribute($reader, 'val');
                    }
                    break;

                case 'outlineLvl':
                    if ($current !== null && ! isset($found[$current])) {
                        $level = (int) SafeXml::attribute($reader, 'val');

                        if ($level >= 0 && $level < 6) {
                            $found[$current] = $level + 1;
                        }
                    }
                    break;
            }
        }

        $reader->close();

        foreach ($basedOn as $style => $parent) {
            if (! isset($found[$style]) && isset($found[$parent])) {
                $found[$style] = $found[$parent];
            }
        }

        return $found;
    }

    /**
     * A heading or title recognised from the style id alone, for documents
     * that ship without styles.xml.
     */
    private function styleFromId(string $id): int|string|null
    {
        if (strcasecmp($id, 'Title') === 0) {
            return 'title';
        }

        return preg_match('/^heading\s*([1-6])$/i', $id, $match) === 1 ? (int) $match[1] : null;
    }

    /**
     * numId => level => [format, text]: whether each list level is a bullet or
     * numbered, and what its marker looks like.
     *
     * @return array<string, array<int, array{format: string, text: string}>>
     */
    private function numbering(?string $xml): array
    {
        if ($xml === null) {
            return [];
        }

        $reader = SafeXml::reader($xml);

        $abstract = [];
        $nums = [];
        $currentAbstract = null;
        $currentLevel = null;
        $currentNum = null;

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            switch ($reader->localName) {
                case 'abstractNum':
                    $currentAbstract = SafeXml::attribute($reader, 'abstractNumId');
                    $currentNum = null;
                    break;

                case 'lvl':
                    $currentLevel = (int) SafeXml::attribute($reader, 'ilvl');

                    if ($currentAbstract !== null) {
                        $abstract[$currentAbstract][$currentLevel] = ['format' => 'decimal', 'text' => ''];
                    }
                    break;

                case 'numFmt':
                    if ($currentAbstract !== null && $currentLevel !== null && $currentNum === null) {
                        $abstract[$currentAbstract][$currentLevel]['format'] = (string) SafeXml::attribute($reader, 'val');
                    }
                    break;

                case 'lvlText':
                    if ($currentAbstract !== null && $currentLevel !== null && $currentNum === null) {
                        $abstract[$currentAbstract][$currentLevel]['text'] = (string) SafeXml::attribute($reader, 'val');
                    }
                    break;

                case 'num':
                    $currentNum = SafeXml::attribute($reader, 'numId');
                    $currentAbstract = null;
                    break;

                case 'abstractNumId':
                    if ($currentNum !== null) {
                        $nums[$currentNum] = (string) SafeXml::attribute($reader, 'val');
                    }
                    break;
            }
        }

        $reader->close();

        $resolved = [];

        foreach ($nums as $num => $abstractId) {
            if (isset($abstract[$abstractId])) {
                $resolved[(string) $num] = $abstract[$abstractId];
            }
        }

        return $resolved;
    }

    /**
     * Web and mail addresses only; a link to anything else is dropped, so a
     * crafted document cannot put a `javascript:` or `file:` link on a page.
     *
     * @param  array<string, array{target: string, external: bool, type: string}>  $relationships
     * @return array<string, string>
     */
    private function links(array $relationships): array
    {
        $links = [];

        foreach ($relationships as $id => $relationship) {
            if ($relationship['external'] && preg_match('#^(https?://|mailto:)#i', $relationship['target']) === 1) {
                $links[$id] = $relationship['target'];
            }
        }

        return $links;
    }

    /**
     * Footnote or endnote text, keyed by id, as inline Markdown.
     *
     * @return array<string, string>
     */
    private function notes(?string $xml, string $element): array
    {
        if ($xml === null) {
            return [];
        }

        $reader = SafeXml::reader($xml);
        $notes = [];
        $current = null;
        $text = '';

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                if ($reader->localName === $element) {
                    $id = (int) SafeXml::attribute($reader, 'id');
                    $current = $id > 0 ? $element.$id : null;
                    $text = '';
                } elseif ($reader->localName === 't' && $current !== null && ! $reader->isEmptyElement) {
                    $text .= $reader->readString();
                }

                continue;
            }

            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === $element) {
                if ($current !== null && trim($text) !== '') {
                    $notes[$current] = MarkdownText::inline(trim((string) preg_replace('/\s+/u', ' ', $text)));
                }

                $current = null;
            }
        }

        $reader->close();

        return $notes;
    }
}
