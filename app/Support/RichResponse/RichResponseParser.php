<?php

declare(strict_types=1);

namespace App\Support\RichResponse;

use App\Models\Board;
use App\Services\ContentRenderer;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Splits a stored answer into prose, tables and charts.
 *
 * The stored artefact is still Markdown text — that has not changed and should
 * not. A message row holds what the model wrote, so a transcript is readable
 * without this class, exportable, searchable, and unaffected by any later
 * change to how tables are drawn. Rendering is a *view* of that text, computed
 * per request, which is the same relationship App\Services\ContentRenderer
 * already has with a ticket description.
 *
 * Two ways a table or chart gets here
 * -----------------------------------
 * A fenced block with a known name and JSON inside:
 *
 *     ```nexora-chart
 *     {"type": "bar", "title": "Tickets by month", "labels": [...], "datasets": [...]}
 *     ```
 *
 * or, for tables only, an ordinary Markdown pipe table. The second matters more
 * than it looks: a model asked for a table writes a pipe table whatever the
 * prompt says, at least some of the time, and promoting those is what makes
 * "render tables as proper UI tables" true in practice rather than only when
 * the model cooperates. The prompt asks for the fenced form because it also
 * carries a title and a caption.
 *
 * Failure is always prose
 * -----------------------
 * A fence whose JSON does not parse, a chart with no numbers, a table with no
 * columns — every one of those falls back to rendering the block as the text
 * the model wrote. An answer must never turn into an error message because its
 * illustration was malformed, and a code fence rendered as a code fence is a
 * perfectly readable degradation.
 *
 * Nothing here produces HTML except by calling ContentRenderer, which is the
 * one sanitising pipeline in the product. See RichBlock.
 */
class RichResponseParser
{
    /**
     * The fence languages this recognises.
     *
     * Namespaced so they cannot collide with a real language: a model writing
     * ```json for a JSON example must not have it silently turned into a table.
     */
    private const TABLE_FENCE = 'nexora-table';

    private const CHART_FENCE = 'nexora-chart';

    private const KPI_FENCE = 'nexora-kpi';

    /** A guard against a pathological answer, not a product limit. */
    private const MAX_BLOCKS = 60;

    public function __construct(private readonly ContentRenderer $renderer) {}

    /**
     * Parse one answer.
     *
     * The viewer and board are passed straight through to ContentRenderer, so
     * prose is still rendered per reader — a ticket reference links only for
     * somebody who may open that ticket, exactly as it does everywhere else.
     *
     * @return list<RichBlock>
     */
    public function parse(?string $content, ?Authenticatable $viewer, ?Board $board = null): array
    {
        $content = (string) $content;

        if (trim($content) === '') {
            return [];
        }

        $blocks = [];
        $index = 0;

        foreach ($this->split($content) as $segment) {
            if (count($blocks) >= self::MAX_BLOCKS) {
                break;
            }

            if ($segment['type'] === 'fence') {
                $structured = $this->fromFence($segment['language'], $segment['body'], $index);

                if ($structured instanceof RichBlock) {
                    $blocks[] = $structured;
                    $index++;

                    continue;
                }

                // Not usable as a table or a chart, so it goes back to being
                // the code fence the model wrote.
                $segment = ['type' => 'text', 'body' => $segment['raw']];
            }

            foreach ($this->prose($segment['body'], $viewer, $board, $index) as $block) {
                $blocks[] = $block;
                $index++;
            }
        }

        return $blocks;
    }

    /**
     * One block of an answer by its position, for the export route.
     *
     * Re-parsed from the stored text rather than read from a cache, which is
     * what makes the export "the exact rendered data": the same text goes
     * through the same parser, so the CSV cannot drift from the table on the
     * screen.
     */
    public function block(?string $content, ?Authenticatable $viewer, int $index, ?Board $board = null): ?RichBlock
    {
        foreach ($this->parse($content, $viewer, $board) as $block) {
            if ($block->index === $index) {
                return $block;
            }
        }

        return null;
    }

