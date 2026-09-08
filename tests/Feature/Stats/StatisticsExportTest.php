<?php

declare(strict_types=1);

namespace Tests\Feature\Stats;

use App\Actions\Tickets\MoveTicket;
use App\Livewire\Stats\Team as TeamStats;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Statistics\StatisticsExport;
use App\Support\StatsPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Downloading the numbers behind a chart.
 *
 * Two properties matter and they are tested separately: that the file contains
 * the report on the screen — same filters, same figures — and that it cannot
 * contain anything else. The second half lives in
 * Tests\Security\StatisticsExportSecurityTest.
 */
class StatisticsExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_csv_carries_the_headline_series(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team, ['title' => 'Something']);
        $this->moveTo($ticket, 'Done');

        $rows = $this->parse($this->download($team, ['dataset' => 'created-vs-completed']));

        $this->assertSame(['Week', 'Created', 'Completed'], array_shift($rows));

        // One created and one completed, in whichever week it happened.
        $this->assertSame(1, (int) collect($rows)->sum(fn (array $row): int => (int) $row[1]));
        $this->assertSame(1, (int) collect($rows)->sum(fn (array $row): int => (int) $row[2]));
    }

    public function test_every_advertised_dataset_downloads(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Something']);
        $this->moveTo($ticket, 'In Progress');

        foreach (array_keys(StatisticsExport::KEYS) as $dataset) {
            $csv = $this->download($team, ['dataset' => $dataset]);

            // A header row at minimum. An empty body is a legitimate answer;
            // an empty file is not.
            $this->assertNotSame('', trim($csv), $dataset.' produced nothing at all.');
        }
    }

    public function test_the_file_starts_with_a_byte_order_mark(): void
    {
        $team = $this->teamMember();
        $this->boardWithColumns([$team]);

        // Without it Excel on Windows reads the file as the system codepage
        // and mangles every non-ASCII board or label name.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $this->download($team, ['dataset' => 'by-column']));
    }

    public function test_the_download_respects_the_board_filter(): void
    {
        $team = $this->teamMember();
        $wanted = $this->boardWithColumns([$team], ['name' => 'Wanted']);
        $other = $this->boardWithColumns([$team], ['name' => 'Other']);

        $this->ticketOn($wanted, $team, ['title' => 'On the wanted board']);
        $this->ticketOn($other, $team, ['title' => 'On the other board']);
        $this->ticketOn($other, $team, ['title' => 'Also on the other board']);

        $all = $this->parse($this->download($team, ['dataset' => 'by-column']));
        $filtered = $this->parse($this->download($team, ['dataset' => 'by-column', 'board' => $wanted->slug]));

        // Three tickets across both boards, one on the filtered board.
        $this->assertSame(3, $this->total($all));
        $this->assertSame(1, $this->total($filtered));
    }

    public function test_the_download_respects_the_date_range(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $old = $this->ticketOn($board, $team, ['title' => 'Ancient']);
        $old->forceFill(['created_at' => now()->subYear()])->save();

        $this->ticketOn($board, $team, ['title' => 'Recent']);

        $recent = $this->download($team, [
            'dataset' => 'created-vs-completed',
            'range' => StatsPeriod::LAST_30_DAYS,
        ]);

        // Both tickets exist; only one was created inside the window.
        $this->assertSame(
            1,
            (int) collect(explode("\n", trim($recent)))
                ->slice(1)
                ->sum(fn (string $line): int => (int) (explode(',', $line)[1] ?? 0))
        );
    }

    public function test_the_filename_names_the_board_and_the_period(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['name' => 'NutriLens']);

        $response = $this->actingAs($team)->get(route('stats.export', [
            'dataset' => 'by-column',
            'board' => $board->slug,
        ]));

        $response->assertOk();

        $disposition = (string) $response->headers->get('Content-Disposition');

        // A folder of files called by-column.csv is a folder nobody can read.
        $this->assertStringContainsString('nutrilens', $disposition);
        $this->assertStringContainsString('by-column', $disposition);
        $this->assertStringContainsString(now()->toDateString(), $disposition);
    }

    public function test_the_response_is_a_csv_that_no_proxy_will_cache(): void
    {
        $team = $this->teamMember();
        $this->boardWithColumns([$team]);

        $response = $this->actingAs($team)->get(route('stats.export', ['dataset' => 'by-column']));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));

        // A report is per-viewer by construction.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_an_unmeasured_week_is_blank_rather_than_zero(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->ticketOn($board, $team, ['title' => 'Never closed']);

        $rows = $this->parse($this->download($team, ['dataset' => 'cycle-time-trend']));

        $this->assertSame(['Week', 'Median hours', 'Measured'], array_shift($rows));
        $this->assertNotSame([], $rows);

        // Nothing closed, so every week says so. A zero in the hours column
        // would read as "everything closed instantly", which is the opposite
        // of what an unmeasured week means.
        foreach ($rows as $row) {
            $this->assertSame('', $row[1]);
            $this->assertSame('no', $row[2]);
        }
    }

    // -----------------------------------------------------------------
    // The page's own wiring
    // -----------------------------------------------------------------

    public function test_the_page_offers_all_three_formats_for_the_headline_chart(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->ticketOn($board, $team, ['title' => 'Something']);

        $html = Livewire::actingAs($team)->test(TeamStats::class)->html();

        // The picture, serialised in the browser from the SVG on the page.
        $this->assertStringContainsString('chartExport', $html);
        $this->assertStringContainsString('downloadPng(', $html);
        $this->assertStringContainsString('downloadSvg(', $html);

        // And the numbers, from the server.
        $this->assertStringContainsString(
            route('stats.export', ['dataset' => 'created-vs-completed']),
            $html
        );

        // The chart the exporter looks for, with its labels inside the SVG so
        // an exported file is not a picture of an unlabelled line.
        $this->assertStringContainsString('data-chart', $html);
        $this->assertStringContainsString('<text', $html);
    }

    public function test_the_download_links_carry_the_filters_on_screen(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['name' => 'Filtered']);
        $this->ticketOn($board, $team, ['title' => 'Something']);

        $html = Livewire::actingAs($team)
            ->test(TeamStats::class)
            ->set('boardSlug', $board->slug)
            ->set('range', StatsPeriod::LAST_7_DAYS)
            ->html();

        // e() because the URL is in an href and Blade escapes the ampersands.
        $this->assertStringContainsString(
            e(route('stats.export', [
                'dataset' => 'created-vs-completed',
                'board' => $board->slug,
                'range' => StatsPeriod::LAST_7_DAYS,
            ])),
            $html
        );
    }

    // -----------------------------------------------------------------

    /**
     * The file as rows, with the byte-order mark stripped.
     *
     * Parsed rather than string-matched: PHP quotes a field the moment it
     * contains a space, so "10 Aug" and "Median hours" arrive enclosed and an
     * assertion written against the raw text is really an assertion about
     * fputcsv's quoting rules.
     *
     * @return array<int, array<int, string>>
     */
    private function parse(string $csv): array
    {
        $csv = ltrim($csv, "\xEF\xBB\xBF");

        return collect(explode("\n", trim($csv)))
            ->filter(fn (string $line): bool => trim($line) !== '')
            ->map(fn (string $line): array => str_getcsv($line))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<int, string>>  $rows  including the header
     */
    private function total(array $rows): int
    {
        return (int) collect($rows)->slice(1)->sum(fn (array $row): int => (int) ($row[1] ?? 0));
    }

    /**
     * @param  array<string, string>  $query
     */
    private function download(User $user, array $query): string
    {
        $response = $this->actingAs($user)->get(route('stats.export', $query));

        $response->assertOk();

        return $response->streamedContent();
    }

    private function moveTo(Ticket $ticket, string $column): void
    {
        app(MoveTicket::class)->handle(
            $ticket,
            $this->columnNamed($ticket->board, $column),
            0,
            $ticket->board->members()->first(),
        );
    }
}
