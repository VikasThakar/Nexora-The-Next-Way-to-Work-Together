<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AI\Attachments\CsvAnalyser;
use Tests\TestCase;

/**
 * The safe data-processing layer for spreadsheets.
 *
 * This is the class that means the assistant can answer "what is the average
 * resolution time" over forty thousand rows without either sending forty
 * thousand rows to a model or letting a model run code. Everything it computes
 * is computed here, in PHP, over the whole file, before the model is involved —
 * so these tests are about arithmetic being right rather than about prompts.
 */
class CsvAnalyserTest extends TestCase
{
    private CsvAnalyser $analyser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyser = new CsvAnalyser;
    }

    // -----------------------------------------------------------------
    // Parsing
    // -----------------------------------------------------------------

    public function test_it_reads_a_comma_separated_file_with_headers(): void
    {
        $analysis = $this->analyser->analyse("Name,Amount\nAlice,10\nBob,20\n");

        $this->assertSame(',', $analysis['delimiter']);
        $this->assertTrue($analysis['has_headers']);
        $this->assertSame(['Name', 'Amount'], $analysis['headers']);
        $this->assertSame([['Alice', '10'], ['Bob', '20']], $analysis['rows']);
    }

    /**
     * Consistency, not frequency, picks the delimiter.
     *
     * Frequency alone picks the comma out of a semicolon-delimited European
     * export whose text fields are full of commas — which is a very common
     * file and would be read as one column.
     */
    public function test_it_prefers_the_delimiter_that_appears_consistently(): void
    {
        // A numeric column as well, which is realistic — the decimal comma is
        // why these exports use semicolons in the first place — and which is
        // what lets the header row be recognised at all. See
        // test_a_file_of_only_text_has_no_header_row_either.
        $csv = "Name;City;Amount\n"
            ."\"Andersson, Karin\";\"Stockholm, Sweden\";1200\n"
            ."\"Bergström, Nils\";\"Malmö, Sweden\";900\n";

        $analysis = $this->analyser->analyse($csv);

        $this->assertSame(';', $analysis['delimiter']);
        $this->assertSame(['Name', 'City', 'Amount'], $analysis['headers']);

        // The comma inside a quoted name did not become a column boundary.
        $this->assertSame('Andersson, Karin', $analysis['rows'][0][0]);
    }

    public function test_it_reads_tab_separated_data(): void
    {
        $analysis = $this->analyser->analyse("Name\tAmount\nAlice\t10\n");

        $this->assertSame("\t", $analysis['delimiter']);
        $this->assertSame(['Name', 'Amount'], $analysis['headers']);
    }

    public function test_a_quoted_field_may_contain_a_line_break(): void
    {
        $analysis = $this->analyser->analyse(
            "Name,Note,Hours\nAlice,\"first line\nsecond line\",3\nBob,plain,5\n"
        );

        // Two data rows, not three: the quoted newline did not end a record.
        $this->assertSame(2, $analysis['rows_scanned']);
        $this->assertStringContainsString('second line', $analysis['rows'][0][1]);

        // And the row after it is intact, which is the failure mode a broken
        // continuation produces: everything shifts by one column.
        $this->assertSame(['Bob', 'plain', '5'], $analysis['rows'][1]);
    }

    /**
     * A header row is only recognised when it looks like one.
     *
     * Conservative on purpose: getting this wrong in the cautious direction
     * costs a synthetic column name, where getting it wrong the other way
     * silently deletes a row of somebody's data from every average.
     */
    public function test_a_file_of_numbers_has_no_header_row(): void
    {
        $analysis = $this->analyser->analyse("1,2\n3,4\n5,6\n");

        $this->assertFalse($analysis['has_headers']);
        $this->assertSame(['Column 1', 'Column 2'], $analysis['headers']);
        $this->assertSame(3, $analysis['rows_scanned']);
    }

    public function test_a_file_of_only_text_has_no_header_row_either(): void
    {
        // Nothing later in the file is numeric, so the first row is data like
        // the rest.
        $analysis = $this->analyser->analyse("apple,red\nbanana,yellow\n");

        $this->assertFalse($analysis['has_headers']);
        $this->assertSame(2, $analysis['rows_scanned']);
    }

    public function test_a_blank_header_cell_gets_a_number(): void
    {
        // A trailing or middle empty column is very common in exported data,
        // and it must not disqualify the header row it appears in.
        $analysis = $this->analyser->analyse("Name,,Amount\nAlice,x,10\nBob,y,20\n");

        $this->assertTrue($analysis['has_headers']);
        $this->assertSame(['Name', 'Column 2', 'Amount'], $analysis['headers']);
    }

    public function test_an_empty_file_analyses_to_nothing_rather_than_failing(): void
    {
        $analysis = $this->analyser->analyse('');

        $this->assertSame([], $analysis['headers']);
        $this->assertSame([], $analysis['rows']);
        $this->assertSame(0, $analysis['rows_scanned']);
    }

    // -----------------------------------------------------------------
    // The numeric profile
    // -----------------------------------------------------------------

    public function test_it_computes_the_statistics_a_question_actually_asks_for(): void
    {
        $csv = "Item,Cost\n";

        foreach ([10, 20, 30, 40, 100] as $cost) {
            $csv .= "x,{$cost}\n";
        }

        $column = $this->column($this->analyser->analyse($csv), 'Cost');

        $this->assertSame('number', $column['type']);
        $this->assertSame(5, $column['count']);
        $this->assertSame(200, $column['sum']);
        $this->assertSame(40, $column['mean']);
        $this->assertSame(30, $column['median']);
        $this->assertSame(10, $column['min']);
        $this->assertSame(100, $column['max']);
    }

    public function test_the_median_of_an_even_number_of_values_is_the_midpoint(): void
    {
        $column = $this->column(
            $this->analyser->analyse("A,B\nx,10\nx,20\nx,30\nx,40\n"),
            'B'
        );

        $this->assertSame(25, $column['median']);
    }

    /**
     * Formatted numbers are numbers.
     *
     * A column of "$1,240.00" that profiled as text would answer none of the
     * questions anybody has about it, and exported data looks like this.
     */
    public function test_currency_symbols_separators_and_percentages_are_understood(): void
    {
        $csv = "Item,Amount\na,\"$1,240.50\"\nb,€980\nc,15%\n";

        $column = $this->column($this->analyser->analyse($csv), 'Amount');

        $this->assertSame('number', $column['type']);
        $this->assertSame(3, $column['count']);
        $this->assertSame(2235.5, $column['sum']);
    }

    public function test_accountancy_parentheses_are_read_as_negative(): void
    {
        $column = $this->column(
            $this->analyser->analyse("Item,Balance\na,100\nb,(40)\n"),
            'Balance'
        );

        $this->assertSame(60, $column['sum']);
        $this->assertSame(-40, $column['min']);
    }

    /**
     * A mostly-numeric column stays numeric.
     *
     * A column of amounts with a few blanks and an "n/a" is still a column of
     * amounts, and treating it as text would lose the only question anybody
     * was going to ask about it.
     */
    public function test_a_column_with_a_few_gaps_is_still_numeric(): void
    {
        $csv = "Item,Cost\na,10\nb,20\nc,30\nd,40\ne,\nf,50\ng,60\nh,70\ni,80\nj,90\n";

        $column = $this->column($this->analyser->analyse($csv), 'Cost');

        $this->assertSame('number', $column['type']);
        $this->assertSame(1, $column['blank']);
        $this->assertSame(9, $column['count']);
        $this->assertSame(450, $column['sum']);
    }

    public function test_a_mostly_textual_column_is_text(): void
    {
        $csv = "Item,Status\na,open\nb,open\nc,closed\nd,7\ne,open\n";

        $column = $this->column($this->analyser->analyse($csv), 'Status');

        $this->assertSame('text', $column['type']);
        $this->assertSame(3, $column['distinct']);
        $this->assertSame('open', $column['top_values'][0]['value']);
        $this->assertSame(3, $column['top_values'][0]['count']);
    }

    public function test_a_column_of_dates_is_labelled_as_dates(): void
    {
        $csv = "Ticket,Opened,Hours\n"
            ."A,2026-01-04,3\n"
            ."B,2026-02-11,5\n"
            ."C,2026-03-19,8\n";

        $this->assertSame('date', $this->column($this->analyser->analyse($csv), 'Opened')['type']);
        $this->assertSame('number', $this->column($this->analyser->analyse($csv), 'Hours')['type']);
    }

    /**
     * Integers stay integers.
     *
     * A count of 12 reaching the prompt as 12.0 invites a model to describe it
     * as an average.
     */
    public function test_whole_numbers_are_not_printed_as_decimals(): void
    {
        $column = $this->column($this->analyser->analyse("A,B\nx,2\nx,4\n"), 'B');

        $this->assertSame(3, $column['mean']);
        $this->assertIsInt($column['mean']);
    }

    // -----------------------------------------------------------------
    // Outliers
    // -----------------------------------------------------------------

    public function test_it_finds_a_value_far_from_its_column_mean(): void
    {
        $csv = "Item,Cost\n";

        foreach (range(1, 30) as $index) {
            $csv .= "item {$index},100\n";
        }

        $csv .= "outlier,50000\n";

        $analysis = $this->analyser->analyse($csv);

        $this->assertCount(1, $analysis['outliers']);
        $this->assertSame('Cost', $analysis['outliers'][0]['column']);
        $this->assertSame(50000, $analysis['outliers'][0]['value']);
        $this->assertSame(31, $analysis['outliers'][0]['row']);
    }

    /**
     * A column with no spread has no outliers.
     *
     * Every value being identical is a meaningful answer, not an edge case to
     * be guarded against.
     */
    public function test_an_unvarying_column_has_no_outliers(): void
    {
        $csv = "Item,Cost\n";

        foreach (range(1, 10) as $index) {
            $csv .= "item {$index},100\n";
        }

        $this->assertSame([], $this->analyser->analyse($csv)['outliers']);
    }

    public function test_a_text_column_has_no_outliers(): void
    {
        $csv = "Item,Status\n";

        foreach (range(1, 20) as $index) {
            $csv .= "item {$index},open\n";
        }

        $csv .= "item 21,extremely-unusual-status\n";

        // "Unusual" here means numerically distant, which a text column has no
        // notion of. A rare *value* is reported through top_values instead.
        $this->assertSame([], $this->analyser->analyse($csv)['outliers']);
    }

    public function test_the_most_extreme_outliers_are_kept_when_there_are_many(): void
    {
        $csv = "Item,Cost\n";

        foreach (range(1, 200) as $index) {
            $csv .= "item {$index},100\n";
        }

        foreach ([9000, 20000, 8000, 30000, 7000, 40000, 6000, 50000] as $index => $cost) {
            $csv .= "spike {$index},{$cost}\n";
        }

        $outliers = $this->analyser->analyse($csv)['outliers'];

        // Capped, and the ones kept are the interesting ones.
        $this->assertLessThanOrEqual(5, count($outliers));
        $this->assertSame(50000, $outliers[0]['value']);
    }

    // -----------------------------------------------------------------
    // Bounds
    // -----------------------------------------------------------------

    public function test_columns_beyond_the_limit_are_dropped_and_reported(): void
    {
        config(['ai.attachments.csv.max_columns' => 3]);

        $analysis = $this->analyser->analyse("A,B,C,D,E\n1,2,3,4,5\n6,7,8,9,10\n");

        $this->assertTrue($analysis['truncated_columns']);
        $this->assertCount(3, $analysis['headers']);
    }

    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    private function column(array $analysis, string $name): array
    {
        foreach ($analysis['columns'] as $column) {
            if ($column['name'] === $name) {
                return $column;
            }
        }

        $this->fail("No column named {$name} was profiled.");
    }
}
