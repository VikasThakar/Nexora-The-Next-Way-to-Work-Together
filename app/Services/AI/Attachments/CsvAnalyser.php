<?php

declare(strict_types=1);

namespace App\Services\AI\Attachments;

/**
 * The safe data-processing layer for spreadsheets.
 *
 * This class is the answer to a specific question: how can the assistant tell
 * somebody the average resolution time across forty thousand rows, without
 * either sending forty thousand rows to a language model or letting a language
 * model run code against the file?
 *
 * Neither of those is acceptable. The rows would not fit, and where they did
 * they would be a fortune in tokens for a question with a one-number answer.
 * And executing model-authored code — a filter expression, a formula, a snippet
 * of SQL over the parsed rows — would mean an untrusted party had chosen what
 * this server computes, which is the thing the whole AI security model in this
 * application is built to prevent.
 *
 * So the arithmetic happens *here*, in PHP, over the whole file, before the
 * model is involved at all. The model receives:
 *
 *   - a profile: every column, its detected type, and for a numeric column its
 *     count, sum, mean, median, minimum, maximum and standard deviation; for a
 *     text column its distinct count and most frequent values;
 *   - the outliers, already identified, because "find unusual values" is a
 *     question this can answer exactly and a model cannot answer at all from a
 *     sample;
 *   - a sample of rows, capped, and explicitly labelled a sample.
 *
 * The model's job is then reading and explaining, which is what it is good at.
 * There is no expression to evaluate, no query to parse and no code path from
 * anything the model says to anything this class computes — the profile is
 * identical whatever the question was.
 *
 * What it deliberately does not do
 * --------------------------------
 * It does not answer questions. "Top 10 customers by revenue" is answered by
 * the model, from the sample and the profile, and if the sample does not
 * contain the answer the prompt says so — see AiAttachmentContext. Building a
 * query language here so the model could ask for exactly that ranking would be
 * the tool-execution loop this architecture does not have, and the honest
 * version of it is a good deal more work than it looks.
 */
class CsvAnalyser
{
    /**
     * Rows read from the file, at most. A ceiling on work, and `rows_scanned`
     * in the result says how many were actually read, so a file larger than
     * this reports what was covered rather than implying it covered all of it.
     */
    private const MAX_ROWS_SCANNED = 50000;

    /** How many of the most frequent values a text column reports. */
    private const TOP_VALUES = 5;

    /** How many outliers are named per numeric column. */
    private const MAX_OUTLIERS = 5;

    /**
     * Parse and profile a delimited file.
     *
     * @return array{
     *     delimiter: string,
     *     has_headers: bool,
     *     headers: list<string>,
     *     rows: list<list<string>>,
     *     rows_scanned: int,
     *     truncated_columns: bool,
     *     columns: list<array<string, mixed>>,
     *     outliers: list<array<string, mixed>>,
     * }
     */
    public function analyse(string $contents): array
    {
        $delimiter = $this->detectDelimiter($contents);

        [$records, $truncatedColumns] = $this->parse($contents, $delimiter);

        if ($records === []) {
            return [
                'delimiter' => $delimiter,
                'has_headers' => false,
                'headers' => [],
                'rows' => [],
                'rows_scanned' => 0,
                'truncated_columns' => false,
                'columns' => [],
                'outliers' => [],
            ];
        }

        $hasHeaders = $this->looksLikeHeaderRow($records);
        $headers = $hasHeaders ? $this->headerNames(array_shift($records)) : $this->syntheticHeaders($records);

        $columns = [];
        $outliers = [];

        foreach ($headers as $index => $name) {
            $values = $this->column($records, $index);
            $profile = $this->profile($name, $index, $values);

            $columns[] = $profile;

            foreach ($this->outliersFor($profile, $values, $name) as $outlier) {
                $outliers[] = $outlier;
            }
        }

        return [
            'delimiter' => $delimiter,
            'has_headers' => $hasHeaders,
            'headers' => $headers,
            'rows' => $records,
            'rows_scanned' => count($records),
            'truncated_columns' => $truncatedColumns,
            'columns' => $columns,
            'outliers' => $outliers,
        ];
    }