    /**
     * Does this answer contain anything worth rendering richly?
     *
     * A cheap pre-check for the transcript, which would otherwise parse every
     * historical turn on every render just to discover that almost all of them
     * are prose.
     */
    public function looksRich(?string $content): bool
    {
        $content = (string) $content;

        foreach ([self::TABLE_FENCE, self::CHART_FENCE, self::KPI_FENCE] as $fence) {
            if (str_contains($content, '```'.$fence)) {
                return true;
            }
        }

        // A pipe table needs a delimiter row, which is the cheapest signature.
        return preg_match('/^\s*\|?[\s:|-]*\|[\s:|-]*$/m', $content) === 1
            && str_contains($content, '|');
    }

    // -----------------------------------------------------------------
    // Splitting
    // -----------------------------------------------------------------

    /**
     * Break the text into fenced and unfenced segments.
     *
     * Written by hand rather than with one regular expression over the whole
     * document, because a fence's body may itself contain anything — including
     * pipe characters that would otherwise be read as a table — and because the
     * raw text of an unusable fence has to be recoverable to fall back to.
     *
     * @return list<array{type: string, body: string, language?: string, raw?: string}>
     */
    private function split(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];

        $segments = [];
        $text = [];
        $fence = null;
        $body = [];
        $language = '';

        foreach ($lines as $line) {
            if ($fence === null) {
                if (preg_match('/^\s*(`{3,}|~{3,})\s*([A-Za-z0-9_-]*)\s*$/', $line, $match) === 1) {
                    if ($text !== []) {
                        $segments[] = ['type' => 'text', 'body' => implode("\n", $text)];
                        $text = [];
                    }

                    $fence = $match[1];
                    $language = strtolower($match[2]);
                    $body = [];

                    continue;
                }

                $text[] = $line;

                continue;
            }

            // A closing fence is the same character, at least as long.
            if (preg_match('/^\s*('.preg_quote($fence[0], '/').'{3,})\s*$/', $line, $match) === 1
                && strlen($match[1]) >= strlen($fence)) {
                $segments[] = [
                    'type' => 'fence',
                    'language' => $language,
                    'body' => implode("\n", $body),
                    'raw' => $fence.$language."\n".implode("\n", $body)."\n".$fence,
                ];

                $fence = null;
                $body = [];
                $language = '';

                continue;
            }

            $body[] = $line;
        }

        /*
         * An unterminated fence.
         *
         * Common while an answer is still streaming and not rare in a finished
         * one either. It is emitted as text so the content is not lost — the
         * alternative is an answer that silently ends at the fence.
         */
        if ($fence !== null) {
            $text[] = $fence.$language;

            foreach ($body as $line) {
                $text[] = $line;
            }
        }

        if ($text !== []) {
            $segments[] = ['type' => 'text', 'body' => implode("\n", $text)];
        }

