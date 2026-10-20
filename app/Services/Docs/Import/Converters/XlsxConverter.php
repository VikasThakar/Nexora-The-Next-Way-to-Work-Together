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
 * An Excel workbook (.xlsx) as Markdown: each sheet a heading and a table.
 *
 * A spreadsheet's text lives in two places — a shared table of strings, and the
 * sheets themselves, whose cells point into it by number — so a sheet is read
 * cell by cell and each value resolved: shared strings, inline strings,
 * formula results and plain numbers. Cells are placed by their reference (C7
 * is row 7, column 3), so a sheet with gaps keeps its columns lined up.
 *
 * Bounded at `$maxRows` rows and 30 columns a sheet: a page is for reading, and
 * the workbook itself is attached for anybody who needs every row.
 */
final class XlsxConverter
{
    private const MAX_COLUMNS = 30;

    public function __construct(private readonly int $maxRows = 500) {}

    public function convert(string $path): ConvertedDocument
    {
        $package = OoxmlPackage::open($path);

        if ($package === null) {
            throw new DocumentImportException(
                'That workbook could not be opened. It may be corrupt, or saved in an older format — '
                .'re-saving it as .xlsx will fix it.'
            );
        }

        $blocks = [];
        $warnings = [];

        try {
            $strings = $this->sharedStrings($package->read('xl/sharedStrings.xml'));
            $sheets = $this->sheets($package);

            foreach ($sheets as $sheet) {
                $xml = $package->read($sheet['path']);

                if ($xml === null) {
                    continue;
                }

                [$rows, $cut] = $this->rows($xml, $strings);

                if ($rows === []) {
                    continue;
                }

                $header = array_shift($rows);

                $blocks[] = ['type' => 'heading', 'level' => 2, 'text' => MarkdownText::inline($sheet['name'])];
                $blocks[] = ['type' => 'table', 'markdown' => MarkdownText::table($header, $rows)];

                if ($cut) {
                    $warnings[] = 'The sheet "'.$sheet['name'].'" has more than '.number_format($this->maxRows)
                        .' rows or '.self::MAX_COLUMNS.' columns; the page shows the first ones and the workbook is attached.';
                }
            }
        } catch (RuntimeException) {
            throw new DocumentImportException(
                'That workbook could not be read. It may be corrupt — opening it in Excel and saving it again usually fixes this.'
            );
        } finally {
            $package->close();
        }

        return new ConvertedDocument(MarkdownBlocks::render($blocks), null, $warnings);
    }

    /**
     * The workbook's sheets, in the order the workbook lists them.
     *
     * @return list<array{name: string, path: string}>
     */
    private function sheets(OoxmlPackage $package): array
    {
        $workbook = $package->read('xl/workbook.xml');
        $relationships = $package->relationships('xl/workbook.xml');

        $sheets = [];

        if ($workbook !== null) {
            $reader = SafeXml::reader($workbook);

            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'sheet') {
                    continue;
                }

                $id = SafeXml::attribute($reader, 'id');
                $target = $id !== null ? ($relationships[$id]['target'] ?? null) : null;

                if ($target !== null) {
                    $sheets[] = ['name' => (string) SafeXml::attribute($reader, 'name'), 'path' => $target];
                }
            }

            $reader->close();
        }

        if ($sheets !== []) {
            return $sheets;
        }

        // A workbook without a readable index: its sheets by file name.
        foreach ($package->matching('#^xl/worksheets/sheet\d+\.xml$#') as $index => $path) {
            $sheets[] = ['name' => 'Sheet '.($index + 1), 'path' => $path];
        }

        return $sheets;
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(?string $xml): array
    {
        if ($xml === null) {
            return [];
        }

        $reader = SafeXml::reader($xml);
        $strings = [];
        $current = null;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                if ($reader->localName === 'si') {
                    $current = '';
                } elseif ($reader->localName === 't' && $current !== null && ! $reader->isEmptyElement) {
                    // A rich-text cell is several runs, each with its own <t>.
                    $current .= $reader->readString();
                } elseif ($reader->localName === 'rPh') {
                    // Phonetic guides for East Asian text: not the cell's value.
                    $reader->next();
                }

                continue;
            }

            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'si') {
                $strings[] = (string) $current;
                $current = null;
            }
        }

        $reader->close();

        return $strings;
    }

    /**
     * One sheet's non-empty rows, each padded to its widest used column.
     *
     * @param  list<string>  $strings
     * @return array{0: list<list<string>>, 1: bool} the rows, and whether any were left out
     */
    private function rows(string $xml, array $strings): array
    {
        $reader = SafeXml::reader($xml);

        $rows = [];
        $row = null;
        $cell = null;
        $cut = false;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                switch ($reader->localName) {
                    case 'row':
                        if (count($rows) >= $this->maxRows) {
                            $cut = true;

                            break 2;
                        }

                        $row = [];
                        break;

                    case 'c':
                        $column = $this->column((string) SafeXml::attribute($reader, 'r'), $row === null ? 0 : count($row));

                        if ($column >= self::MAX_COLUMNS) {
                            $cut = true;
                            $cell = null;
                            break;
                        }

                        $cell = ['column' => $column, 'type' => (string) SafeXml::attribute($reader, 't'), 'value' => ''];
                        break;

                    case 'v':
                        if ($cell !== null && ! $reader->isEmptyElement) {
                            $cell['value'] = $reader->readString();
                        }
                        break;

                    case 't':
                        // An inline string: <c t="inlineStr"><is><t>…</t></is></c>.
                        if ($cell !== null && ! $reader->isEmptyElement) {
                            $cell['value'] .= $reader->readString();
                        }
                        break;
                }

                continue;
            }

            if ($reader->nodeType !== XMLReader::END_ELEMENT) {
                continue;
            }

            if ($reader->localName === 'c' && $cell !== null && $row !== null) {
                $value = match ($cell['type']) {
                    's' => $strings[(int) $cell['value']] ?? '',
                    'b' => $cell['value'] === '1' ? 'TRUE' : 'FALSE',
                    default => $cell['value'],
                };

                $row[$cell['column']] = trim($value);
                $cell = null;
            }

            if ($reader->localName === 'row' && $row !== null) {
                if (implode('', $row) !== '') {
                    $filled = [];

                    for ($index = 0, $last = max(array_keys($row)); $index <= $last; $index++) {
                        $filled[] = $row[$index] ?? '';
                    }

                    $rows[] = $filled;
                }

                $row = null;
            }
        }

        $reader->close();

        return [$rows, $cut];
    }

    /**
     * Zero-based column of a cell reference: "C7" is 2, "AA1" is 26.
     */
    private function column(string $reference, int $fallback): int
    {
        if (preg_match('/^([A-Z]+)\d+$/i', $reference, $match) !== 1) {
            return $fallback;
        }

        $column = 0;

        foreach (str_split(strtoupper($match[1])) as $letter) {
            $column = $column * 26 + (ord($letter) - 64);
        }

        return $column - 1;
    }
}