    // -----------------------------------------------------------------
    // Parsing
    // -----------------------------------------------------------------

    /**
     * Which character separates the fields.
     *
     * Decided by counting candidates in the first few lines and taking the one
     * that appears most *consistently* rather than most often. Frequency alone
     * picks the comma out of a semicolon-delimited European export whose text
     * fields are full of commas; consistency across lines does not.
     */
    public function detectDelimiter(string $contents): string
    {
        $sample = array_slice(preg_split('/\r\n|\r|\n/', $contents) ?: [], 0, 10);
        $sample = array_values(array_filter($sample, static fn (string $line): bool => trim($line) !== ''));

        if ($sample === []) {
            return ',';
        }

        $best = ',';
        $bestScore = -1.0;

        foreach ([',', ';', "\t", '|'] as $candidate) {
            $counts = array_map(
                static fn (string $line): int => substr_count($line, $candidate),
                $sample
            );

            $total = array_sum($counts);

            if ($total === 0) {
                continue;
            }

            // Consistency: how close every line is to the average count. A
            // delimiter that appears twice on every line beats one that appears
            // nine times on one line and never again.
            $mean = $total / count($counts);
            $spread = 0.0;

            foreach ($counts as $count) {
                $spread += abs($count - $mean);
            }

            $score = $mean - ($spread / count($counts));

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * Read the rows.
     *
     * str_getcsv per line rather than a hand-rolled split, so quoted fields
     * containing the delimiter, escaped quotes and empty trailing fields behave
     * the way every spreadsheet expects. Lines are re-joined when a quote is
     * left open, which is how a field containing a newline survives.
     *
     * @return array{0: list<list<string>>, 1: bool}
     */
    private function parse(string $contents, string $delimiter): array
    {
        $maxColumns = max(1, (int) config('ai.attachments.csv.max_columns', 40));

        $rows = [];
        $truncatedColumns = false;
        $buffer = '';

        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            $buffer = $buffer === '' ? $line : $buffer."\n".$line;

            // An odd number of quote characters means the record continues on
            // the next line.
            if (substr_count($buffer, '"') % 2 !== 0) {
                continue;
            }

            $record = $buffer;
            $buffer = '';

            if (trim($record) === '') {
                continue;
            }

            $fields = str_getcsv($record, $delimiter, '"', '\\');

            if (count($fields) > $maxColumns) {
                $fields = array_slice($fields, 0, $maxColumns);
                $truncatedColumns = true;
            }

            $rows[] = array_map(
                static fn ($field): string => trim((string) $field),
                $fields
            );

            if (count($rows) >= self::MAX_ROWS_SCANNED) {
                break;
            }
        }

        // A record left open at end of file: take it as it stands rather than
        // discarding the last row of somebody's export.
        if (trim($buffer) !== '' && count($rows) < self::MAX_ROWS_SCANNED) {
            $rows[] = array_map(
                static fn ($field): string => trim((string) $field),
                str_getcsv($buffer, $delimiter, '"', '\\')
            );
        }

        return [$rows, $truncatedColumns];
    }

    /**
     * Does the first row name the columns?
     *
     * Two conditions: no cell in the first row is a number, and some later row
     * contains one. That is what a header looks like and what a data row does
     * not.
     *
     * It is deliberately conservative, and the asymmetry is the reason. Reading
     * a header as data costs a synthetic column name and one extra row in a
     * distinct-value count. Reading data as a header silently deletes a row of
     * somebody's figures from every sum and average this class computes, and
     * nothing downstream could ever notice. So the doubtful case resolves to
     * "no header".
     *
     * The consequence worth knowing about: a file with no numeric column at all
     * — names and cities, say — gets numbered columns even when its first row
     * plainly reads as a header. Both readings of such a file are defensible,
     * and the harmless one is chosen.
     *
     * A blank cell in the first row does not disqualify it. A trailing empty
     * column is extremely common in exported data, and headerNames() already
     * numbers an unnamed column; treating the blank as evidence of data would
     * have contradicted that.
     *
     * @param  list<list<string>>  $records
     */
    private function looksLikeHeaderRow(array $records): bool
    {
        if (count($records) < 2) {
            return false;
        }

        $first = $records[0];

        if ($first === []) {
            return false;
        }

        $named = 0;

        foreach ($first as $value) {
            if ($this->isNumeric($value)) {
                return false;
            }

            $named += $value === '' ? 0 : 1;
        }

        // An entirely blank first row names nothing.
        if ($named === 0) {
            return false;
        }

        foreach (array_slice($records, 1, 20) as $row) {
            foreach ($row as $value) {
                if ($this->isNumeric($value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $row
     * @return list<string>
     */
    private function headerNames(array $row): array
    {
        $names = [];

        foreach ($row as $index => $value) {
            $name = trim($value);
            $names[] = $name === '' ? 'Column '.($index + 1) : mb_substr($name, 0, 120);
        }

        return $names;
    }

    /**
     * @param  list<list<string>>  $records
     * @return list<string>
     */
    private function syntheticHeaders(array $records): array
    {
        $width = 0;

        foreach ($records as $row) {
            $width = max($width, count($row));
        }

        $names = [];

        for ($index = 0; $index < $width; $index++) {
            $names[] = 'Column '.($index + 1);
        }

        return $names;
    }

    // -----------------------------------------------------------------
    // Profiling
    // -----------------------------------------------------------------

    /**
     * @param  list<list<string>>  $records
     * @return list<string>
     */
    private function column(array $records, int $index): array
    {
        $values = [];

        foreach ($records as $row) {
            $values[] = (string) ($row[$index] ?? '');
        }

        return $values;
    }

    /**
     * Everything computable about one column.
     *
     * The type decides which half of the answer is filled in, and the rule is
     * majority-numeric: a column of amounts with three blanks and one "n/a" is
     * still a numeric column, and treating it as text would lose the only
     * question anybody was going to ask about it.
     *
     * @param  list<string>  $values
     * @return array<string, mixed>
     */
    private function profile(string $name, int $index, array $values): array
    {
        $filled = array_values(array_filter($values, static fn (string $value): bool => $value !== ''));

        $numbers = [];

        foreach ($filled as $value) {
            if ($this->isNumeric($value)) {
                $numbers[] = $this->toNumber($value);
            }
        }

        $isNumeric = $filled !== [] && count($numbers) >= (int) ceil(count($filled) * 0.8);

        $profile = [
            'name' => $name,
            'index' => $index,
            'type' => $isNumeric ? 'number' : ($this->looksLikeDates($filled) ? 'date' : 'text'),
            'filled' => count($filled),
            'blank' => count($values) - count($filled),
        ];

        if ($isNumeric && $numbers !== []) {
            sort($numbers);

            $count = count($numbers);
            $sum = array_sum($numbers);
            $mean = $sum / $count;

            $profile += [
                'count' => $count,
                'sum' => $this->round($sum),
                'mean' => $this->round($mean),
                'median' => $this->round($this->median($numbers)),
                'min' => $this->round($numbers[0]),
                'max' => $this->round($numbers[$count - 1]),
                'std_dev' => $this->round($this->standardDeviation($numbers, $mean)),
            ];

            return $profile;
        }

        $counts = array_count_values(array_map(
            static fn (string $value): string => mb_substr($value, 0, 120),
            $filled
        ));

        arsort($counts);

        $profile['distinct'] = count($counts);
        $profile['top_values'] = [];

        foreach (array_slice($counts, 0, self::TOP_VALUES, true) as $value => $frequency) {
            $profile['top_values'][] = ['value' => (string) $value, 'count' => $frequency];
        }

        return $profile;
    }

    /**
     * The values a person would call unusual.
     *
     * Numeric columns only, and by distance from the mean in standard
     * deviations — the definition everybody means by "outlier" and the only one
     * that is scale-free, so it works on both a column of prices and a column
     * of durations without being told which is which.
     *
     * A column with no spread has no outliers, which is why the zero check is
     * there rather than as a division-by-zero guard: every value being
     * identical is a meaningful answer, not an edge case.
     *
     * @param  array<string, mixed>  $profile
     * @param  list<string>  $values
     * @return list<array<string, mixed>>
     */
    private function outliersFor(array $profile, array $values, string $column): array
    {
        if (($profile['type'] ?? null) !== 'number') {
            return [];
        }

        $mean = (float) ($profile['mean'] ?? 0);
        $deviation = (float) ($profile['std_dev'] ?? 0);

        if ($deviation <= 0.0) {
            return [];
        }

        $found = [];

        foreach ($values as $row => $value) {
            if ($value === '' || ! $this->isNumeric($value)) {
                continue;
            }

            $number = $this->toNumber($value);
            $distance = abs($number - $mean) / $deviation;

            if ($distance < 3.0) {
                continue;
            }

            $found[] = [
                'column' => $column,
                // One-based, and counted from the first data row, which is what
                // a person looking at a spreadsheet means by "row 4".
                'row' => $row + 1,
                'value' => $this->round($number),
                'deviations' => round($distance, 1),
            ];
        }

        // The most extreme first, so a cap keeps the interesting ones.
        usort($found, static fn (array $a, array $b): int => $b['deviations'] <=> $a['deviations']);

        return array_slice($found, 0, self::MAX_OUTLIERS);
    }

    // -----------------------------------------------------------------
    // Numbers
    // -----------------------------------------------------------------

    /**
     * Is this a number as a spreadsheet would mean it?
     *
     * Wider than is_numeric on purpose: thousands separators, a currency
     * symbol, a percentage sign, and parentheses for a negative are all how
     * exported data actually looks, and a column of "$1,240.00" that profiled
     * as text would answer none of the questions anybody has about it.
     */
    private function isNumeric(string $value): bool
    {
        return is_numeric($this->strip($value));
    }

    private function toNumber(string $value): float
    {
        $stripped = $this->strip($value);
        $number = (float) $stripped;

        // "(1,240.00)" is accountancy for -1240.
        return str_starts_with(trim($value), '(') && str_ends_with(trim($value), ')')
            ? -abs($number)
            : $number;
    }

    private function strip(string $value): string
    {
        $value = trim($value);
        $value = trim($value, '()');

        // Currency symbols, spaces and thousands separators.
        $value = (string) preg_replace('/[\p{Sc}\s]/u', '', $value);
        $value = str_replace(',', '', $value);

        return rtrim($value, '%');
    }

    /**
     * Does this column hold dates?
     *
     * Only used to label the column in the profile — nothing computes with it,
     * which is why the test is a cheap pattern match rather than a real parse.
     * ISO first because that is what an export from this product produces.
     *
     * @param  list<string>  $values
     */
    private function looksLikeDates(array $values): bool
    {
        if ($values === []) {
            return false;
        }

        $sample = array_slice($values, 0, 20);
        $matches = 0;

        foreach ($sample as $value) {
            if (preg_match('#^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2})?#', $value) === 1
                || preg_match('#^\d{1,2}[/.]\d{1,2}[/.]\d{2,4}$#', $value) === 1) {
                $matches++;
            }
        }

        return $matches >= (int) ceil(count($sample) * 0.8);
    }

    /**
     * @param  list<float>  $sorted
     */
    private function median(array $sorted): float
    {
        $count = count($sorted);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $sorted[$middle]
            : ($sorted[$middle - 1] + $sorted[$middle]) / 2;
    }

    /**
     * Population standard deviation.
     *
     * Population rather than sample: this is the whole column, not a draw from
     * something larger, so dividing by n is the correct denominator.
     *
     * @param  list<float>  $numbers
     */
    private function standardDeviation(array $numbers, float $mean): float
    {
        if (count($numbers) < 2) {
            return 0.0;
        }

        $sum = 0.0;

        foreach ($numbers as $number) {
            $sum += ($number - $mean) ** 2;
        }

        return sqrt($sum / count($numbers));
    }

    /**
     * Trim floating-point noise without losing precision that matters.
     *
     * Four decimal places, and an integer stays an integer — a count of 12
     * should not reach the prompt as 12.0, which invites a model to describe it
     * as an average.
     */
    private function round(float $value): float|int
    {
        $rounded = round($value, 4);

        return $rounded === floor($rounded) && abs($rounded) < PHP_INT_MAX
            ? (int) $rounded
            : $rounded;
    }
}
