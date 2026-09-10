<?php

declare(strict_types=1);

namespace App\Services\AI\Attachments;

use App\Enums\AiAttachmentKind;
use App\Models\Attachment;
use App\Services\AI\Attachments\Concerns\NormalisesText;
use App\Services\AI\Attachments\Concerns\ReadsStoredFiles;

/**
 * Spreadsheets: .csv, .tsv.
 *
 * A CSV is the one attachment kind where handing the model the file's text
 * would be actively misleading, which is why this processor produces something
 * different in shape from every other one.
 *
 * Send a language model forty thousand rows and one of two things happens: the
 * rows do not fit, or they do and it answers arithmetic questions by reading —
 * badly, expensively, and with no way for anybody to tell that the average it
 * quoted is wrong. Send it three hundred rows and it will still answer "what is
 * the average" as though it had seen all of them.
 *
 * So the file is split into three things, and the prompt keeps them apart:
 *
 *   the profile   computed by CsvAnalyser over *every* row: column types,
 *                 sums, means, medians, ranges, distinct counts, frequent
 *                 values, and the outliers. This is where an arithmetic answer
 *                 comes from, and it is exact.
 *   a sample      the first N rows as a table, labelled a sample, so the model
 *                 can see what the data looks like and quote examples.
 *   the shape     row and column counts, so it can say when a question needs
 *                 more than the sample contains.
 *
 * The text stored in `extracted_text` is that assembled description, and the
 * structured part is stored separately so the attachment card can show the
 * shape and the export can reproduce the exact table.
 *
 * Nothing here evaluates anything the model says. See CsvAnalyser for why that
 * is the whole point.
 */
class CsvProcessor implements AiAttachmentProcessor
{
    use NormalisesText;
    use ReadsStoredFiles;

    public function __construct(private readonly CsvAnalyser $analyser) {}

    public function kind(): AiAttachmentKind
    {
        return AiAttachmentKind::Csv;
    }

    public function process(Attachment $attachment): AiAttachmentExtraction
    {
        $contents = $this->contents($attachment);

        if ($contents === null) {
            return AiAttachmentExtraction::failed(
                'The stored file could not be read. Try attaching it again.'
            );
        }

        $analysis = $this->analyser->analyse($this->normalise($contents));

        if ($analysis['headers'] === [] || $analysis['rows'] === []) {
            return AiAttachmentExtraction::ready(
                summary: 'No rows found',
                structured: ['rows' => 0, 'columns' => 0],
            );
        }

        $sampleSize = max(1, (int) config('ai.attachments.csv.max_rows_in_prompt', 300));
        $sample = array_slice($analysis['rows'], 0, $sampleSize);
        $sampled = count($sample) < count($analysis['rows']);

        $text = $this->describe($analysis, $sample, $sampled);

        [$text, $truncated] = $this->truncate($text);

        return AiAttachmentExtraction::ready(
            text: $text,
            structured: [
                'rows' => $analysis['rows_scanned'],
                'columns' => count($analysis['headers']),
                'headers' => $analysis['headers'],
                'has_headers' => $analysis['has_headers'],
                'delimiter' => $analysis['delimiter'] === "\t" ? 'tab' : $analysis['delimiter'],
                'sampled' => $sampled,
                'sample_rows' => count($sample),
                'profile' => $analysis['columns'],
                'outliers' => $analysis['outliers'],
                // The sample as data, so an export reproduces exactly what the
                // model was shown rather than re-deriving it.
                'sample' => $sample,
            ],
            summary: number_format($analysis['rows_scanned']).' rows × '
                .count($analysis['headers']).' columns',
            truncated: $truncated || $sampled,
        );
    }

    // -----------------------------------------------------------------

