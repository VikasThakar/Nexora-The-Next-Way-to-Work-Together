<?php

declare(strict_types=1);

namespace Tests\Feature\Stats;

use App\Livewire\Stats\Customer as CustomerStats;
use App\Models\User;
use App\Services\Statistics\CustomerStatisticsExport;
use App\Support\StatsPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Downloading the numbers behind the customer summary's charts.
 *
 * The same two properties as the team export, tested the same way: that the
 * file contains the summary on the screen, and that it cannot contain anything
 * else. The second half lives in Tests\Security\StatisticsExportSecurityTest,
 * because "anything else" here means the whole of the team's reporting.
 */
class CustomerStatisticsExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_can_download_their_own_summary(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        // Shared explicitly: a ticket is internal until somebody publishes it,
        // so a fixture that forgot this would pass an assertion of zero.
        $this->ticketOn($board, $team, ['title' => 'Theirs one', 'customer_visible' => true]);
        $this->ticketOn($board, $team, ['title' => 'Theirs two', 'customer_visible' => true]);

        $rows = $this->parse($this->download($customer, ['dataset' => 'status-split']));

        $this->assertSame(['Status', 'Tickets'], array_shift($rows));

        // Two open, nothing closed — the split the ring on their page draws.
        $this->assertSame(2, $this->total($rows));
    }

    public function test_every_advertised_dataset_downloads(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $this->ticketOn($board, $team, ['title' => 'Theirs']);

        foreach (array_keys(CustomerStatisticsExport::KEYS) as $dataset) {
            $csv = $this->download($customer, ['dataset' => $dataset]);

            // A header row at minimum. An empty body is a legitimate answer;
            // an empty file is not.
            $this->assertNotSame('', trim($csv), $dataset.' produced nothing at all.');
        }
    }

    public function test_the_file_starts_with_a_byte_order_mark(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $this->ticketOn($board, $customer, ['title' => 'Theirs']);

        // Excel on Windows reads a CSV as the system codepage without it.
        $this->assertStringStartsWith(
            "\xEF\xBB\xBF",
            $this->download($customer, ['dataset' => 'status-split'])
        );
    }

    public function test_the_download_respects_the_date_range(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $old = $this->ticketOn($board, $team, ['title' => 'Long ago', 'customer_visible' => true]);
        $old->forceFill(['created_at' => now()->subMonths(6)])->save();

        $this->ticketOn($board, $team, ['title' => 'Recent', 'customer_visible' => true]);

        $rows = $this->parse($this->download($customer, [
            'dataset' => 'created-by-week',
            'range' => StatsPeriod::LAST_7_DAYS,
        ]));

        array_shift($rows);

        // Only the recent one was raised inside the window.
        $this->assertSame(1, $this->total($rows));
    }

    public function test_a_staff_member_downloads_the_customer_view_not_the_team_one(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->ticketOn($board, $team, ['title' => 'Internal', 'customer_visible' => false]);
        $this->ticketOn($board, $team, ['title' => 'Shared']);

        // Staff may open the customer screen, so they may download it — and
        // what they get is the customer's table computed against their own
        // access, which is what makes the page useful for checking its shape.
        $rows = $this->parse($this->download($team, ['dataset' => 'status-split']));

        array_shift($rows);

        $this->assertSame(2, $this->total($rows));
    }

    // -----------------------------------------------------------------
    // The page's own wiring
    // -----------------------------------------------------------------

    public function test_every_dataset_is_reachable_from_the_page(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $this->ticketOn($board, $team, ['title' => 'Theirs']);

        $html = Livewire::actingAs($customer)->test(CustomerStats::class)->html();

        // The picture, serialised in the browser from the SVG on the page.
        $this->assertStringContainsString('chartExport', $html);
        $this->assertStringContainsString('downloadPng(', $html);
        $this->assertStringContainsString('data-chart', $html);

        foreach (array_keys(CustomerStatisticsExport::KEYS) as $dataset) {
            $this->assertStringContainsString(
                e(route('stats.customer.export', ['dataset' => $dataset])),
                $html,
                $dataset.' has no CSV button on the customer summary.'
            );
        }
    }

    /**
     * The property behind the `route` prop on x-charts.exportable.
     *
     * Every download button on this page must point at the customer route. One
     * pointing at stats.export would 403 for the customer it was built for, and
     * would quietly work for a member of staff — a page that behaves
     * differently depending on who is reading it is exactly what the two-route
     * split exists to prevent.
     */
    public function test_the_page_never_links_the_team_export_route(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $this->ticketOn($board, $team, ['title' => 'Theirs']);

        $html = Livewire::actingAs($customer)->test(CustomerStats::class)->html();

        $this->assertStringNotContainsString(route('stats.export'), $html);
    }

    public function test_the_download_links_carry_the_filters_on_screen(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer], ['name' => 'Filtered']);
        $this->ticketOn($board, $team, ['title' => 'Theirs']);

        $html = Livewire::actingAs($customer)
            ->test(CustomerStats::class)
            ->set('boardSlug', $board->slug)
            ->set('range', StatsPeriod::LAST_7_DAYS)
            ->html();

        // e() because the URL is in an href and Blade escapes the ampersands.
        $this->assertStringContainsString(
            e(route('stats.customer.export', [
                'dataset' => 'status-split',
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
     * @return array<int, array<int, string>>
     */
    private function parse(string $csv): array
    {
        return collect(explode("\n", trim(ltrim($csv, "\xEF\xBB\xBF"))))
            ->filter(fn (string $line): bool => trim($line) !== '')
            ->map(fn (string $line): array => str_getcsv($line))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<int, string>>  $rows  excluding the header
     */
    private function total(array $rows): int
    {
        return (int) collect($rows)->sum(fn (array $row): int => (int) ($row[1] ?? 0));
    }

    /**
     * @param  array<string, string>  $query
     */
    private function download(User $user, array $query): string
    {
        $response = $this->actingAs($user)->get(route('stats.customer.export', $query));

        $response->assertOk();

        return $response->streamedContent();
    }
}
