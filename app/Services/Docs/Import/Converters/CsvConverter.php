<?php

declare(strict_types=1);

namespace App\Services\Docs\Import\Converters;

use App\Services\Docs\Import\ConvertedDocument;
use App\Services\Docs\Import\DocumentImportException;
use App\Services\Docs\Import\MarkdownText;

/**
 * A CSV or TSV file as a Markdown table, the first row as the header.
 *
 * The delimiter is the extension's for .tsv and otherwise whichever of comma,
 * semicolon and tab splits the first line most — a European spreadsheet export
 * uses semicolons. Bounded at `$maxRows` rows and 30 columns; the file itself
 * is attached for anybody who needs every row.
 */
final class CsvConverter
{
    private const MAX_COLUMNS = 30;

    public function __construct(private readonly int $maxRows = 500) {}

    public function convert(string $path, string $filename, callable $normalise): ConvertedDocument
    {
        $contents = @file_get_contents($path);

        if (! is_string($contents)) {
            throw new DocumentImportException('That file could not be read.');
        }

        $text = $normalise($contents);

        if (trim($text) === '') {
            throw new DocumentImportException('That file is empty.');
        }

        $delimiter = strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'tsv'
            ? "\t"
            : $this->delimiter(strtok($text, "\n") ?: '');

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $text);
        rewind($stream);

        $rows = [];
        $cut = false;

        while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            if ($row === [null] || implode('', array_map('strval', $row)) === '') {
                continue;
            }

            if (count($rows) > $this->maxRows) {
                $cut = true;

                break;
            }

            if (count($row) > self::MAX_COLUMNS) {
                $cut = true;
                $row = array_slice($row, 0, self::MAX_COLUMNS);
            }

            $rows[] = array_map(static fn ($cell): string => trim((string) $cell), $row);
        }

        fclose($stream);

        if ($rows === []) {
            throw new DocumentImportException('That file has no rows.');
        }

        $header = array_shift($rows);

        return new ConvertedDocument(
            MarkdownText::table($header, $rows),
            null,
            $cut ? ['The file has more than '.number_format($this->maxRows).' rows or '.self::MAX_COLUMNS
                .' columns; the page shows the first ones and the file is attached.'] : [],
        );
    }

    private function delimiter(string $line): string
    {
        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];

        arsort($counts);

        return (string) array_key_first($counts);
    }
}