    /**
     * The file, described for a language model.
     *
     * Order matters. The shape comes first so the model knows what it is
     * looking at, the profile second because that is where exact answers live,
     * and the sample last — a model that reads the rows first tends to answer
     * from them and never reach the statistics.
     *
     * The sentence about the sample is the load-bearing one. Without it, a
     * question about a total gets an answer derived from three hundred rows and
     * stated as though it covered forty thousand.
     *
     * @param  array<string, mixed>  $analysis
     * @param  list<list<string>>  $sample
     */
    private function describe(array $analysis, array $sample, bool $sampled): string
    {
        /** @var list<string> $headers */
        $headers = $analysis['headers'];
        $rows = (int) $analysis['rows_scanned'];

        $lines = [];

        $lines[] = 'SPREADSHEET DATA';
        $lines[] = sprintf(
            '%s rows and %d columns%s.',
            number_format($rows),
            count($headers),
            $analysis['has_headers'] ? '' : ' (the file has no header row, so columns are numbered)'
        );

        if ($analysis['truncated_columns']) {
            $lines[] = 'Only the first '.count($headers).' columns were read; the file has more.';
        }

        $lines[] = '';
        $lines[] = 'COLUMN PROFILE — computed over all '.number_format($rows).' rows. '
            .'These figures are exact; use them for any question about totals, averages or ranges.';

        foreach ($analysis['columns'] as $column) {
            $lines[] = '- '.$this->describeColumn($column);
        }

        if ($analysis['outliers'] !== []) {
            $lines[] = '';
            $lines[] = 'UNUSUAL VALUES — more than three standard deviations from their column mean:';

            foreach ($analysis['outliers'] as $outlier) {
                $lines[] = sprintf(
                    '- %s: %s in row %d (%s deviations from the mean)',
                    (string) $outlier['column'],
                    (string) $outlier['value'],
                    (int) $outlier['row'],
                    (string) $outlier['deviations'],
                );
            }
        }

        $lines[] = '';

        $lines[] = $sampled
            ? 'SAMPLE ROWS — the first '.number_format(count($sample)).' of '.number_format($rows)
                .' rows. This is a SAMPLE, not the whole file. Do not state a total, a maximum or a '
                .'count derived from these rows: use the column profile above for that, and say '
                .'plainly when a question needs rows that are not shown.'
            : 'ALL ROWS — every row in the file is below.';

        $lines[] = '';
        $lines[] = $this->table($headers, $sample);

        return implode("\n", $lines);
    }

    /**
     * One column, as a line of prose.
     *
     * @param  array<string, mixed>  $column
     */
    private function describeColumn(array $column): string
    {
        $name = (string) ($column['name'] ?? '');
        $type = (string) ($column['type'] ?? 'text');

        $parts = [$name.' ('.$type.')'];

        if ($type === 'number') {
            $parts[] = sprintf(
                'count %s, sum %s, mean %s, median %s, min %s, max %s',
                number_format((int) ($column['count'] ?? 0)),
                (string) ($column['sum'] ?? ''),
                (string) ($column['mean'] ?? ''),
                (string) ($column['median'] ?? ''),
                (string) ($column['min'] ?? ''),
                (string) ($column['max'] ?? ''),
            );
        } else {
            $parts[] = number_format((int) ($column['distinct'] ?? 0)).' distinct values';

            $top = (array) ($column['top_values'] ?? []);

            if ($top !== []) {
                $parts[] = 'most frequent: '.implode(', ', array_map(
                    static fn (array $entry): string => (string) $entry['value'].' ×'.(int) $entry['count'],
                    array_slice($top, 0, (int) config('ai.attachments.csv.sample_values', 5))
                ));
            }
        }

        if ((int) ($column['blank'] ?? 0) > 0) {
            $parts[] = number_format((int) $column['blank']).' blank';
        }

        return implode('; ', $parts);
    }

    /**
     * The sample as a pipe table.
     *
     * Markdown rather than re-serialised CSV: it is the format the model is
     * most reliably able to read column-wise, and it is also the format the
     * model is asked to *answer* in, so the shape of the question matches the
     * shape of the answer.
     *
     * Pipes inside a value are escaped, or a single field containing one would
     * silently shift every column after it.
     *
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function table(array $headers, array $rows): string
    {
        $escape = static fn (string $value): string => str_replace(['|', "\n"], ['\\|', ' '], $value);

        $lines = [
            '| '.implode(' | ', array_map($escape, $headers)).' |',
            '| '.implode(' | ', array_fill(0, count($headers), '---')).' |',
        ];

        foreach ($rows as $row) {
            $cells = [];

            foreach (array_keys($headers) as $index) {
                $cells[] = $escape((string) ($row[$index] ?? ''));
            }

            $lines[] = '| '.implode(' | ', $cells).' |';
        }

        return implode("\n", $lines);
    }
}