        return $segments;
    }

    /**
     * A fenced block, if it is one this understands.
     */
    private function fromFence(string $language, string $body, int $index): ?RichBlock
    {
        if (! in_array($language, [self::TABLE_FENCE, self::CHART_FENCE, self::KPI_FENCE], true)) {
            return null;
        }

        $data = $this->decode($body);

        if ($data === null) {
            return null;
        }

        if ($language === self::CHART_FENCE) {
            $chart = ChartSpec::fromArray($data);

            return $chart instanceof ChartSpec ? RichBlock::chart($chart, $index) : null;
        }

        if ($language === self::KPI_FENCE) {
            $kpi = KpiSpec::fromArray($data);

            return $kpi instanceof KpiSpec ? RichBlock::kpi($kpi, $index) : null;
        }

        $table = TableSpec::fromArray($data);

        return $table instanceof TableSpec ? RichBlock::table($table, $index) : null;
    }

    /**
     * Decode the JSON inside a fence.
     *
     * Depth-limited, because a deeply nested structure is a way to spend the
     * server's stack on decoding something that cannot be a chart anyway.
     *
     * @return array<mixed>|null
     */
    private function decode(string $body): ?array
    {
        $body = trim($body);

        if ($body === '') {
            return null;
        }

        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    // -----------------------------------------------------------------
    // Prose, and the pipe tables inside it
    // -----------------------------------------------------------------

    /**
     * Render a run of ordinary text, promoting any Markdown tables in it.
     *
     * @return list<RichBlock>
     */
    private function prose(string $text, ?Authenticatable $viewer, ?Board $board, int $index): array
    {
        if (trim($text) === '') {
            return [];
        }

        $blocks = [];
        $buffer = [];

        $flush = function () use (&$buffer, &$blocks, $viewer, $board, &$index): void {
            $chunk = implode("\n", $buffer);
            $buffer = [];

            if (trim($chunk) === '') {
                return;
            }

            $html = $this->renderer->render($chunk, $viewer, $board, mentionScope: false);

            if ($html !== '') {
                $blocks[] = RichBlock::prose($html, $index);
                $index++;
            }
        };

        $lines = preg_split('/\n/', $text) ?: [];
        $position = 0;
        $count = count($lines);

        while ($position < $count) {
            $table = $this->tableAt($lines, $position);

            if ($table === null) {
                $buffer[] = $lines[$position];
                $position++;

                continue;
            }

            [$spec, $consumed] = $table;

            $flush();

            $blocks[] = RichBlock::table($spec, $index);
            $index++;

            $position += $consumed;
        }

        $flush();

        return $blocks;
    }

    /**
     * Is there a Markdown pipe table starting at this line?
     *
     * A table is a header row, a delimiter row of dashes and colons, and one or
     * more body rows. The delimiter row is what makes the detection reliable:
     * a single line containing pipes is far more often prose than a table, and
     * requiring the delimiter is how "the options are A | B | C" stays a
     * sentence.
     *
     * @param  list<string>  $lines
     * @return array{0: TableSpec, 1: int}|null the spec and how many lines it used
     */
    private function tableAt(array $lines, int $position): ?array
    {
        $header = $lines[$position] ?? '';
        $delimiter = $lines[$position + 1] ?? '';

        if (! str_contains($header, '|') || trim($header) === '') {
            return null;
        }

        if (preg_match('/^\s*\|?(\s*:?-{1,}:?\s*\|)+(\s*:?-{1,}:?\s*)?\|?\s*$/', $delimiter) !== 1) {
            return null;
        }

        $columns = $this->cells($header);

        if ($columns === []) {
            return null;
        }

        $rows = [];
        $consumed = 2;

        for ($index = $position + 2; $index < count($lines); $index++) {
            $line = $lines[$index];

            if (trim($line) === '' || ! str_contains($line, '|')) {
                break;
            }

            $rows[] = $this->cells($line);
            $consumed++;
        }

        if ($rows === []) {
            return null;
        }

        $spec = TableSpec::fromMarkdown($columns, $rows);

        return $spec instanceof TableSpec ? [$spec, $consumed] : null;
    }

    /**
     * Split a pipe row into cells.
     *
     * Escaped pipes are honoured, which matters because that is exactly what
     * App\Services\AI\Attachments\CsvProcessor writes when a spreadsheet cell
     * contains one — so a table that came back out of a CSV round-trips.
     *
     * @return list<string>
     */
    private function cells(string $line): array
    {
        $line = trim($line);
        $line = preg_replace('/^\|/', '', $line) ?? $line;
        $line = preg_replace('/\|$/', '', $line) ?? $line;

        // Split on unescaped pipes only.
        $parts = preg_split('/(?<!\\\\)\|/', $line) ?: [];

        return array_map(
            static fn (string $cell): string => trim(str_replace('\\|', '|', $cell)),
            $parts
        );
    }
}
