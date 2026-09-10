<?php

declare(strict_types=1);

namespace App\Support\RichResponse;

/**
 * A table the assistant produced, after validation.
 *
 * The existence of this type is the security boundary for tabular answers. A
 * model emits JSON; nothing renders that JSON. It is parsed into one of these
 * or discarded, and what reaches Blade is a fixed set of strings in a fixed
 * shape — so there is no path from a model's output to markup, an attribute, or
 * a template expression.
 *
 * Every value is coerced to a string here rather than at the template. A model
 * that returns a nested object for a cell, or a boolean, or null, produces a
 * cell this can print instead of an error in a view — and the coercion is in
 * one place, where it can be read, rather than repeated at every echo.
 *
 * Ragged rows are squared off. A model that emits a row with four cells for a
 * five-column table is common, and the alternative to padding it is a table
 * whose columns stop lining up halfway down.
 */
final readonly class TableSpec
{
    /** The most columns a table may have. Beyond this it is not a table. */
    private const MAX_COLUMNS = 40;

    /** The most rows. A model producing more than this has misread its brief. */
    private const MAX_ROWS = 2000;

    /**
     * @param  list<string>  $columns
     * @param  list<list<string>>  $rows
     */
    private function __construct(
        public array $columns,
        public array $rows,
        public ?string $title = null,
        public ?string $caption = null,
    ) {}

    /**
     * Build from a decoded JSON structure, or return null.
     *
     * Null for anything that is not a usable table, and the caller then leaves
     * the block as ordinary prose. That is deliberately forgiving: a malformed
     * chart or table should degrade to the text the model wrote, never to an
     * error message in the middle of an answer.
     *
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $columns = self::strings($data['columns'] ?? $data['headers'] ?? null);

        if ($columns === []) {
            return null;
        }

        $columns = array_slice($columns, 0, self::MAX_COLUMNS);

        $rawRows = $data['rows'] ?? $data['data'] ?? null;

        if (! is_array($rawRows)) {
            return null;
        }

        $rows = [];

        foreach ($rawRows as $row) {
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }

            $cells = self::row($row, $columns);

            if ($cells !== null) {
                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            return null;
        }

        return new self(
            columns: $columns,
            rows: $rows,
            title: self::text($data['title'] ?? null),
            caption: self::text($data['caption'] ?? $data['note'] ?? null),
        );
    }

    /**
     * Build from a parsed Markdown pipe table.
     *
     * The path most tables actually take. A model asked for a table will often
     * write a Markdown one whatever the prompt says, and promoting that to a
     * real table is what makes "render tables as proper UI tables rather than
     * plain text" true in practice rather than only when the model cooperates.
     *
     * @param  list<string>  $columns
     * @param  list<list<string>>  $rows
     */
    public static function fromMarkdown(array $columns, array $rows): ?self
    {
        $columns = array_slice(self::strings($columns), 0, self::MAX_COLUMNS);

        if ($columns === []) {
            return null;
        }

        $squared = [];

        foreach (array_slice($rows, 0, self::MAX_ROWS) as $row) {
            $cells = self::row($row, $columns);

            if ($cells !== null) {
                $squared[] = $cells;
            }
        }

        return $squared === [] ? null : new self(columns: $columns, rows: $squared);
    }

    public function columnCount(): int
    {
        return count($this->columns);
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    /**
     * The table as the rows a CSV export writes.
     *
     * The same structure App\Support\CsvDownload already takes, so the export
     * reuses the product's existing download path — BOM, headers, streaming and
     * all — rather than growing a second one.
     *
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public function toCsvTable(): array
    {
        return ['headers' => $this->columns, 'rows' => $this->rows];
    }

    /**
     * The table as tab-separated text, for the copy button.
     *
     * TSV rather than CSV because the destination is a clipboard, and a
     * spreadsheet pasting from the clipboard splits on tabs. Tabs inside a cell
     * become spaces, which is lossy and correct: the alternative is quoting
     * rules that a paste does not honour anyway.
     */
    public function toClipboardText(): string
    {
        $flatten = static fn (array $cells): string => implode("\t", array_map(
            static fn (string $cell): string => str_replace(["\t", "\n", "\r"], ' ', $cell),
            $cells
        ));

        $lines = [$flatten($this->columns)];

        foreach ($this->rows as $row) {
            $lines[] = $flatten($row);
        }

        return implode("\n", $lines);
    }

    // -----------------------------------------------------------------

    /**
     * One row, squared to the column count.
     *
     * @param  list<string>  $columns
     * @return list<string>|null
     */
    private static function row(mixed $row, array $columns): ?array
    {
        if (! is_array($row)) {
            return null;
        }

        $width = count($columns);

        // Object-shaped rows keyed by column name, which is the other form a
        // model naturally produces.
        if (! array_is_list($row)) {
            $cells = [];

            foreach ($columns as $column) {
                $cells[] = self::cell($row[$column] ?? '');
            }

            return $cells;
        }

        $cells = array_map(static fn (mixed $cell): string => self::cell($cell), array_slice($row, 0, $width));

        // Padded rather than dropped: a short row is a model's arithmetic
        // mistake, not a reason to lose the data it did produce.
        return array_pad($cells, $width, '');
    }

    /**
     * One cell, as text.
     *
     * Everything becomes a string, including the things that should not have
     * been in a cell. A nested array is JSON-encoded rather than dropped, so
     * the answer is visibly odd instead of quietly missing a value.
     */
    private static function cell(mixed $value): string
    {
        if (is_string($value)) {
            return trim(mb_substr($value, 0, 500));
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value === null) {
            return '';
        }

        return mb_substr((string) json_encode($value), 0, 500);
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            $string = self::cell($item);

            if ($string !== '') {
                $strings[] = $string;
            }
        }

        return $strings;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 200);
    }
}
